<?php

namespace App\Services\RawatJalan;

use App\Auth\KhanzaUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SOAP rawat jalan, tabel pemeriksaan_ralan (DlgRawatJalan tab 3).
 *
 * Kolom pemeriksaan_ralan berbeda urutan dengan pemeriksaan_ranap (rtl sebelum penilaian, ada
 * lingkar_perut), jadi selalu pakai nama kolom.
 */
class PemeriksaanService
{
    public const KESADARAN = [
        'Compos Mentis', 'Somnolence', 'Sopor', 'Coma', 'Alert', 'Confusion', 'Voice', 'Pain', 'Unresponsive',
        'Apatis', 'Delirium', 'Meninggal',
    ];

    /** Kolom isian (selain no_rawat, tgl, jam, nip) => panjang maksimal kolom. */
    public const KOLOM = [
        'suhu_tubuh' => 5, 'tensi' => 8, 'nadi' => 3, 'respirasi' => 3, 'tinggi' => 5, 'berat' => 5, 'spo2' => 3,
        'gcs' => 10, 'kesadaran' => 20, 'keluhan' => 2000, 'pemeriksaan' => 2000, 'alergi' => 80, 'lingkar_perut' => 5,
        'rtl' => 2000, 'penilaian' => 2000, 'instruksi' => 2000, 'evaluasi' => 2000,
    ];

    /** Minimal salah satu kolom ini terisi (pengecekan simpan() case 3). */
    private const WAJIB_SALAH_SATU = [
        'keluhan', 'pemeriksaan', 'suhu_tubuh', 'tensi', 'alergi', 'tinggi', 'berat', 'respirasi', 'nadi', 'gcs',
        'rtl', 'penilaian', 'instruksi', 'spo2', 'evaluasi',
    ];

    /**
     * Daftar SOAP satu kunjungan, terbaru di atas, dengan nama pegawai (tampilPemeriksaan 12047-12059).
     */
    public function daftar(string $noRawat): Collection
    {
        return DB::table('pemeriksaan_ralan')
            ->leftJoin('pegawai', 'pemeriksaan_ralan.nip', '=', 'pegawai.nik')
            ->where('pemeriksaan_ralan.no_rawat', $noRawat)
            ->orderByDesc('pemeriksaan_ralan.tgl_perawatan')
            ->orderByDesc('pemeriksaan_ralan.jam_rawat')
            ->get(['pemeriksaan_ralan.*', 'pegawai.nama as nm_pegawai', 'pegawai.jbtn']);
    }

    public function cari(string $noRawat, string $tgl, string $jam): ?object
    {
        return DB::table('pemeriksaan_ralan')
            ->where(['no_rawat' => $noRawat, 'tgl_perawatan' => $tgl, 'jam_rawat' => Aturan::jam($jam)])
            ->first();
    }

    /**
     * Non-admin hanya atas nama dirinya (kode user harus ada di pegawai.nik); admin memilih pegawai.
     */
    public function nipPenyimpan(KhanzaUser $user, ?string $nipPilihan): string
    {
        $nip = $user->isAdmin ? (string) $nipPilihan : $user->kode;

        if ($nip === '' || ! DB::table('pegawai')->where('nik', $nip)->exists()) {
            throw new AturanException($user->isAdmin ? 'Dokter/petugas pemeriksa belum dipilih.' : 'User login bukan pegawai/petugas.');
        }

        return $nip;
    }

    /**
     * @param  array<string, mixed>  $data  kolom KOLOM + nip (untuk admin)
     */
    public function simpan(string $noRawat, string $tgl, string $jam, array $data, KhanzaUser $user): void
    {
        $jam = Aturan::jam($jam);

        if (! DB::table('reg_periksa')->where('no_rawat', $noRawat)->exists()) {
            throw new AturanException('Data registrasi tidak ditemukan.');
        }

        Aturan::pastikanTidakTerkunci($noRawat);
        $this->pastikanAdaIsi($data);
        $nip = $this->nipPenyimpan($user, $data['nip'] ?? null);
        Aturan::pastikanSetelahRegistrasi($user, $noRawat, $tgl, $jam);

        if ($this->cari($noRawat, $tgl, $jam)) {
            throw new AturanException('Sudah ada pemeriksaan pada tanggal & jam tersebut. Ubah jam pemeriksaan.');
        }

        try {
            DB::table('pemeriksaan_ralan')->insert(array_merge(
                ['no_rawat' => $noRawat, 'tgl_perawatan' => $tgl, 'jam_rawat' => $jam],
                $this->isian($data),
                ['nip' => $nip],
            ));
        } catch (UniqueConstraintViolationException) {
            throw new AturanException('Sudah ada pemeriksaan pada tanggal & jam tersebut. Ubah jam pemeriksaan.');
        }
    }

    /**
     * Edit berdasarkan PK lama (BtnEdit 6292-6420). Non-admin hanya data miliknya, dalam 48 jam, dan nip tidak berubah.
     *
     * @param  array<string, mixed>  $data
     */
    public function ubah(string $noRawat, string $tglLama, string $jamLama, string $tgl, string $jam, array $data, KhanzaUser $user): void
    {
        $jam = Aturan::jam($jam);
        $lama = $this->cari($noRawat, $tglLama, $jamLama);

        if (! $lama) {
            throw new AturanException('Data pemeriksaan tidak ditemukan.');
        }

        Aturan::pastikanTidakTerkunci($noRawat);
        $this->pastikanPemilik($lama, $user, 'diubah');
        Aturan::pastikanDalam48Jam($user, $lama->tgl_perawatan, $lama->jam_rawat);
        Aturan::pastikanSetelahRegistrasi($user, $noRawat, $tgl, $jam);
        $this->pastikanAdaIsi($data);

        $kolom = array_merge(['tgl_perawatan' => $tgl, 'jam_rawat' => $jam], $this->isian($data));
        if ($user->isAdmin) {
            $kolom['nip'] = $this->nipPenyimpan($user, $data['nip'] ?? null);
        }

        $pindahWaktu = $tgl !== $lama->tgl_perawatan || $jam !== $lama->jam_rawat;
        if ($pindahWaktu && $this->cari($noRawat, $tgl, $jam)) {
            throw new AturanException('Sudah ada pemeriksaan lain pada tanggal & jam tersebut.');
        }

        try {
            DB::table('pemeriksaan_ralan')
                ->where(['no_rawat' => $noRawat, 'tgl_perawatan' => $lama->tgl_perawatan, 'jam_rawat' => $lama->jam_rawat])
                ->update($kolom);
        } catch (UniqueConstraintViolationException) {
            throw new AturanException('Sudah ada pemeriksaan lain pada tanggal & jam tersebut.');
        }
    }

    /**
     * Hapus (BtnHapus case 3, 5618-5646).
     */
    public function hapus(string $noRawat, string $tgl, string $jam, KhanzaUser $user): void
    {
        $row = $this->cari($noRawat, $tgl, $jam);

        if (! $row) {
            throw new AturanException('Data pemeriksaan tidak ditemukan.');
        }

        Aturan::pastikanTidakTerkunci($noRawat);
        Aturan::pastikanDalam48Jam($user, $row->tgl_perawatan, $row->jam_rawat);
        $this->pastikanPemilik($row, $user, 'dihapus');

        DB::table('pemeriksaan_ralan')
            ->where(['no_rawat' => $noRawat, 'tgl_perawatan' => $row->tgl_perawatan, 'jam_rawat' => $row->jam_rawat])
            ->delete();
    }

    public function bolehUbah(object $row, KhanzaUser $user, bool $terkunci): bool
    {
        return ! $terkunci
            && ($user->isAdmin || ($row->nip === $user->kode && ! Aturan::lewat48Jam($row->tgl_perawatan, $row->jam_rawat)));
    }

    /**
     * Nilai kolom isian. Khanza mengirim teks kosong ('') untuk field kosong, termasuk kolom NOT NULL
     * (tensi, spo2, rtl, penilaian, instruksi, evaluasi); kesadaran default 'Compos Mentis'.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function isian(array $data): array
    {
        $hasil = [];
        foreach (array_keys(self::KOLOM) as $kolom) {
            $hasil[$kolom] = trim((string) ($data[$kolom] ?? ''));
        }

        if (! in_array($hasil['kesadaran'], self::KESADARAN, true)) {
            $hasil['kesadaran'] = 'Compos Mentis';
        }

        return $hasil;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function pastikanAdaIsi(array $data): void
    {
        foreach (self::WAJIB_SALAH_SATU as $kolom) {
            if (trim((string) ($data[$kolom] ?? '')) !== '') {
                return;
            }
        }

        throw new AturanException('Isi minimal satu data pemeriksaan (subjek, objek, tanda vital, asesmen, plan, dll).');
    }

    private function pastikanPemilik(object $row, KhanzaUser $user, string $aksi): void
    {
        if (! $user->isAdmin && $row->nip !== $user->kode) {
            throw new AturanException("Hanya bisa {$aksi} oleh dokter/petugas yang bersangkutan.");
        }
    }
}
