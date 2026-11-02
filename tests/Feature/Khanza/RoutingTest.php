<?php

namespace Tests\Feature\Khanza;

use Tests\TestCase;

class RoutingTest extends TestCase
{
    public function test_route_no_rawat_dengan_garis_miring(): void
    {
        $url = route('rawat-jalan.show', ['noRawat' => '2026/10/31/000001']);

        $this->assertStringEndsWith('/rawat-jalan/2026/10/31/000001', $url);
        $this->actingAsAdmin()->get($url)->assertOk();
        $this->actingAsAdmin()->get(route('rawat-inap.show', ['noRawat' => '2026/10/31/000001']))->assertOk();
    }

    public function test_route_masuk_tidak_tertangkap_sebagai_no_rawat(): void
    {
        $this->actingAsAdmin()->get(route('rawat-inap.masuk', ['no_rawat' => '2026/10/31/000001']))->assertOk()->assertSee('Masuk Kamar Inap');
    }
}
