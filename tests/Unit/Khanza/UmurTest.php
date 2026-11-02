<?php

namespace Tests\Unit\Khanza;

use App\Services\Khanza\Umur;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class UmurTest extends TestCase
{
    public function test_umur_tahun(): void
    {
        $acuan = Carbon::parse('2026-11-01');

        $this->assertSame(['umurdaftar' => 34, 'sttsumur' => 'Th'], Umur::untukRegistrasi('1992-08-27', $acuan));
        $this->assertSame('34 Th 2 Bl 5 Hr', Umur::teks('1992-08-27', $acuan));
    }

    public function test_umur_bulan_dan_hari(): void
    {
        $acuan = Carbon::parse('2026-11-01');

        $this->assertSame(['umurdaftar' => 3, 'sttsumur' => 'Bl'], Umur::untukRegistrasi('2026-07-15', $acuan));
        $this->assertSame(['umurdaftar' => 10, 'sttsumur' => 'Hr'], Umur::untukRegistrasi('2026-10-22', $acuan));
    }
}
