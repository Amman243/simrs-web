<?php

namespace App\Services\RawatInap;

use App\Auth\KhanzaUser;
use App\Services\Khanza\Registrasi;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tindakan (rawat_inap_dr/pr/drpr), SOAP (pemeriksaan_ranap) dan DPJP ranap
 * (port DlgRawatInap.java, DlgCariPerawatanRanap.java, DlgDpjp.java).
 */
class PerawatanService
{
    /** Jenis tindakan => [tabel, kolom total tarif di jns_perawatan_inap]. */
    public const JENIS = [
        'dr' => ['tabel' => 'rawat_inap_dr', 'total' => 'total_byrdr', 'label' => 'Dokter'],
        'pr' => ['tabel' => 'rawat_inap_pr', 'total' => 'total_byrpr', 'label' => 'Petugas'],
        'drpr' => ['tabel' => 'rawat_inap_drpr', 'total' => 'total_byrdrpr', 'label' => 'Dokter & Petugas'],
    ];

    public const KESADARAN = [
        'Compos Mentis', 'Somnolence', 'Sopor', 'Coma', 'Alert', 'Confusion', 'Voice', 'Pain',
        'Unresponsive', 'Apatis', 'Delirium', 'Meninggal',
    ];

    /** Batas ubah/hapus untuk non-admin (Sequel.cekTanggal48jam). */
    public const BATAS_DETIK = 172800;

    public function __construct(private KamarInapService $kamarInap) {}

    // ------------------------------------------------------------------ Data umum

    /**
     * Registrasi + pasien + penjab untuk header halaman perawatan.
     */
    public function registrasi(string $noRawat): ?object
    {
        return $this->kamarInap->registrasi($noRawat);
    }

    /**
     * @return Collection<int, object{kd_dokter: string, nm_dokter: string}>
     */
    public function dpjp(string $noRawat): Collection
    {
        return DB::table('dpjp_ranap')
            ->join('dokter', 'dpjp_ranap.kd_dokter', '=', 'dokter.kd_dokter')
            ->where('dpjp_ranap.no_rawat', $noRawat)
            ->orderBy('dokter.nm_dokter')
            ->get(['dpjp_ranap.kd_dokter', 'dokter.nm_dokter']);
    }

    /**
     * Dokter default tindakan (isRawat 9728-9760): DPJP pertama, kalau tidak ada dokter registrasi.
     */
    public function dokterDefault(string $noRawat): ?string
    {
        return DB::table('dpjp_ranap')->where('no_rawat', $noRawat)->value('kd_dokter')
            ?? DB::table('reg_periksa')->where('no_rawat', $noRawat)->value('kd_dokter');
    }

    public function tambahDpjp(string $noRawat, string $kdDokter): void
    {
        $this->pastikanTidakTerkunci($noRawat);

        if (! DB::table('dokter')->where('kd_dokter', $kdDokter)->where('status', '1')->exists()) {
            throw new RawatInapException('Dokter tidak ditemukan.');
        }
        if (DB::table('dpjp_ranap')->where('no_rawat', $noRawat)->where('kd_dokter', $kdDokter)->exists()) {
            throw new RawatInapException('Dokter tersebut sudah menjadi DPJP.');
        }

        DB::table('dpjp_ranap')->insert(['no_rawat' => $noRawat, 'kd_dokter' => $kdDokter]);
    }

    public function hapusDpjp(string $noRawat, string $kdDokter): void
    {
        $this->pastikanTidakTerkunci($noRawat);

        DB::table('dpjp_ranap')->where('no_rawat', $noRawat)->where('kd_dokter', $kdDokter)->delete();
    }

    // ------------------------------------------------------------------ Katalog tindakan

    /**
     * Katalog jns_perawatan_inap sesuai DlgCariPerawatanRanap.tampil() (1341-1414).
     * Filter kd_pj / kd_bangsal / kelas hanya aktif bila set_tarif.*_ranap = 'Yes';
     * bangsal & kelas diambil dari kamar inap terbuka terakhir. Tarif 0 tidak ditampilkan.
     */
    public function katalog(string $jenis, string $noRawat, string $cari = '', ?int $limit = 100): Collection
    {
        $kolomTotal = self::JENIS[$jenis]['total'] ?? throw new RawatInapException('Jenis tindakan tidak valid.');

        $query = $this->queryKatalog($noRawat)
            ->where("jns_perawatan_inap.$kolomTotal", '>', 0)
            ->select([
                'jns_perawatan_inap.kd_jenis_prw', 'jns_perawatan_inap.nm_perawatan', 'kategori_perawatan.nm_kategori',
                'jns_perawatan_inap.kelas', "jns_perawatan_inap.$kolomTotal as tarif",
            ])
            ->orderBy('jns_perawatan_inap.nm_perawatan');

        $cari = trim($cari);
        if ($cari !== '') {
            $like = '%'.$cari.'%';
            $query->where(fn ($q) => $q
                ->where('jns_perawatan_inap.kd_jenis_prw', 'like', $like)
                ->orWhere('jns_perawatan_inap.nm_perawatan', 'like', $like)
                ->orWhere('kategori_perawatan.nm_kategori', 'like', $like));
        }

        return $limit ? $query->limit($limit)->get() : $query->get();
    }

    private function queryKatalog(string $noRawat): Builder
    {
        $setTarif = DB::table('set_tarif')->first();
        $kdPj = (string) DB::table('reg_periksa')->where('no_rawat', $noRawat)->value('kd_pj');
        $aktif = $this->kamarInap->kamarAktif($noRawat);
        $kdBangsal = (string) ($aktif->kd_bangsal ?? '');
        $kelas = (string) ($aktif->kelas ?? '');

        $query = DB::table('jns_perawatan_inap')
            ->join('kategori_perawatan', 'jns_perawatan_inap.kd_kategori', '=', 'kategori_perawatan.kd_kategori')
            ->where('jns_perawatan_inap.status', '1');

        if (($setTarif->cara_bayar_ranap ?? 'No') === 'Yes') {
            $query->whereIn('jns_perawatan_inap.kd_pj', [$kdPj, '-']);
        }
        if (($setTarif->ruang_ranap ?? 'No') === 'Yes') {
            $query->whereIn('jns_perawatan_inap.kd_bangsal', [$kdBangsal, '-']);
        }
        if (($setTarif->kelas_ranap ?? 'No') === 'Yes') {
            $query->whereIn('jns_perawatan_inap.kelas', [$kelas, '-']);
        }

        return $query;
    }

    // ------------------------------------------------------------------ Tindakan

    /**
     * Daftar tindakan yang sudah diberikan untuk satu jenis.
     */
    public function tindakan(string $jenis, string $noRawat): Collection
    {
        $tabel = self::JENIS[$jenis]['tabel'] ?? throw new RawatInapException('Jenis tindakan tidak valid.');

        $query = DB::table($tabel)
            ->leftJoin('jns_perawatan_inap', "$tabel.kd_jenis_prw", '=', 'jns_perawatan_inap.kd_jenis_prw')
            ->where("$tabel.no_rawat", $noRawat)
            ->select(["$tabel.*", 'jns_perawatan_inap.nm_perawatan'])
            ->orderByDesc("$tabel.tgl_perawatan")->orderByDesc("$tabel.jam_rawat");

        if ($jenis !== 'pr') {
            $query->leftJoin('dokter', "$tabel.kd_dokter", '=', 'dokter.kd_dokter')->addSelect('dokter.nm_dokter');
        }
        if ($jenis !== 'dr') {
            $query->leftJoin('petugas', "$tabel.nip", '=', 'petugas.nip')->addSelect('petugas.nama as nm_petugas');
        }

        return $query->get();
    }

    /**
     * Simpan satu/lebih tindakan (DlgCariPerawatanRanap 726-840), tarif diambil dari katalog di server.
     *
     * @param  list<string>  $kdJenisPrw
     */
    public function simpanTindakan(string $jenis, string $noRawat, array $kdJenisPrw, ?string $kdDokter, ?string $nip, CarbonInterface $waktu): int
    {
        $info = self::JENIS[$jenis] ?? throw new RawatInapException('Jenis tindakan tidak valid.');
        $kdJenisPrw = array_values(array_unique(array_filter($kdJenisPrw)));

        if ($kdJenisPrw === []) {
            throw new RawatInapException('Pilih minimal satu tindakan.');
        }
        if ($jenis !== 'pr' && ! DB::table('dokter')->where('kd_dokter', (string) $kdDokter)->exists()) {
            throw new RawatInapException('Dokter tidak ditemukan.');
        }
        if ($jenis !== 'dr' && ! DB::table('petugas')->where('nip', (string) $nip)->exists()) {
            throw new RawatInapException('Petugas tidak ditemukan.');
        }

        return DB::transaction(function () use ($info, $jenis, $noRawat, $kdJenisPrw, $kdDokter, $nip, $waktu) {
            $this->pastikanRegistrasiRanap($noRawat);

            $items = $this->queryKatalog($noRawat)
                ->whereIn('jns_perawatan_inap.kd_jenis_prw', $kdJenisPrw)
                ->where('jns_perawatan_inap.'.$info['total'], '>', 0)
                ->get(['jns_perawatan_inap.*'])
                ->keyBy('kd_jenis_prw');

            foreach ($kdJenisPrw as $kode) {
                $item = $items[$kode] ?? throw new RawatInapException("Tindakan {$kode} tidak tersedia untuk pasien ini.");

                $row = [
                    'no_rawat' => $noRawat,
                    'kd_jenis_prw' => $item->kd_jenis_prw,
                    'tgl_perawatan' => $waktu->toDateString(),
                    'jam_rawat' => $waktu->format('H:i:s'),
                    'material' => (float) $item->material,
                    'bhp' => (float) $item->bhp,
                    'kso' => (float) $item->kso,
                    'menejemen' => (float) $item->menejemen,
                    'biaya_rawat' => (float) $item->{$info['total']},
                ];
                if ($jenis !== 'pr') {
                    $row['kd_dokter'] = $kdDokter;
                    $row['tarif_tindakandr'] = (float) $item->tarif_tindakandr;
                }
                if ($jenis !== 'dr') {
                    $row['nip'] = $nip;
                    $row['tarif_tindakanpr'] = (float) $item->tarif_tindakanpr;
                }

                $pk = array_intersect_key($row, array_flip(['no_rawat', 'kd_jenis_prw', 'kd_dokter', 'nip', 'tgl_perawatan', 'jam_rawat']));
                if (DB::table($info['tabel'])->where($pk)->exists()) {
                    throw new RawatInapException("Tindakan {$item->nm_perawatan} sudah tercatat pada waktu yang sama.");
                }

                DB::table($info['tabel'])->insert($row);
            }

            return count($kdJenisPrw);
        });
    }

    /**
     * Hapus satu tindakan berdasarkan PK lengkap. Non-admin dibatasi 2 x 24 jam.
     *
     * @param  array{kd_jenis_prw: string, tgl_perawatan: string, jam_rawat: string, kd_dokter?: string, nip?: string}  $kunci
     */
    public function hapusTindakan(string $jenis, string $noRawat, array $kunci, KhanzaUser $user): void
    {
        $info = self::JENIS[$jenis] ?? throw new RawatInapException('Jenis tindakan tidak valid.');
        $this->pastikanTidakTerkunci($noRawat, 'Data billing sudah terverifikasi, data tidak boleh dihapus.');

        // PK lengkap wajib ada agar penghapusan tidak pernah mengenai lebih dari satu baris.
        $kolomPk = array_merge(['kd_jenis_prw', 'tgl_perawatan', 'jam_rawat'], $jenis !== 'pr' ? ['kd_dokter'] : [], $jenis !== 'dr' ? ['nip'] : []);
        $where = ['no_rawat' => $noRawat];
        foreach ($kolomPk as $kolom) {
            if (! isset($kunci[$kolom]) || ! is_string($kunci[$kolom])) {
                throw new RawatInapException('Data tindakan tidak ditemukan.');
            }
            $where[$kolom] = $kunci[$kolom];
        }

        $row = DB::table($info['tabel'])->where($where)->first();
        if (! $row) {
            throw new RawatInapException('Data tindakan tidak ditemukan.');
        }

        $this->pastikanDalamBatasWaktu($row->tgl_perawatan, $row->jam_rawat, $user);

        DB::table($info['tabel'])->where($where)->delete();
    }

    // ------------------------------------------------------------------ SOAP

    public function soap(string $noRawat): Collection
    {
        return DB::table('pemeriksaan_ranap')
            ->leftJoin('pegawai', 'pemeriksaan_ranap.nip', '=', 'pegawai.nik')
            ->where('pemeriksaan_ranap.no_rawat', $noRawat)
            ->orderByDesc('pemeriksaan_ranap.tgl_perawatan')->orderByDesc('pemeriksaan_ranap.jam_rawat')
            ->get(['pemeriksaan_ranap.*', 'pegawai.nama as nm_pegawai']);
    }

    public const KOLOM_SOAP = [
        'suhu_tubuh', 'tensi', 'nadi', 'respirasi', 'tinggi', 'berat', 'spo2', 'gcs', 'kesadaran',
        'keluhan', 'pemeriksaan', 'alergi', 'penilaian', 'rtl', 'instruksi', 'evaluasi',
    ];

    /**
     * Insert pemeriksaan_ranap (DlgRawatInap 11913-11935) dengan kolom eksplisit, tanpa lingkar_perut.
     *
     * @param  array<string, string|null>  $data  kolom KOLOM_SOAP
     */
    public function simpanSoap(string $noRawat, array $data, string $nip, CarbonInterface $waktu): void
    {
        $this->pastikanRegistrasiRanap($noRawat);

        if (! DB::table('pegawai')->where('nik', $nip)->exists()) {
            throw new RawatInapException('Petugas pemeriksa tidak terdaftar sebagai pegawai.');
        }

        $pk = ['no_rawat' => $noRawat, 'tgl_perawatan' => $waktu->toDateString(), 'jam_rawat' => $waktu->format('H:i:s')];
        if (DB::table('pemeriksaan_ranap')->where($pk)->exists()) {
            throw new RawatInapException('Sudah ada pemeriksaan pada tanggal & jam yang sama.');
        }

        DB::table('pemeriksaan_ranap')->insert($pk + $this->dataSoap($data) + ['nip' => $nip]);
    }

    /**
     * Ubah isi SOAP (PK tetap). Non-admin hanya miliknya sendiri dan dalam 2 x 24 jam.
     */
    public function ubahSoap(string $noRawat, string $tgl, string $jam, array $data, KhanzaUser $user): void
    {
        $row = $this->soapMilik($noRawat, $tgl, $jam, $user);

        DB::table('pemeriksaan_ranap')
            ->where(['no_rawat' => $noRawat, 'tgl_perawatan' => $row->tgl_perawatan, 'jam_rawat' => $row->jam_rawat])
            ->update($this->dataSoap($data));
    }

    public function hapusSoap(string $noRawat, string $tgl, string $jam, KhanzaUser $user): void
    {
        $row = $this->soapMilik($noRawat, $tgl, $jam, $user);

        DB::table('pemeriksaan_ranap')
            ->where(['no_rawat' => $noRawat, 'tgl_perawatan' => $row->tgl_perawatan, 'jam_rawat' => $row->jam_rawat])
            ->delete();
    }

    private function soapMilik(string $noRawat, string $tgl, string $jam, KhanzaUser $user): object
    {
        $this->pastikanTidakTerkunci($noRawat);

        $row = DB::table('pemeriksaan_ranap')
            ->where(['no_rawat' => $noRawat, 'tgl_perawatan' => $tgl, 'jam_rawat' => $jam])
            ->first();

        if (! $row) {
            throw new RawatInapException('Data pemeriksaan tidak ditemukan.');
        }
        if (! $user->isAdmin && $row->nip !== $user->kode) {
            throw new RawatInapException('Hanya pemeriksa yang bersangkutan yang boleh mengubah/menghapus data ini.');
        }

        $this->pastikanDalamBatasWaktu($row->tgl_perawatan, $row->jam_rawat, $user);

        return $row;
    }

    /**
     * @return array<string, string>
     */
    private function dataSoap(array $data): array
    {
        $hasil = [];
        foreach (self::KOLOM_SOAP as $kolom) {
            $hasil[$kolom] = trim((string) ($data[$kolom] ?? ''));
        }

        if (! in_array($hasil['kesadaran'], self::KESADARAN, true)) {
            $hasil['kesadaran'] = 'Compos Mentis';
        }

        return $hasil;
    }

    // ------------------------------------------------------------------ Aturan

    public function bolehUbah(string $tgl, string $jam, KhanzaUser $user, ?string $pemilik = null): bool
    {
        if ($user->isAdmin) {
            return true;
        }
        if ($pemilik !== null && $pemilik !== $user->kode) {
            return false;
        }

        return Carbon::parse($tgl.' '.$jam)->diffInSeconds(now(), false) <= self::BATAS_DETIK;
    }

    private function pastikanDalamBatasWaktu(string $tgl, string $jam, KhanzaUser $user): void
    {
        if (! $this->bolehUbah($tgl, $jam, $user)) {
            throw new RawatInapException('Maaf, perubahan / penghapusan data tidak boleh lebih dari 2 x 24 jam.');
        }
    }

    private function pastikanTidakTerkunci(string $noRawat, string $pesan = 'Data billing sudah terverifikasi. Silakan hubungi bagian kasir/keuangan.'): void
    {
        if (Registrasi::terkunci($noRawat)) {
            throw new RawatInapException($pesan);
        }
    }

    private function pastikanRegistrasiRanap(string $noRawat): void
    {
        if (! DB::table('reg_periksa')->where('no_rawat', $noRawat)->exists()) {
            throw new RawatInapException('Registrasi tidak ditemukan.');
        }
        if (! DB::table('kamar_inap')->where('no_rawat', $noRawat)->exists()) {
            throw new RawatInapException('Registrasi ini belum masuk kamar inap.');
        }
        $this->pastikanTidakTerkunci($noRawat);
    }
}
