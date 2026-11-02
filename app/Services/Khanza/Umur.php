<?php

namespace App\Services\Khanza;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Perhitungan umur gaya Khanza (DlgIGD.isCekPasien / UpdateUmur).
 */
class Umur
{
    /**
     * @return array{tahun: int, bulan: int, hari: int}
     */
    public static function rinci(CarbonInterface|string $tglLahir, ?CarbonInterface $acuan = null): array
    {
        $lahir = Carbon::parse($tglLahir)->startOfDay();
        $acuan = ($acuan ?? Carbon::today())->copy()->startOfDay();
        $diff = $lahir->diff($acuan);

        return ['tahun' => $diff->y, 'bulan' => $diff->m, 'hari' => $diff->d];
    }

    /**
     * Nilai untuk reg_periksa.umurdaftar + sttsumur: tahun bila > 0, else bulan, else hari.
     *
     * @return array{umurdaftar: int, sttsumur: string}
     */
    public static function untukRegistrasi(CarbonInterface|string $tglLahir, ?CarbonInterface $acuan = null): array
    {
        $u = self::rinci($tglLahir, $acuan);

        return match (true) {
            $u['tahun'] > 0 => ['umurdaftar' => $u['tahun'], 'sttsumur' => 'Th'],
            $u['bulan'] > 0 => ['umurdaftar' => $u['bulan'], 'sttsumur' => 'Bl'],
            default => ['umurdaftar' => $u['hari'], 'sttsumur' => 'Hr'],
        };
    }

    /**
     * Teks untuk pasien.umur, contoh "34 Th 2 Bl 5 Hr".
     */
    public static function teks(CarbonInterface|string $tglLahir, ?CarbonInterface $acuan = null): string
    {
        $u = self::rinci($tglLahir, $acuan);

        return "{$u['tahun']} Th {$u['bulan']} Bl {$u['hari']} Hr";
    }
}
