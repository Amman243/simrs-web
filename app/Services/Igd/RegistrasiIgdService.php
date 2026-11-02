<?php

namespace App\Services\Igd;

use App\Services\Khanza\NomorRawat;
use App\Services\Khanza\Registrasi;
use App\Services\Khanza\Umur;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registrasi IGD gaya Khanza (src/simrskhanza/DlgIGD.java, docs/khanza/02-registrasi-igd.md).
 *
 * Hak akses (igd / edit_registrasi) dicek di komponen Livewire sebelum memanggil service ini.
 */
class RegistrasiIgdService
{
    use CobaUlangDuplikat;

    /** Status yang boleh dipilih dari daftar IGD (DlgIGD 7781-8463). "Dirawat" lewat modul rawat inap. */
    public const STATUS = ['Belum', 'Sudah', 'Batal', 'Dirujuk', 'Meninggal', 'Pulang Paksa'];

    public const PESAN_RANAP = 'Pasien sedang dalam masa perawatan di kamar inap..!!';

    public const PESAN_KAMAR_INAP = 'Maaf, Pasien sudah masuk Kamar Inap. Gunakan billing Ranap..!!!';

    public const PESAN_TERKUNCI = 'Data billing sudah terverifikasi, data tidak boleh diubah. Silahkan hubungi bagian kasir/keuangan..!!';

    public const PESAN_BELUM_LUNAS = 'Pasien masih memiliki tagihan yang belum dibayar. Selesaikan pembayaran di kasir terlebih dahulu..!!';

    public function kdPoli(): string
    {
        return (string) config('khanza.kd_poli_igd', 'IGDK');
    }

    // ------------------------------------------------------------------
    // Daftar registrasi IGD
    // ------------------------------------------------------------------

    /**
     * Query daftar registrasi IGD (DlgIGD 13038: reg_periksa.kd_poli='IGDK').
     */
    public function query(string $tglAwal, string $tglAkhir, string $cari = ''): Builder
    {
        $cari = trim($cari);

        return DB::table('reg_periksa')
            ->join('pasien', 'pasien.no_rkm_medis', '=', 'reg_periksa.no_rkm_medis')
            ->leftJoin('dokter', 'dokter.kd_dokter', '=', 'reg_periksa.kd_dokter')
            ->leftJoin('penjab', 'penjab.kd_pj', '=', 'reg_periksa.kd_pj')
            ->where('reg_periksa.kd_poli', $this->kdPoli())
            ->whereBetween('reg_periksa.tgl_registrasi', [$tglAwal, $tglAkhir])
            ->when($cari !== '', function (Builder $q) use ($cari) {
                $q->where(function (Builder $q) use ($cari) {
                    $q->where('reg_periksa.no_rkm_medis', 'like', "%{$cari}%")
                        ->orWhere('pasien.nm_pasien', 'like', "%{$cari}%")
                        ->orWhere('reg_periksa.no_rawat', 'like', "%{$cari}%");
                });
            })
            ->select([
                'reg_periksa.no_reg', 'reg_periksa.no_rawat', 'reg_periksa.tgl_registrasi', 'reg_periksa.jam_reg',
                'reg_periksa.kd_dokter', 'reg_periksa.no_rkm_medis', 'reg_periksa.p_jawab', 'reg_periksa.almt_pj',
                'reg_periksa.hubunganpj', 'reg_periksa.biaya_reg', 'reg_periksa.stts', 'reg_periksa.stts_daftar',
                'reg_periksa.kd_pj', 'reg_periksa.umurdaftar', 'reg_periksa.sttsumur', 'reg_periksa.status_bayar',
                'pasien.nm_pasien', 'pasien.jk', 'dokter.nm_dokter', 'penjab.png_jawab',
            ])
            ->selectRaw('(select count(*) from kamar_inap where kamar_inap.no_rawat = reg_periksa.no_rawat) as jml_kamar_inap')
            ->selectRaw('(select count(*) from billing where billing.no_rawat = reg_periksa.no_rawat) as jml_billing')
            ->orderByDesc('reg_periksa.tgl_registrasi')
            ->orderByDesc('reg_periksa.jam_reg')
            ->orderByDesc('reg_periksa.no_rawat');
    }

    // ------------------------------------------------------------------
    // Pasien
    // ------------------------------------------------------------------

    /**
     * Cari pasien untuk form registrasi (no RM / nama / NIK / no peserta).
     */
    public function cariPasien(string $kata, int $batas = 10): Collection
    {
        $kata = trim($kata);
        if (mb_strlen($kata) < 2) {
            return collect();
        }

        return DB::table('pasien')
            ->where(function (Builder $q) use ($kata) {
                $q->where('no_rkm_medis', 'like', "{$kata}%")
                    ->orWhere('nm_pasien', 'like', "%{$kata}%")
                    ->orWhere('no_ktp', 'like', "{$kata}%")
                    ->orWhere('no_peserta', 'like', "{$kata}%");
            })
            ->orderBy('nm_pasien')
            ->limit($batas)
            ->get(['no_rkm_medis', 'nm_pasien', 'jk', 'tgl_lahir', 'alamat', 'no_ktp', 'no_peserta']);
    }

    /**
     * Data pasien terpilih + nilai default form (DlgIGD.isCekPasien, 13234-13287).
     *
     * @return array<string, mixed>|null
     */
    public function dataPasien(string $noRkmMedis, ?CarbonInterface $tglRegistrasi = null): ?array
    {
        $p = DB::table('pasien')
            ->leftJoin('kelurahan', 'kelurahan.kd_kel', '=', 'pasien.kd_kel')
            ->leftJoin('kecamatan', 'kecamatan.kd_kec', '=', 'pasien.kd_kec')
            ->leftJoin('kabupaten', 'kabupaten.kd_kab', '=', 'pasien.kd_kab')
            ->leftJoin('penjab', 'penjab.kd_pj', '=', 'pasien.kd_pj')
            ->where('pasien.no_rkm_medis', $noRkmMedis)
            ->first([
                'pasien.no_rkm_medis', 'pasien.nm_pasien', 'pasien.jk', 'pasien.tgl_lahir', 'pasien.tgl_daftar',
                'pasien.alamat', 'pasien.namakeluarga', 'pasien.keluarga', 'pasien.kd_pj', 'pasien.no_peserta',
                'pasien.no_ktp', 'penjab.png_jawab', 'kelurahan.nm_kel', 'kecamatan.nm_kec', 'kabupaten.nm_kab',
            ]);

        if (! $p) {
            return null;
        }

        $tgl = ($tglRegistrasi ?? Carbon::today())->toDateString();
        $umur = $this->umurRegistrasi($p->tgl_lahir);

        return [
            'no_rkm_medis' => $p->no_rkm_medis,
            'nm_pasien' => $p->nm_pasien,
            'jk' => $p->jk,
            'tgl_lahir' => $p->tgl_lahir,
            'no_ktp' => $p->no_ktp,
            'no_peserta' => $p->no_peserta,
            'umur' => $umur['umurdaftar'].' '.$umur['sttsumur'],
            'p_jawab' => (string) $p->namakeluarga,
            'hubunganpj' => (string) $p->keluarga,
            // concat(pasien.alamat,', ',kelurahan.nm_kel,', ',kecamatan.nm_kec,', ',kabupaten.nm_kab)
            'almt_pj' => implode(', ', [(string) $p->alamat, (string) $p->nm_kel, (string) $p->nm_kec, (string) $p->nm_kab]),
            'kd_pj' => (string) $p->kd_pj,
            'png_jawab' => $p->png_jawab,
            'stts_daftar' => $p->tgl_daftar === $tgl ? 'Baru' : 'Lama',
            'tagihan_belum_lunas' => $this->wajibClosingKasir() ? $this->jumlahTagihanBelumLunas($noRkmMedis) : 0,
            'sedang_ranap' => Registrasi::sedangDirawatInap($noRkmMedis),
        ];
    }

    /**
     * set_validasi_registrasi.wajib_closing_kasir (src/fungsi/closingkasir.java).
     */
    public function wajibClosingKasir(): bool
    {
        return DB::table('set_validasi_registrasi')->value('wajib_closing_kasir') === 'Yes';
    }

    public function jumlahTagihanBelumLunas(string $noRkmMedis): int
    {
        return DB::table('reg_periksa')
            ->where('no_rkm_medis', $noRkmMedis)
            ->where('status_bayar', 'Belum Bayar')
            ->where('stts', '<>', 'Batal')
            ->count();
    }

    // ------------------------------------------------------------------
    // Registrasi baru
    // ------------------------------------------------------------------

    /**
     * Daftarkan pasien ke IGD (DlgIGD.BtnSimpanActionPerformed, 5040-5149).
     *
     * @param  array{no_rkm_medis: string, kd_dokter: string, kd_pj: string, tgl_registrasi?: string, jam_reg?: string,
     *     p_jawab?: ?string, hubunganpj?: ?string, almt_pj?: ?string, asal_rujukan?: ?string, alamat_perujuk?: ?string}  $data
     * @return array{no_rawat: string, no_reg: string}
     *
     * @throws IgdException
     */
    public function daftar(array $data): array
    {
        $noRm = (string) $data['no_rkm_medis'];
        $tanggal = Carbon::parse($data['tgl_registrasi'] ?? Carbon::today()->toDateString())->startOfDay();
        $jam = $data['jam_reg'] ?? Carbon::now()->format('H:i:s');
        $kdPoli = $this->kdPoli();

        return $this->cobaUlangSaatDuplikat(function () use ($data, $noRm, $tanggal, $jam, $kdPoli) {
            $pasien = DB::table('pasien')->where('no_rkm_medis', $noRm)->first(['no_rkm_medis', 'tgl_lahir', 'tgl_daftar']);
            if (! $pasien) {
                throw new IgdException('Pasien tidak ditemukan.');
            }
            if (! DB::table('dokter')->where('kd_dokter', $data['kd_dokter'])->exists()) {
                throw new IgdException('Dokter tidak ditemukan.');
            }
            if (! DB::table('penjab')->where('kd_pj', $data['kd_pj'])->exists()) {
                throw new IgdException('Cara bayar tidak ditemukan.');
            }
            if (Registrasi::sedangDirawatInap($noRm)) {
                throw new IgdException(self::PESAN_RANAP);
            }
            if ($this->wajibClosingKasir() && $this->jumlahTagihanBelumLunas($noRm) > 0) {
                throw new IgdException(self::PESAN_BELUM_LUNAS);
            }

            $poli = $this->poliIgd();
            $sttsDaftar = $pasien->tgl_daftar === $tanggal->toDateString() ? 'Baru' : 'Lama';
            $biaya = $sttsDaftar === 'Lama' ? $poli->registrasilama : $poli->registrasi;
            $statusPoli = DB::table('reg_periksa')->where('no_rkm_medis', $noRm)->where('kd_poli', $kdPoli)->exists() ? 'Lama' : 'Baru';
            $umur = $this->umurRegistrasi($pasien->tgl_lahir);
            [$noReg, $noRawat] = $this->nomorBaru($data['kd_dokter'], $kdPoli, $tanggal);

            DB::table('reg_periksa')->insert([
                'no_reg' => $noReg,
                'no_rawat' => $noRawat,
                'tgl_registrasi' => $tanggal->toDateString(),
                'jam_reg' => $jam,
                'kd_dokter' => $data['kd_dokter'],
                'no_rkm_medis' => $noRm,
                'kd_poli' => $kdPoli,
                'p_jawab' => $this->potong($data['p_jawab'] ?? '', 100),
                'almt_pj' => $this->potong($data['almt_pj'] ?? '', 200),
                'hubunganpj' => $this->potong($data['hubunganpj'] ?? '', 20),
                'biaya_reg' => (float) $biaya,
                'stts' => 'Belum',
                'stts_daftar' => $sttsDaftar,
                'status_lanjut' => 'Ralan',
                'kd_pj' => $data['kd_pj'],
                'umurdaftar' => $umur['umurdaftar'],
                'sttsumur' => $umur['sttsumur'],
                'status_bayar' => 'Belum Bayar',
                'status_poli' => $statusPoli,
            ]);

            // UpdateUmur() (DlgIGD 13652)
            if ($pasien->tgl_lahir && $pasien->tgl_lahir !== '0000-00-00') {
                DB::table('pasien')->where('no_rkm_medis', $noRm)->update(['umur' => Umur::teks($pasien->tgl_lahir)]);
            }

            $asal = trim((string) ($data['asal_rujukan'] ?? ''));
            if ($asal !== '') {
                $this->simpanRujukMasuk($noRawat, $tanggal, $asal, (string) ($data['alamat_perujuk'] ?? ''));
            }

            return ['no_rawat' => $noRawat, 'no_reg' => $noReg];
        }, 'Gagal menyimpan registrasi: nomor rawat bentrok dengan registrasi lain. Silakan coba lagi.');
    }

    /**
     * No. registrasi + no. rawat baru. Dipisah agar bisa diganti di tes (simulasi bentrok nomor).
     *
     * @return array{0: string, 1: string}
     */
    protected function nomorBaru(string $kdDokter, string $kdPoli, CarbonInterface $tanggal): array
    {
        return [NomorRawat::noReg($kdDokter, $kdPoli, $tanggal), NomorRawat::noRawat($tanggal)];
    }

    /**
     * Baris poliklinik IGD. Kalau tidak ada, dibuat seperti Khanza (DlgIGD 5069-5071):
     * insert into poliklinik values('IGDK','Unit IGD','0','0','1').
     */
    public function poliIgd(): object
    {
        $poli = DB::table('poliklinik')->where('kd_poli', $this->kdPoli())->first(['kd_poli', 'nm_poli', 'registrasi', 'registrasilama']);

        if (! $poli) {
            DB::table('poliklinik')->insertOrIgnore([
                'kd_poli' => $this->kdPoli(), 'nm_poli' => 'Unit IGD', 'registrasi' => 0, 'registrasilama' => 0, 'status' => '1',
            ]);
            $poli = (object) ['kd_poli' => $this->kdPoli(), 'nm_poli' => 'Unit IGD', 'registrasi' => 0, 'registrasilama' => 0];
        }

        return $poli;
    }

    /**
     * rujuk_masuk saat Asal Rujukan diisi (DlgIGD 5124-5133).
     * no_balasan = 'BR/yyyy/MM/dd/' + 4 digit dari MAX(RIGHT(rujuk_masuk.no_rawat,4)) pada tanggal tsb (sama persis Khanza).
     * Nilai: (no_rawat, perujuk, alamat, no_rujuk '-', jm_perujuk 0, dokter_perujuk=perujuk, -, '-', '-', no_balasan).
     */
    protected function simpanRujukMasuk(string $noRawat, CarbonInterface $tanggal, string $perujuk, string $alamat): void
    {
        $max = (int) DB::table('reg_periksa')
            ->join('rujuk_masuk', 'rujuk_masuk.no_rawat', '=', 'reg_periksa.no_rawat')
            ->where('reg_periksa.tgl_registrasi', $tanggal->toDateString())
            ->max(DB::raw('CONVERT(RIGHT(rujuk_masuk.no_rawat, 4), SIGNED)'));

        $alamat = trim($alamat);

        DB::table('rujuk_masuk')->insert([
            'no_rawat' => $noRawat,
            'perujuk' => $this->potong($perujuk, 60),
            'alamat' => $this->potong($alamat === '' ? '-' : $alamat, 70),
            'no_rujuk' => '-',
            'jm_perujuk' => 0,
            'dokter_perujuk' => $this->potong($perujuk, 50),
            // Khanza mengisi '-', tetapi kolom ini FK ke penyakit (tidak ada baris '-') → pakai NULL.
            'kd_penyakit' => null,
            'kategori_rujuk' => '-',
            'keterangan' => '-',
            'no_balasan' => 'BR/'.$tanggal->format('Y/m/d').'/'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT),
        ]);
    }

    // ------------------------------------------------------------------
    // Ubah status & edit
    // ------------------------------------------------------------------

    /**
     * Ubah reg_periksa.stts (DlgIGD MnSudah/MnBelum/MnBatal/MnDirujuk/MnMeninggal/MnPulangPaksa).
     * Batal juga mengosongkan biaya_reg.
     *
     * @throws IgdException
     */
    public function ubahStatus(string $noRawat, string $stts): void
    {
        if (! in_array($stts, self::STATUS, true)) {
            throw new IgdException('Status tidak dikenal.');
        }

        DB::transaction(function () use ($noRawat, $stts) {
            $this->registrasiIgd($noRawat);

            if (Registrasi::punyaKamarInap($noRawat)) {
                throw new IgdException(self::PESAN_KAMAR_INAP);
            }
            if (Registrasi::terkunci($noRawat)) {
                throw new IgdException(self::PESAN_TERKUNCI);
            }

            $kolom = ['stts' => $stts];
            if ($stts === 'Batal') {
                $kolom['biaya_reg'] = 0;
            }

            DB::table('reg_periksa')->where('no_rawat', $noRawat)->update($kolom);
        });
    }

    /**
     * Edit registrasi (DlgIGD.BtnEditActionPerformed, 5261-5319): dokter, cara bayar, PJ.
     * No. rawat & no. reg tidak diubah. biaya_reg dihitung ulang dari poliklinik IGD sesuai stts_daftar.
     *
     * @param  array{kd_dokter: string, kd_pj: string, p_jawab?: ?string, hubunganpj?: ?string, almt_pj?: ?string}  $data
     *
     * @throws IgdException
     */
    public function ubah(string $noRawat, array $data): void
    {
        DB::transaction(function () use ($noRawat, $data) {
            $reg = $this->registrasiIgd($noRawat);

            if (Registrasi::terkunci($noRawat)) {
                throw new IgdException(self::PESAN_TERKUNCI);
            }
            if (! DB::table('dokter')->where('kd_dokter', $data['kd_dokter'])->exists()) {
                throw new IgdException('Dokter tidak ditemukan.');
            }
            if (! DB::table('penjab')->where('kd_pj', $data['kd_pj'])->exists()) {
                throw new IgdException('Cara bayar tidak ditemukan.');
            }

            $poli = $this->poliIgd();

            DB::table('reg_periksa')->where('no_rawat', $noRawat)->update([
                'kd_dokter' => $data['kd_dokter'],
                'kd_pj' => $data['kd_pj'],
                'p_jawab' => $this->potong($data['p_jawab'] ?? '', 100),
                'hubunganpj' => $this->potong($data['hubunganpj'] ?? '', 20),
                'almt_pj' => $this->potong($data['almt_pj'] ?? '', 200),
                'biaya_reg' => (float) ($reg->stts_daftar === 'Lama' ? $poli->registrasilama : $poli->registrasi),
            ]);
        });
    }

    /**
     * @throws IgdException
     */
    public function registrasiIgd(string $noRawat): object
    {
        $reg = DB::table('reg_periksa')
            ->where('no_rawat', $noRawat)
            ->where('kd_poli', $this->kdPoli())
            ->first();

        if (! $reg) {
            throw new IgdException('Data registrasi IGD tidak ditemukan.');
        }

        return $reg;
    }

    // ------------------------------------------------------------------

    /**
     * @return array{umurdaftar: int, sttsumur: string}
     */
    protected function umurRegistrasi(?string $tglLahir): array
    {
        if (! $tglLahir || $tglLahir === '0000-00-00') {
            return ['umurdaftar' => 0, 'sttsumur' => 'Th'];
        }

        return Umur::untukRegistrasi($tglLahir);
    }

    protected function potong(?string $nilai, int $panjang): string
    {
        return mb_substr(trim((string) $nilai), 0, $panjang);
    }
}
