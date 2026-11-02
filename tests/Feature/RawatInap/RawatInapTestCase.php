<?php

namespace Tests\Feature\RawatInap;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Basis tes modul rawat inap (DB sik_test, semua perubahan di-rollback).
 */
abstract class RawatInapTestCase extends TestCase
{
    use DatabaseTransactions;

    protected const KAMAR_A = 'K1.01';

    protected const KAMAR_B = 'K3.01';

    protected function setUp(): void
    {
        parent::setUp();

        // Data dev bisa berubah; pastikan kamar uji kosong & pengaturan sesuai asumsi tes.
        DB::table('kamar')->whereIn('kd_kamar', [self::KAMAR_A, self::KAMAR_B])->update(['status' => 'KOSONG', 'statusdata' => '1']);
        DB::table('set_jam_minimal')->update(['lamajam' => 12, 'hariawal' => 'No', 'diagnosaakhir' => 'Yes']);
    }

    /**
     * Pasien yang tidak sedang dirawat inap.
     */
    protected function pasienBebas(int $urutan = 0): string
    {
        return DB::table('pasien')
            ->where('no_rkm_medis', '<>', '-')
            ->whereNotExists(fn ($q) => $q->from('kamar_inap')
                ->join('reg_periksa', 'kamar_inap.no_rawat', '=', 'reg_periksa.no_rawat')
                ->whereColumn('reg_periksa.no_rkm_medis', 'pasien.no_rkm_medis')
                ->where('kamar_inap.stts_pulang', '-'))
            ->orderBy('no_rkm_medis')
            ->skip($urutan)
            ->value('no_rkm_medis');
    }

    protected function buatRegistrasi(string $noRawat, ?string $noRm = null, array $override = []): object
    {
        $template = (array) DB::table('reg_periksa')->first();
        $row = array_merge($template, [
            'no_rawat' => $noRawat,
            'no_reg' => '901',
            'tgl_registrasi' => '2099-01-01',
            'jam_reg' => '08:00:00',
            'kd_dokter' => 'D0000003',
            'no_rkm_medis' => $noRm ?? $this->pasienBebas(),
            'kd_pj' => 'A09',
            'stts' => 'Belum',
            'status_lanjut' => 'Ralan',
        ], $override);

        DB::table('reg_periksa')->insert($row);

        return (object) $row;
    }

    /**
     * Masukkan registrasi ke kamar langsung lewat DB (untuk menyiapkan skenario).
     */
    protected function buatRawatInap(string $noRawat, string $kdKamar, string $tgl = '2099-01-01', string $jam = '10:00:00', array $override = []): void
    {
        DB::table('kamar_inap')->insert(array_merge([
            'no_rawat' => $noRawat,
            'kd_kamar' => $kdKamar,
            'trf_kamar' => (float) DB::table('kamar')->where('kd_kamar', $kdKamar)->value('trf_kamar'),
            'diagnosa_awal' => 'Demam',
            'diagnosa_akhir' => '',
            'tgl_masuk' => $tgl,
            'jam_masuk' => $jam,
            'tgl_keluar' => '0000-00-00',
            'jam_keluar' => '00:00:00',
            'lama' => 0,
            'ttl_biaya' => 0,
            'stts_pulang' => '-',
        ], $override));

        if (($override['stts_pulang'] ?? '-') === '-') {
            DB::table('kamar')->where('kd_kamar', $kdKamar)->update(['status' => 'ISI']);
        }
    }

    protected function kamarInap(string $noRawat)
    {
        return DB::table('kamar_inap')->where('no_rawat', $noRawat)->orderBy('tgl_masuk')->orderBy('jam_masuk')->get();
    }

    protected function statusKamar(string $kdKamar): string
    {
        return DB::table('kamar')->where('kd_kamar', $kdKamar)->value('status');
    }
}
