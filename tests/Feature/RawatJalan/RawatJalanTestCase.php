<?php

namespace Tests\Feature\RawatJalan;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Tests\TestCase;

/**
 * Helper bersama tes modul rawat jalan. Semua data dibuat di dalam transaksi (sik_test).
 */
abstract class RawatJalanTestCase extends TestCase
{
    use DatabaseTransactions;

    protected const DOKTER = 'D0000005';

    protected const DOKTER_LAIN = 'D0000004';

    protected const PETUGAS = '123124';

    private static int $urut = 0;

    /**
     * Registrasi ralan baru (salin baris contoh, ganti kolom kunci).
     *
     * @param  array<string, mixed>  $override
     */
    protected function buatRegistrasi(array $override = []): object
    {
        $tgl = Carbon::parse($override['tgl_registrasi'] ?? today()->subDays(3)->toDateString());
        $noRawat = $override['no_rawat'] ?? $tgl->format('Y/m/d').'/'.str_pad((string) (900000 + ++self::$urut), 6, '0', STR_PAD_LEFT);

        $template = (array) DB::table('reg_periksa')->where('status_lanjut', 'Ralan')->first();
        $row = array_merge($template, [
            'no_rawat' => $noRawat,
            'no_reg' => '9'.str_pad((string) self::$urut, 2, '0', STR_PAD_LEFT),
            'tgl_registrasi' => $tgl->toDateString(),
            'jam_reg' => '00:00:00',
            'kd_dokter' => self::DOKTER,
            'kd_poli' => 'U0009',
            'kd_pj' => 'A09',
            'stts' => 'Belum',
            'status_lanjut' => 'Ralan',
            'status_bayar' => 'Belum Bayar',
            'biaya_reg' => 10000,
        ], $override);

        DB::table('reg_periksa')->insert($row);

        return (object) $row;
    }

    protected function kunciBilling(string $noRawat): void
    {
        DB::table('billing')->insert([
            'noindex' => 1, 'no_rawat' => $noRawat, 'tgl_byr' => today()->toDateString(), 'no' => 'Tes',
            'nm_perawatan' => 'Tes billing', 'pemisah' => ':', 'biaya' => 0, 'jumlah' => 1, 'tambahan' => 0,
            'totalbiaya' => 0, 'status' => '-',
        ]);
    }

    protected function masukKamarInap(string $noRawat): void
    {
        DB::table('kamar_inap')->insert([
            'no_rawat' => $noRawat, 'kd_kamar' => DB::table('kamar')->value('kd_kamar'), 'trf_kamar' => 0,
            'diagnosa_awal' => '-', 'diagnosa_akhir' => '-', 'tgl_masuk' => today()->toDateString(),
            'jam_masuk' => '08:00:00', 'tgl_keluar' => '0000-00-00', 'jam_keluar' => '00:00:00',
            'lama' => 0, 'ttl_biaya' => 0, 'stts_pulang' => '-',
        ]);
    }

    /**
     * Satu tindakan aktif yang tarif jenisnya > 0.
     */
    protected function tindakanKatalog(string $kolomTotal): object
    {
        return DB::table('jns_perawatan')
            ->join('kategori_perawatan', 'jns_perawatan.kd_kategori', '=', 'kategori_perawatan.kd_kategori')
            ->where('jns_perawatan.status', '1')
            ->where('jns_perawatan.'.$kolomTotal, '>', 0)
            ->orderBy('jns_perawatan.kd_jenis_prw')
            ->first(['jns_perawatan.*']);
    }

    protected function assertToast(Testable $component, string $type): Testable
    {
        return $component->assertDispatched('toast', fn ($name, $params) => ($params['type'] ?? null) === $type);
    }
}
