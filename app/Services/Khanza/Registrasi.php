<?php

namespace App\Services\Khanza;

use Illuminate\Support\Facades\DB;

/**
 * Pengecekan registrasi yang dipakai lintas modul (IGD, rawat jalan, rawat inap).
 */
class Registrasi
{
    /**
     * Sama dengan Sequel.cariRegistrasi() (src/fungsi/sekuel.java:1443-1448):
     * registrasi terkunci kalau sudah ada billing atau statusnya Batal.
     * Kalau terkunci, Khanza menolak hampir semua perubahan ("Data billing sudah terverifikasi").
     */
    public static function terkunci(string $noRawat): bool
    {
        return DB::table('billing')->where('no_rawat', $noRawat)->exists()
            || DB::table('reg_periksa')->where('no_rawat', $noRawat)->where('stts', 'Batal')->exists();
    }

    /**
     * Pasien masih punya rawat inap terbuka (kamar_inap.stts_pulang = '-').
     * Dipakai DlgIGD/DlgReg (tolak registrasi) dan DlgKamarInap (tolak masuk kamar).
     */
    public static function sedangDirawatInap(string $noRkmMedis): bool
    {
        return DB::table('kamar_inap')
            ->join('reg_periksa', 'reg_periksa.no_rawat', '=', 'kamar_inap.no_rawat')
            ->where('reg_periksa.no_rkm_medis', $noRkmMedis)
            ->where('kamar_inap.stts_pulang', '-')
            ->exists();
    }

    /**
     * Registrasi sudah punya baris kamar_inap (dipakai rawat jalan untuk menolak ubah status).
     */
    public static function punyaKamarInap(string $noRawat): bool
    {
        return DB::table('kamar_inap')->where('no_rawat', $noRawat)->exists();
    }
}
