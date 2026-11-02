<?php

namespace App\Services\Igd;

use App\Services\Khanza\Umur;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pasien baru gaya Khanza (src/simrskhanza/DlgPasien.java BtnSimpanActionPerformed 3705-3900, autoNomor 10030-10082).
 */
class PasienBaruService
{
    use CobaUlangDuplikat;

    public const JK = ['L' => 'LAKI-LAKI', 'P' => 'PEREMPUAN'];

    public const GOL_DARAH = ['-', 'A', 'B', 'AB', 'O'];

    public const AGAMA = ['ISLAM', 'KRISTEN', 'KATOLIK', 'HINDU', 'BUDHA', 'KONG HU CHU', '-'];

    public const STTS_NIKAH = ['MENIKAH', 'BELUM MENIKAH', 'JANDA', 'DUDHA'];

    public const PENDIDIKAN = ['-', 'TS', 'TK', 'SD', 'SMP', 'SMA', 'SLTA/SEDERAJAT', 'D1', 'D2', 'D3', 'D4', 'S1', 'S2', 'S3'];

    public const KELUARGA = ['DIRI SENDIRI', 'AYAH', 'IBU', 'ISTRI', 'SUAMI', 'SAUDARA', 'ANAK', 'LAIN-LAIN'];

    /** Panjang kolom pasien (varchar) untuk memotong nilai sebelum insert. */
    public const PANJANG = [
        'nm_pasien' => 40, 'no_ktp' => 20, 'tmp_lahir' => 15, 'nm_ibu' => 40, 'alamat' => 200, 'pekerjaan' => 60,
        'agama' => 12, 'no_tlp' => 40, 'namakeluarga' => 50, 'no_peserta' => 25, 'pekerjaanpj' => 35, 'alamatpj' => 100,
        'kelurahanpj' => 60, 'kecamatanpj' => 60, 'kabupatenpj' => 60, 'propinsipj' => 30, 'email' => 50, 'nip' => 30,
        'kelurahan' => 60, 'kecamatan' => 60, 'kabupaten' => 60, 'propinsi' => 30,
    ];

    // ------------------------------------------------------------------
    // No. RM otomatis
    // ------------------------------------------------------------------

    /**
     * @return object{urutan: string, tahun: string, bulan: string, posisi_tahun_bulan: string}
     */
    public function settingNomor(): object
    {
        $s = DB::table('set_urut_no_rkm_medis')->first();

        return (object) [
            'urutan' => $s->urutan ?? 'Straight',
            'tahun' => $s->tahun ?? 'No',
            'bulan' => $s->bulan ?? 'No',
            'posisi_tahun_bulan' => $s->posisi_tahun_bulan ?? 'Depan',
        ];
    }

    /**
     * No. RM berikutnya dari set_no_rkm_medis (DlgPasien.autoNomor).
     * $lompat > 0 dipakai saat percobaan ulang setelah duplikat (lihat simpan()).
     */
    public function nomorBerikutnya(?CarbonInterface $tglDaftar = null, int $lompat = 0): string
    {
        $tgl = $tglDaftar ?? Carbon::today();
        $s = $this->settingNomor();

        $tahun = $s->tahun === 'Yes' ? $tgl->format('y') : '';
        $bulan = $s->bulan === 'Yes' ? $tgl->format('m') : '';
        $belakang = $s->posisi_tahun_bulan === 'Belakang';

        $bagian = ($belakang ? 'LEFT' : 'RIGHT').'(set_no_rkm_medis.no_rkm_medis, 6)';
        $ekspresi = match ($s->urutan) {
            'Terminal' => "CONVERT(CONCAT(SUBSTRING({$bagian},5,2), SUBSTRING({$bagian},3,2), SUBSTRING({$bagian},1,2)), SIGNED)",
            'Middle' => "CONVERT(CONCAT(SUBSTRING({$bagian},3,2), SUBSTRING({$bagian},1,2), SUBSTRING({$bagian},5,2)), SIGNED)",
            default => "CONVERT({$bagian}, SIGNED)",
        };

        $max = (int) DB::table('set_no_rkm_medis')->max(DB::raw($ekspresi));
        $n = str_pad((string) ($max + 1 + $lompat), 6, '0', STR_PAD_LEFT);

        // Valid.autoNomer4 (Terminal) / autoNomer5 (Middle): susun ulang pasangan digit.
        $n = match ($s->urutan) {
            'Terminal' => substr($n, 4, 2).substr($n, 2, 2).substr($n, 0, 2),
            'Middle' => substr($n, 2, 2).substr($n, 0, 2).substr($n, 4, 2),
            default => $n,
        };

        if (! $belakang) {
            return $tahun.$bulan.$n;
        }

        return ($bulan.$tahun) !== '' ? $n.'-'.$bulan.$tahun : $n;
    }

    // ------------------------------------------------------------------
    // Master pilihan
    // ------------------------------------------------------------------

    public function pilihanSuku(): Collection
    {
        return DB::table('suku_bangsa')->orderBy('nama_suku_bangsa')->pluck('nama_suku_bangsa', 'id');
    }

    public function pilihanBahasa(): Collection
    {
        return DB::table('bahasa_pasien')->orderBy('nama_bahasa')->pluck('nama_bahasa', 'id');
    }

    public function pilihanCacat(): Collection
    {
        return DB::table('cacat_fisik')->orderBy('nama_cacat')->pluck('nama_cacat', 'id');
    }

    public function pilihanPerusahaan(): Collection
    {
        return DB::table('perusahaan_pasien')->orderBy('nama_perusahaan')->pluck('nama_perusahaan', 'kode_perusahaan');
    }

    /**
     * Id baris '-' untuk suku_bangsa / bahasa_pasien / cacat_fisik / perusahaan_pasien.
     *
     * @return array{suku_bangsa: int, bahasa_pasien: int, cacat_fisik: int, perusahaan_pasien: string}
     */
    public function defaultMaster(): array
    {
        return [
            'suku_bangsa' => (int) (DB::table('suku_bangsa')->where('nama_suku_bangsa', '-')->value('id') ?? DB::table('suku_bangsa')->min('id')),
            'bahasa_pasien' => (int) (DB::table('bahasa_pasien')->where('nama_bahasa', '-')->value('id') ?? DB::table('bahasa_pasien')->min('id')),
            'cacat_fisik' => (int) (DB::table('cacat_fisik')->where('nama_cacat', '-')->value('id') ?? DB::table('cacat_fisik')->min('id')),
            'perusahaan_pasien' => (string) (DB::table('perusahaan_pasien')->where('kode_perusahaan', '-')->value('kode_perusahaan')
                ?? DB::table('perusahaan_pasien')->where('nama_perusahaan', '-')->value('kode_perusahaan')
                ?? DB::table('perusahaan_pasien')->min('kode_perusahaan')),
        ];
    }

    // ------------------------------------------------------------------
    // Simpan
    // ------------------------------------------------------------------

    /**
     * Simpan pasien baru, kembalikan no_rkm_medis.
     *
     * @param  array<string, mixed>  $data  nama kolom pasien; wilayah sebagai nama: kelurahan, kecamatan, kabupaten, propinsi
     *
     * @throws IgdException
     */
    public function simpan(array $data): string
    {
        $nama = trim((string) ($data['nm_pasien'] ?? ''));
        if ($nama === '') {
            throw new IgdException('Nama pasien wajib diisi.');
        }
        if (! DB::table('penjab')->where('kd_pj', $data['kd_pj'] ?? '')->exists()) {
            throw new IgdException('Cara bayar tidak ditemukan.');
        }

        $tglDaftar = Carbon::today();
        $tglLahir = ! empty($data['tgl_lahir']) ? Carbon::parse($data['tgl_lahir'])->startOfDay() : Carbon::today();
        $default = $this->defaultMaster();

        return $this->cobaUlangSaatDuplikat(function (int $percobaan) use ($data, $nama, $tglDaftar, $tglLahir, $default) {
            $this->pastikanMaster($data, $default);

            // Wilayah: insert ignore + cari kode berdasarkan nama (DlgPasien 3775-3813).
            $kdKel = $this->kodeWilayah('kelurahan', 'kd_kel', 'nm_kel', $data['kelurahan'] ?? '', 60);
            $kdKec = $this->kodeWilayah('kecamatan', 'kd_kec', 'nm_kec', $data['kecamatan'] ?? '', 60);
            $kdKab = $this->kodeWilayah('kabupaten', 'kd_kab', 'nm_kab', $data['kabupaten'] ?? '', 60);
            $kdProp = $this->kodeWilayah('propinsi', 'kd_prop', 'nm_prop', $data['propinsi'] ?? '', 30);

            // Khanza membuat ulang nomor yang sama dari set_no_rkm_medis; di sini nomor dilompati
            // supaya percobaan ulang tidak bentrok lagi bila set_no_rkm_medis tertinggal.
            $noRm = $this->nomorBerikutnya($tglDaftar, $percobaan);

            DB::table('pasien')->insert([
                'no_rkm_medis' => $noRm,
                'nm_pasien' => $this->teks($nama, 'nm_pasien', ''),
                'no_ktp' => $this->teks($data['no_ktp'] ?? '', 'no_ktp'),
                'jk' => in_array($data['jk'] ?? '', ['L', 'P'], true) ? $data['jk'] : 'L',
                'tmp_lahir' => $this->teks($data['tmp_lahir'] ?? '', 'tmp_lahir'),
                'tgl_lahir' => $tglLahir->toDateString(),
                'nm_ibu' => $this->teks($data['nm_ibu'] ?? '', 'nm_ibu'),
                'alamat' => $this->teks(str_replace('ALAMAT', '', (string) ($data['alamat'] ?? '')), 'alamat'),
                'gol_darah' => $this->pilih($data['gol_darah'] ?? '-', self::GOL_DARAH, '-'),
                'pekerjaan' => $this->teks($data['pekerjaan'] ?? '', 'pekerjaan'),
                'stts_nikah' => $this->pilih($data['stts_nikah'] ?? '', self::STTS_NIKAH, 'BELUM MENIKAH'),
                'agama' => $this->teks($this->pilih($data['agama'] ?? '', self::AGAMA, '-'), 'agama'),
                'tgl_daftar' => $tglDaftar->toDateString(),
                'no_tlp' => $this->teks($data['no_tlp'] ?? '', 'no_tlp'),
                'umur' => Umur::teks($tglLahir, $tglDaftar),
                'pnd' => $this->pilih($data['pnd'] ?? '-', self::PENDIDIKAN, '-'),
                'keluarga' => $this->pilih($data['keluarga'] ?? '', self::KELUARGA, 'DIRI SENDIRI'),
                'namakeluarga' => $this->teks($data['namakeluarga'] ?? '', 'namakeluarga'),
                'kd_pj' => $data['kd_pj'],
                'no_peserta' => $this->teks($data['no_peserta'] ?? '', 'no_peserta'),
                'kd_kel' => $kdKel,
                'kd_kec' => $kdKec,
                'kd_kab' => $kdKab,
                'pekerjaanpj' => $this->teks($data['pekerjaanpj'] ?? '', 'pekerjaanpj'),
                'alamatpj' => $this->teks(str_replace('ALAMAT', '', (string) ($data['alamatpj'] ?? '')), 'alamatpj'),
                'kelurahanpj' => $this->teks($data['kelurahanpj'] ?? '', 'kelurahanpj'),
                'kecamatanpj' => $this->teks($data['kecamatanpj'] ?? '', 'kecamatanpj'),
                'kabupatenpj' => $this->teks($data['kabupatenpj'] ?? '', 'kabupatenpj'),
                'perusahaan_pasien' => (string) ($data['perusahaan_pasien'] ?? '') !== '' ? $data['perusahaan_pasien'] : $default['perusahaan_pasien'],
                'suku_bangsa' => (int) (($data['suku_bangsa'] ?? '') !== '' ? $data['suku_bangsa'] : $default['suku_bangsa']),
                'bahasa_pasien' => (int) (($data['bahasa_pasien'] ?? '') !== '' ? $data['bahasa_pasien'] : $default['bahasa_pasien']),
                'cacat_fisik' => (int) (($data['cacat_fisik'] ?? '') !== '' ? $data['cacat_fisik'] : $default['cacat_fisik']),
                'email' => $this->teks($data['email'] ?? '', 'email', ''),
                'nip' => $this->teks($data['nip'] ?? '', 'nip', ''),
                'kd_prop' => $kdProp,
                'propinsipj' => $this->teks($data['propinsipj'] ?? '', 'propinsipj'),
            ]);

            // DlgPasien 3841-3842: set_no_rkm_medis hanya berisi satu baris = nomor terakhir.
            DB::table('set_no_rkm_medis')->delete();
            DB::table('set_no_rkm_medis')->insert(['no_rkm_medis' => $noRm]);

            return $noRm;
        }, 'Gagal membuat No. RM baru (bentrok dengan data lain). Periksa pengaturan set_no_rkm_medis lalu coba lagi.');
    }

    /**
     * Sama dengan "insert ignore into <tabel> values('0', nama)" lalu cari kode berdasarkan nama.
     * Kolom kode auto_increment, jadi cukup isi kolom nama (setara dengan nilai '0').
     */
    public function kodeWilayah(string $tabel, string $kolomKode, string $kolomNama, ?string $nama, int $panjang): int
    {
        $nama = mb_substr(trim((string) $nama), 0, $panjang);
        if ($nama === '') {
            $nama = '-';
        }

        DB::table($tabel)->insertOrIgnore([$kolomNama => $nama]);
        $kode = DB::table($tabel)->where($kolomNama, $nama)->value($kolomKode);

        if ($kode === null) {
            throw new IgdException("Gagal menyimpan data {$tabel} \"{$nama}\".");
        }

        return (int) $kode;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $default
     */
    protected function pastikanMaster(array $data, array $default): void
    {
        $cek = [
            'suku_bangsa' => ['suku_bangsa', 'id', 'Suku/bangsa'],
            'bahasa_pasien' => ['bahasa_pasien', 'id', 'Bahasa'],
            'cacat_fisik' => ['cacat_fisik', 'id', 'Cacat fisik'],
            'perusahaan_pasien' => ['perusahaan_pasien', 'kode_perusahaan', 'Perusahaan/instansi'],
        ];

        foreach ($cek as $field => [$tabel, $kolom, $label]) {
            $nilai = ($data[$field] ?? '') !== '' ? $data[$field] : $default[$field];
            if (! DB::table($tabel)->where($kolom, $nilai)->exists()) {
                throw new IgdException("{$label} tidak ditemukan.");
            }
        }
    }

    protected function teks(mixed $nilai, string $kolom, string $kosong = '-'): string
    {
        $nilai = trim((string) $nilai);
        if ($nilai === '') {
            return $kosong;
        }

        return mb_substr($nilai, 0, self::PANJANG[$kolom] ?? 255);
    }

    /**
     * @param  array<int, string>  $pilihan
     */
    protected function pilih(mixed $nilai, array $pilihan, string $default): string
    {
        return in_array($nilai, $pilihan, true) ? (string) $nilai : $default;
    }
}
