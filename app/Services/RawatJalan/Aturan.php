<?php

namespace App\Services\RawatJalan;

use App\Auth\KhanzaUser;
use App\Services\Khanza\Registrasi;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aturan waktu & kunci yang dipakai DlgRawatJalan / DlgKasirRalan.
 */
class Aturan
{
    /** Batas Sequel.cekTanggal48jam(): 2 x 24 jam, dalam detik. */
    public const BATAS_UBAH_DETIK = 172800;

    /**
     * Sequel.cekTanggal48jam(): data yang dicatat lebih dari 48 jam lalu tidak boleh diubah/dihapus.
     */
    public static function lewat48Jam(string $tgl, string $jam, ?Carbon $sekarang = null): bool
    {
        $mulai = Carbon::parse($tgl.' '.$jam);

        return (($sekarang ?? now())->getTimestamp() - $mulai->getTimestamp()) > self::BATAS_UBAH_DETIK;
    }

    /**
     * Sequel.cekTanggalRegistrasi(): waktu input tidak boleh sebelum tgl_registrasi + jam_reg.
     */
    public static function sebelumRegistrasi(string $noRawat, string $tgl, string $jam): bool
    {
        $reg = DB::table('reg_periksa')->where('no_rawat', $noRawat)->first(['tgl_registrasi', 'jam_reg']);

        if (! $reg) {
            return false;
        }

        return Carbon::parse($tgl.' '.$jam)->lt(Carbon::parse($reg->tgl_registrasi.' '.$reg->jam_reg));
    }

    /**
     * Tolak bila registrasi terkunci (billing sudah ada / status Batal).
     */
    public static function pastikanTidakTerkunci(string $noRawat): void
    {
        if (Registrasi::terkunci($noRawat)) {
            throw new AturanException('Data billing sudah terverifikasi / registrasi batal, data tidak boleh diubah. Silakan hubungi bagian kasir/keuangan.');
        }
    }

    /**
     * Non-admin: tolak bila lewat 48 jam (Khanza tidak membatasi Admin Utama).
     */
    public static function pastikanDalam48Jam(KhanzaUser $user, string $tgl, string $jam): void
    {
        if (! $user->isAdmin && self::lewat48Jam($tgl, $jam)) {
            throw new AturanException('Maaf, perubahan / penghapusan data tidak boleh lebih dari 2 x 24 jam.');
        }
    }

    /**
     * Non-admin: waktu input minimal waktu registrasi.
     */
    public static function pastikanSetelahRegistrasi(KhanzaUser $user, string $noRawat, string $tgl, string $jam): void
    {
        if (! $user->isAdmin && self::sebelumRegistrasi($noRawat, $tgl, $jam)) {
            $reg = DB::table('reg_periksa')->where('no_rawat', $noRawat)->first(['tgl_registrasi', 'jam_reg']);

            throw new AturanException("Maaf, jam input data minimal di jam registrasi ({$reg->tgl_registrasi} {$reg->jam_reg}).");
        }
    }

    /**
     * Normalisasi jam dari input HTML ("HH:MM" atau "HH:MM:SS") ke "HH:MM:SS".
     */
    public static function jam(string $jam): string
    {
        return strlen($jam) === 5 ? $jam.':00' : $jam;
    }
}
