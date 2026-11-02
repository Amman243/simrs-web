<?php

namespace Tests\Feature\RawatJalan;

use App\Services\RawatJalan\WorklistQuery;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class WorklistTest extends RawatJalanTestCase
{
    public function test_tanpa_hak_tindakan_ralan_ditolak(): void
    {
        $this->actingAsKhanza(['igd' => true])->get(route('rawat-jalan.index'))->assertForbidden();
        $this->actingAsKhanza(['igd' => true])->get(route('rawat-jalan.show', ['noRawat' => '2026/10/31/000001']))->assertForbidden();
    }

    public function test_worklist_tampil_untuk_admin(): void
    {
        $reg = $this->buatRegistrasi(['tgl_registrasi' => '2099-01-01']);

        $this->actingAsAdmin()
            ->get(route('rawat-jalan.index', ['tgl1' => '2099-01-01', 'tgl2' => '2099-01-01']))
            ->assertOk()
            ->assertSee('Rawat Jalan')
            ->assertSee($reg->no_rawat);
    }

    public function test_default_tanggal_hari_ini(): void
    {
        $this->actingAsAdmin();

        Livewire::test('pages::rawat-jalan.index')
            ->assertSet('tgl1', today()->toDateString())
            ->assertSet('tgl2', today()->toDateString());
    }

    public function test_filter_tanggal_status_poli_dokter_penjab_dan_cari(): void
    {
        $a = $this->buatRegistrasi(['tgl_registrasi' => '2099-01-01', 'stts' => 'Belum', 'kd_poli' => 'U0009', 'kd_dokter' => self::DOKTER]);
        $b = $this->buatRegistrasi(['tgl_registrasi' => '2099-01-01', 'stts' => 'Sudah', 'kd_poli' => 'IGDK', 'kd_dokter' => self::DOKTER_LAIN, 'kd_pj' => 'BPJ']);
        $c = $this->buatRegistrasi(['tgl_registrasi' => '2099-01-05', 'stts' => 'Belum']);
        $ranap = $this->buatRegistrasi(['tgl_registrasi' => '2099-01-01', 'status_lanjut' => 'Ranap']);

        $this->actingAsAdmin();
        $lw = Livewire::test('pages::rawat-jalan.index')->set('tgl1', '2099-01-01')->set('tgl2', '2099-01-01');

        $lw->assertSee($a->no_rawat)->assertSee($b->no_rawat)->assertDontSee($c->no_rawat)->assertDontSee($ranap->no_rawat);
        $this->assertSame(['total' => 2, 'Belum' => 1, 'Sudah' => 1, 'Batal' => 0], $lw->instance()->ringkasan);

        $lw->set('stts', 'Sudah')->assertSee($b->no_rawat)->assertDontSee($a->no_rawat)->set('stts', '');
        $lw->set('kd_poli', 'IGDK')->assertSee($b->no_rawat)->assertDontSee($a->no_rawat)->set('kd_poli', '');
        $lw->set('kd_dokter', self::DOKTER)->assertSee($a->no_rawat)->assertDontSee($b->no_rawat)->set('kd_dokter', '');
        $lw->set('kd_pj', 'BPJ')->assertSee($b->no_rawat)->assertDontSee($a->no_rawat)->set('kd_pj', '');
        $lw->set('cari', $a->no_rawat)->assertSee($a->no_rawat)->assertDontSee($b->no_rawat);
    }

    public function test_dokter_dikunci_ke_pasiennya_sendiri(): void
    {
        $milik = $this->buatRegistrasi(['tgl_registrasi' => '2099-01-01', 'kd_dokter' => self::DOKTER]);
        $lain = $this->buatRegistrasi(['tgl_registrasi' => '2099-01-01', 'kd_dokter' => self::DOKTER_LAIN]);

        $this->actingAsKhanza(['tindakan_ralan' => true], kdDokter: self::DOKTER);

        Livewire::test('pages::rawat-jalan.index')
            ->assertSet('kd_dokter', self::DOKTER)
            ->set('tgl1', '2099-01-01')->set('tgl2', '2099-01-01')
            ->assertSee($milik->no_rawat)
            ->assertDontSee($lain->no_rawat)
            ->set('kd_dokter', self::DOKTER_LAIN)
            ->assertSet('kd_dokter', self::DOKTER)
            ->assertDontSee($lain->no_rawat)
            ->call('ubahStatus', $lain->no_rawat, 'Sudah')
            ->assertForbidden();

        $this->assertSame('Belum', $this->stts($lain->no_rawat));
    }

    public function test_dokter_dengan_akses_dokter_lain_tidak_dikunci(): void
    {
        $lain = $this->buatRegistrasi(['tgl_registrasi' => '2099-01-01', 'kd_dokter' => self::DOKTER_LAIN]);

        $this->actingAsKhanza(['tindakan_ralan' => true, 'akses_dokter_lain_rawat_jalan' => true], kdDokter: self::DOKTER);
        $this->assertNull(WorklistQuery::dokterTerkunci(auth()->user()));

        Livewire::test('pages::rawat-jalan.index')
            ->assertSet('kd_dokter', '')
            ->set('tgl1', '2099-01-01')->set('tgl2', '2099-01-01')
            ->assertSee($lain->no_rawat);
    }

    public function test_admin_tidak_pernah_dikunci(): void
    {
        $this->actingAsAdmin();
        $this->assertNull(WorklistQuery::dokterTerkunci(auth()->user()));
    }

    private function stts(string $noRawat): string
    {
        return DB::table('reg_periksa')->where('no_rawat', $noRawat)->value('stts');
    }
}
