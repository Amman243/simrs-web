<?php

namespace App\Services\RawatJalan;

use App\Auth\KhanzaUser;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tindakan rawat jalan (DlgRawatJalan tab 0-2):
 *   dr   → rawat_jl_dr   (Penanganan Dokter, total_byrdr)
 *   pr   → rawat_jl_pr   (Penanganan Petugas, total_byrpr)
 *   drpr → rawat_jl_drpr (Dokter & Petugas, total_byrdrpr)
 */
class TindakanService
{
    /**
     * @var array<string, array{tabel: string, total: string, label: string, dokter: bool, petugas: bool}>
     */
    public const JENIS = [
        'dr' => ['tabel' => 'rawat_jl_dr', 'total' => 'total_byrdr', 'label' => 'Dokter', 'dokter' => true, 'petugas' => false],
        'pr' => ['tabel' => 'rawat_jl_pr', 'total' => 'total_byrpr', 'label' => 'Petugas', 'dokter' => false, 'petugas' => true],
        'drpr' => ['tabel' => 'rawat_jl_drpr', 'total' => 'total_byrdrpr', 'label' => 'Dokter & Petugas', 'dokter' => true, 'petugas' => true],
    ];

    /**
     * @return array{tabel: string, total: string, label: string, dokter: bool, petugas: bool}
     */
    public static function jenis(string $jenis): array
    {
        return self::JENIS[$jenis] ?? throw new AturanException('Jenis tindakan tidak dikenal.');
    }

    /**
     * set_tarif.poli_ralan / cara_bayar_ralan (fungsi/tarifralan.java; default 'No' bila tidak ada baris).
     *
     * @return array{poli: bool, cara_bayar: bool}
     */
    public static function setTarif(): array
    {
        $row = DB::table('set_tarif')->first(['poli_ralan', 'cara_bayar_ralan']);

        return [
            'poli' => ($row->poli_ralan ?? 'No') === 'Yes',
            'cara_bayar' => ($row->cara_bayar_ralan ?? 'No') === 'Yes',
        ];
    }

    /**
     * Query dasar katalog, sama dengan tampilTindakanDr/Pr/DrPr (12480, 12609, 12738) untuk keempat
     * kombinasi set_tarif: filter kd_pj hanya bila cara_bayar_ralan='Yes', kd_poli hanya bila poli_ralan='Yes'.
     */
    public function katalogQuery(string $jenis, string $kdPj, string $kdPoli): Builder
    {
        $total = self::jenis($jenis)['total'];
        $tarif = self::setTarif();

        $query = DB::table('jns_perawatan')
            ->join('kategori_perawatan', 'jns_perawatan.kd_kategori', '=', 'kategori_perawatan.kd_kategori')
            ->where('jns_perawatan.'.$total, '>', 0)
            ->where('jns_perawatan.status', '1');

        if ($tarif['cara_bayar']) {
            $query->whereIn('jns_perawatan.kd_pj', [$kdPj, '-']);
        }
        if ($tarif['poli']) {
            $query->whereIn('jns_perawatan.kd_poli', [$kdPoli, '-']);
        }

        return $query;
    }

    /**
     * Pencarian katalog tindakan; kolom `total` = tarif sesuai jenis.
     */
    public function katalog(string $jenis, string $kdPj, string $kdPoli, string $cari = '', int $limit = 50): Collection
    {
        $total = self::jenis($jenis)['total'];
        $query = $this->katalogQuery($jenis, $kdPj, $kdPoli);

        $cari = trim($cari);
        if ($cari !== '') {
            $like = '%'.$cari.'%';
            $query->where(fn (Builder $q) => $q->where('jns_perawatan.kd_jenis_prw', 'like', $like)
                ->orWhere('jns_perawatan.nm_perawatan', 'like', $like)
                ->orWhere('kategori_perawatan.nm_kategori', 'like', $like));
        }

        return $query
            ->orderBy('jns_perawatan.nm_perawatan')
            ->limit($limit)
            ->get([
                'jns_perawatan.kd_jenis_prw', 'jns_perawatan.nm_perawatan', 'kategori_perawatan.nm_kategori',
                DB::raw('jns_perawatan.'.$total.' as total'),
            ]);
    }

    /**
     * Simpan tindakan terpilih dalam satu transaksi (SimpanPenangananDokter/Petugas/DokterPetugas).
     *
     * @param  list<string>  $kodeTindakan  kd_jenis_prw
     * @return int jumlah baris tersimpan
     */
    public function simpan(
        string $jenis,
        string $noRawat,
        array $kodeTindakan,
        ?string $kdDokter,
        ?string $nip,
        string $tgl,
        string $jam,
        KhanzaUser $user,
    ): int {
        $def = self::jenis($jenis);
        $jam = Aturan::jam($jam);
        $kodeTindakan = array_values(array_unique(array_filter($kodeTindakan)));

        if ($kodeTindakan === []) {
            throw new AturanException('Pilih minimal satu tindakan.');
        }

        $reg = DB::table('reg_periksa')->where('no_rawat', $noRawat)->first(['kd_pj', 'kd_poli']);
        if (! $reg) {
            throw new AturanException('Data registrasi tidak ditemukan.');
        }

        Aturan::pastikanTidakTerkunci($noRawat);
        Aturan::pastikanSetelahRegistrasi($user, $noRawat, $tgl, $jam);

        if ($def['dokter'] && (blank($kdDokter) || ! DB::table('dokter')->where('kd_dokter', $kdDokter)->exists())) {
            throw new AturanException('Dokter belum dipilih atau tidak ditemukan.');
        }
        if ($def['petugas'] && (blank($nip) || ! DB::table('petugas')->where('nip', $nip)->exists())) {
            throw new AturanException('Petugas belum dipilih atau tidak ditemukan.');
        }

        $tarif = $this->katalogQuery($jenis, $reg->kd_pj, $reg->kd_poli)
            ->whereIn('jns_perawatan.kd_jenis_prw', $kodeTindakan)
            ->get(['jns_perawatan.*']);

        if ($tarif->count() !== count($kodeTindakan)) {
            throw new AturanException('Ada tindakan yang tidak tersedia untuk jenis/cara bayar/poli kunjungan ini.');
        }

        $baris = $tarif->map(fn (object $t) => $this->baris($jenis, $noRawat, $t, $kdDokter, $nip, $tgl, $jam))->all();

        $this->tolakDuplikat($def['tabel'], $baris, $tarif);

        try {
            DB::transaction(fn () => DB::table($def['tabel'])->insert($baris));
        } catch (UniqueConstraintViolationException) {
            throw new AturanException('Tindakan yang sama sudah tercatat pada tanggal & jam tersebut.');
        }

        return count($baris);
    }

    /**
     * Daftar tindakan yang sudah diberikan (tampilDr/tampilPr/tampilDrPr), terbaru di atas.
     */
    public function daftar(string $jenis, string $noRawat): Collection
    {
        $def = self::jenis($jenis);
        $t = $def['tabel'];

        $query = DB::table($t)
            ->join('jns_perawatan', $t.'.kd_jenis_prw', '=', 'jns_perawatan.kd_jenis_prw')
            ->where($t.'.no_rawat', $noRawat)
            ->select([
                $t.'.no_rawat', $t.'.kd_jenis_prw', 'jns_perawatan.nm_perawatan', $t.'.tgl_perawatan', $t.'.jam_rawat',
                $t.'.biaya_rawat', $t.'.stts_bayar',
            ]);

        if ($def['dokter']) {
            $query->leftJoin('dokter', $t.'.kd_dokter', '=', 'dokter.kd_dokter')->addSelect([$t.'.kd_dokter', 'dokter.nm_dokter']);
        }
        if ($def['petugas']) {
            $query->leftJoin('petugas', $t.'.nip', '=', 'petugas.nip')->addSelect([$t.'.nip', 'petugas.nama as nm_petugas']);
        }

        return $query->orderByDesc($t.'.tgl_perawatan')->orderByDesc($t.'.jam_rawat')->orderBy('jns_perawatan.nm_perawatan')->get();
    }

    /**
     * Apakah baris boleh dihapus user ini (dipakai UI; hapus() tetap mengecek ulang).
     */
    public function bolehHapus(object $row, KhanzaUser $user, bool $terkunci): bool
    {
        return ! $terkunci
            && $row->stts_bayar !== 'Sudah'
            && ($user->isAdmin || ! Aturan::lewat48Jam($row->tgl_perawatan, $row->jam_rawat));
    }

    /**
     * Hapus satu baris berdasarkan PK penuh (BtnHapus 5211+).
     *
     * @param  array{no_rawat: string, kd_jenis_prw: string, kd_dokter?: string, nip?: string, tgl_perawatan: string, jam_rawat: string}  $pk
     */
    public function hapus(string $jenis, array $pk, KhanzaUser $user): void
    {
        $def = self::jenis($jenis);

        $kolomPk = ['no_rawat', 'kd_jenis_prw', 'tgl_perawatan', 'jam_rawat'];
        if ($def['dokter']) {
            $kolomPk[] = 'kd_dokter';
        }
        if ($def['petugas']) {
            $kolomPk[] = 'nip';
        }

        $where = [];
        foreach ($kolomPk as $kolom) {
            if (! isset($pk[$kolom]) || $pk[$kolom] === '') {
                throw new AturanException('Data tindakan tidak lengkap.');
            }
            $where[$kolom] = (string) $pk[$kolom];
        }

        $row = DB::table($def['tabel'])->where($where)->first();
        if (! $row) {
            throw new AturanException('Data tindakan tidak ditemukan.');
        }

        Aturan::pastikanTidakTerkunci($row->no_rawat);

        if ($row->stts_bayar === 'Sudah') {
            throw new AturanException('Tindakan sudah dibayar, tidak boleh dihapus.');
        }

        Aturan::pastikanDalam48Jam($user, $row->tgl_perawatan, $row->jam_rawat);

        DB::table($def['tabel'])->where($where)->delete();
    }

    /**
     * Baris insert dengan kolom eksplisit sesuai urutan Khanza (12920, 13018, 13116).
     *
     * @return array<string, mixed>
     */
    private function baris(string $jenis, string $noRawat, object $t, ?string $kdDokter, ?string $nip, string $tgl, string $jam): array
    {
        $angka = fn ($v) => (float) ($v ?? 0);

        return match ($jenis) {
            'dr' => [
                'no_rawat' => $noRawat,
                'kd_jenis_prw' => $t->kd_jenis_prw,
                'kd_dokter' => $kdDokter,
                'tgl_perawatan' => $tgl,
                'jam_rawat' => $jam,
                'material' => $angka($t->material),
                'bhp' => $angka($t->bhp),
                'tarif_tindakandr' => $angka($t->tarif_tindakandr),
                'kso' => $angka($t->kso),
                'menejemen' => $angka($t->menejemen),
                'biaya_rawat' => $angka($t->total_byrdr),
                'stts_bayar' => 'Belum',
            ],
            'pr' => [
                'no_rawat' => $noRawat,
                'kd_jenis_prw' => $t->kd_jenis_prw,
                'nip' => $nip,
                'tgl_perawatan' => $tgl,
                'jam_rawat' => $jam,
                'material' => $angka($t->material),
                'bhp' => $angka($t->bhp),
                'tarif_tindakanpr' => $angka($t->tarif_tindakanpr),
                'kso' => $angka($t->kso),
                'menejemen' => $angka($t->menejemen),
                'biaya_rawat' => $angka($t->total_byrpr),
                'stts_bayar' => 'Belum',
            ],
            'drpr' => [
                'no_rawat' => $noRawat,
                'kd_jenis_prw' => $t->kd_jenis_prw,
                'kd_dokter' => $kdDokter,
                'nip' => $nip,
                'tgl_perawatan' => $tgl,
                'jam_rawat' => $jam,
                'material' => $angka($t->material),
                'bhp' => $angka($t->bhp),
                'tarif_tindakandr' => $angka($t->tarif_tindakandr),
                'tarif_tindakanpr' => $angka($t->tarif_tindakanpr),
                'kso' => $angka($t->kso),
                'menejemen' => $angka($t->menejemen),
                'biaya_rawat' => $angka($t->total_byrdrpr),
                'stts_bayar' => 'Belum',
            ],
        };
    }

    /**
     * Tolak bila PK (no_rawat, kd_jenis_prw, kd_dokter/nip, tgl, jam) sudah ada.
     *
     * @param  list<array<string, mixed>>  $baris
     */
    private function tolakDuplikat(string $tabel, array $baris, Collection $tarif): void
    {
        $ada = [];
        foreach ($baris as $b) {
            $pk = collect($b)->only(['no_rawat', 'kd_jenis_prw', 'kd_dokter', 'nip', 'tgl_perawatan', 'jam_rawat'])->all();
            if (DB::table($tabel)->where($pk)->exists()) {
                $ada[] = $tarif->firstWhere('kd_jenis_prw', $b['kd_jenis_prw'])->nm_perawatan ?? $b['kd_jenis_prw'];
            }
        }

        if ($ada !== []) {
            throw new AturanException('Sudah tercatat pada tanggal & jam yang sama: '.implode(', ', $ada).'. Ubah jam tindakan.');
        }
    }
}
