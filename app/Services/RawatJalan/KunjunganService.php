<?php

namespace App\Services\RawatJalan;

use Illuminate\Support\Facades\DB;

/**
 * Data satu kunjungan untuk header halaman pemeriksaan (DlgRawatJalan.isRawat 11200-11229).
 */
class KunjunganService
{
    public function detail(string $noRawat): ?object
    {
        return DB::table('reg_periksa')
            ->join('pasien', 'reg_periksa.no_rkm_medis', '=', 'pasien.no_rkm_medis')
            ->leftJoin('dokter', 'reg_periksa.kd_dokter', '=', 'dokter.kd_dokter')
            ->leftJoin('poliklinik', 'reg_periksa.kd_poli', '=', 'poliklinik.kd_poli')
            ->leftJoin('penjab', 'reg_periksa.kd_pj', '=', 'penjab.kd_pj')
            ->where('reg_periksa.no_rawat', $noRawat)
            ->first([
                'reg_periksa.no_rawat', 'reg_periksa.no_reg', 'reg_periksa.tgl_registrasi', 'reg_periksa.jam_reg',
                'reg_periksa.no_rkm_medis', 'pasien.nm_pasien', 'pasien.jk', 'pasien.tgl_lahir', 'pasien.alamat',
                'reg_periksa.kd_dokter', 'dokter.nm_dokter', 'reg_periksa.kd_poli', 'poliklinik.nm_poli',
                'reg_periksa.kd_pj', 'penjab.png_jawab', 'reg_periksa.stts', 'reg_periksa.status_bayar',
                'reg_periksa.status_lanjut', 'reg_periksa.umurdaftar', 'reg_periksa.sttsumur', 'reg_periksa.p_jawab',
            ]);
    }
}
