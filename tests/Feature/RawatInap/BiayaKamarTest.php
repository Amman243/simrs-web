<?php

namespace Tests\Feature\RawatInap;

use App\Services\RawatInap\BiayaKamar;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BiayaKamarTest extends RawatInapTestCase
{
    private function lama(string $masuk, string $keluar, int $lamaJam = 12, bool $hariAwal = false): int
    {
        return (new BiayaKamar)->lama(Carbon::parse($masuk), Carbon::parse($keluar), $lamaJam, $hariAwal);
    }

    public function test_hari_sama_kurang_dari_jam_minimal_dihitung_nol(): void
    {
        $this->assertSame(0, $this->lama('2026-01-01 08:00:00', '2026-01-01 19:59:59'));
        // Tepat 12 jam belum lebih dari lamajam → 0 (syarat ">").
        $this->assertSame(0, $this->lama('2026-01-01 08:00:00', '2026-01-01 20:00:00'));
    }

    public function test_hari_sama_lebih_dari_jam_minimal_dihitung_satu(): void
    {
        $this->assertSame(1, $this->lama('2026-01-01 08:00:00', '2026-01-01 20:00:01'));
        $this->assertSame(1, $this->lama('2026-01-01 00:30:00', '2026-01-01 23:00:00', lamaJam: 6));
    }

    public function test_beda_hari_memakai_selisih_tanggal_kalender(): void
    {
        // Lewat tengah malam = 1 hari walau baru beberapa menit.
        $this->assertSame(1, $this->lama('2026-01-01 23:50:00', '2026-01-02 00:10:00'));
        $this->assertSame(3, $this->lama('2026-01-30 10:00:00', '2026-02-02 09:00:00'));
        $this->assertSame(366, $this->lama('2028-01-01 10:00:00', '2029-01-01 10:00:00'));
    }

    public function test_hari_awal_yes_menambah_satu(): void
    {
        $this->assertSame(1, $this->lama('2026-01-01 08:00:00', '2026-01-01 09:00:00', hariAwal: true));
        $this->assertSame(2, $this->lama('2026-01-01 08:00:00', '2026-01-01 21:00:00', hariAwal: true));
        $this->assertSame(4, $this->lama('2026-01-01 08:00:00', '2026-01-04 07:00:00', hariAwal: true));
    }

    public function test_pengaturan_dibaca_dari_set_jam_minimal(): void
    {
        DB::table('set_jam_minimal')->update(['lamajam' => 2, 'hariawal' => 'Yes']);

        $biaya = new BiayaKamar;
        $this->assertSame(2, $biaya->lama(Carbon::parse('2026-01-01 08:00:00'), Carbon::parse('2026-01-01 10:30:00')));
        $this->assertSame(1, $biaya->lama(Carbon::parse('2026-01-01 08:00:00'), Carbon::parse('2026-01-01 09:30:00')));
    }

    public function test_total_biaya_dibulatkan(): void
    {
        $biaya = new BiayaKamar;

        $this->assertSame(1500000.0, $biaya->total(3, 500000));
        $this->assertSame(0.0, $biaya->total(0, 499999.99999999994));
        $this->assertSame(1000000.0, $biaya->total(2, 499999.99999999994));
        $this->assertSame(334.0, $biaya->total(1, 333.5)); // HALF_EVEN seperti DecimalFormat
        $this->assertSame(334.0, $biaya->total(1, 334.5));
    }

    public function test_tarif_kamar_dari_set_harga_kamar_atau_tarif_standar(): void
    {
        $biaya = new BiayaKamar;
        $standar = (float) DB::table('kamar')->where('kd_kamar', self::KAMAR_A)->value('trf_kamar');

        $this->assertSame($standar, $biaya->tarif(self::KAMAR_A, 'A09'));

        DB::table('set_harga_kamar')->insert(['kd_kamar' => self::KAMAR_A, 'kd_pj' => 'BPJ', 'tarif' => 123000]);

        $this->assertSame(123000.0, $biaya->tarif(self::KAMAR_A, 'BPJ'));
        $this->assertSame($standar, $biaya->tarif(self::KAMAR_A, 'A09'));
    }
}
