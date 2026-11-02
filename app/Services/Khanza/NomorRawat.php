<?php

namespace App\Services\Khanza;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Generator nomor registrasi Khanza (docs/khanza/02-registrasi-igd.md).
 *
 * Khanza tidak memakai lock; insert yang gagal karena PK duplikat dicoba ulang
 * dengan nomor baru (maks 5x). Pemanggil sebaiknya melakukan hal yang sama.
 */
class NomorRawat
{
    /**
     * no_rawat = yyyy/MM/dd/NNNNNN, counter bersama semua poli per tanggal.
     */
    public static function noRawat(CarbonInterface $tanggal): string
    {
        $max = (int) DB::table('reg_periksa')
            ->where('tgl_registrasi', $tanggal->toDateString())
            ->max(DB::raw('CONVERT(RIGHT(no_rawat, 6), SIGNED)'));

        return $tanggal->format('Y/m/d').'/'.str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * no_reg = nomor antrian 3 digit, cakupan sesuai config('khanza.urut_no_reg').
     */
    public static function noReg(string $kdDokter, string $kdPoli, CarbonInterface $tanggal): string
    {
        $query = DB::table('reg_periksa')->where('tgl_registrasi', $tanggal->toDateString());

        match (config('khanza.urut_no_reg')) {
            'poli' => $query->where('kd_poli', $kdPoli),
            'dokter + poli' => $query->where('kd_dokter', $kdDokter)->where('kd_poli', $kdPoli),
            default => $query->where('kd_dokter', $kdDokter),
        };

        $max = (int) $query->max(DB::raw('CONVERT(no_reg, SIGNED)'));

        return str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}
