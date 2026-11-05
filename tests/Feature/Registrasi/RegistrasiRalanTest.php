<?php

namespace Tests\Feature\Registrasi;

use App\Services\Igd\IgdException;
use App\Services\Igd\RegistrasiIgdService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Registrasi rawat jalan ke poliklinik (DlgReg) di halaman Registrasi.
 */
class RegistrasiRalanTest extends TestCase
{
    use DatabaseTransactions;

    private const TGL = '2099-01-04';

    private string $kdDokter;

    private string $kdPoli;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('set_validasi_registrasi')->update(['wajib_closing_kasir' => 'No']);
        $this->kdDokter = DB::table('dokter')->where('status', '1')->where('kd_dokter', '<>', '-')->value('kd_dokter');
        $this->kdPoli = DB::table('poliklinik')->where('status', '1')->whereNotIn('kd_poli', ['IGDK', '-'])->value('kd_poli');
        $this->assertNotNull($this->kdPoli, 'Data tes butuh poliklinik aktif selain IGD.');
        DB::table('poliklinik')->where('kd_poli', $this->kdPoli)->update(['registrasi' => 25000, 'registrasilama' => 12000]);
    }

    public function test_menu_dan_tombol_sesuai_hak(): void
    {
        $this->actingAsKhanza(['registrasi' => true])
            ->get(route('registrasi.index'))
            ->assertOk()
            ->assertSee("bukaForm('ralan')", false)
            ->assertDontSee("bukaForm('igd')", false);

        $this->actingAsKhanza(['registrasi' => true, 'igd' => true])
            ->get(route('registrasi.index'))
            ->assertOk()
            ->assertSee("bukaForm('ralan')", false)
            ->assertSee("bukaForm('igd')", false);

        $this->actingAsKhanza(['tindakan_ralan' => true])->get(route('registrasi.index'))->assertForbidden();
    }

    public function test_registrasi_rawat_jalan_ke_poli_terpilih(): void
    {
        $rm = $this->pasienBebas();
        $this->actingAsKhanza(['registrasi' => true]);

        Livewire::test('pages::registrasi.index')
            ->call('bukaForm', 'ralan')
            ->assertSet('formJenis', 'ralan')
            ->set('tgl_registrasi', self::TGL)
            ->set('jam_reg', '10:00')
            ->call('pilihPasien', $rm)
            ->set('kd_dokter', $this->kdDokter)
            ->call('simpan')
            ->assertHasErrors(['kd_poli'])
            ->set('kd_poli', $this->kdPoli)
            ->call('simpan')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $reg = DB::table('reg_periksa')->where('tgl_registrasi', self::TGL)->where('no_rkm_medis', $rm)->first();
        $this->assertNotNull($reg);
        $this->assertSame($this->kdPoli, $reg->kd_poli);
        $this->assertSame('Ralan', $reg->status_lanjut);
        $this->assertEquals(12000, $reg->biaya_reg); // pasien lama → registrasilama poli
    }

    public function test_user_tanpa_hak_igd_tidak_bisa_registrasi_igd(): void
    {
        $this->actingAsKhanza(['registrasi' => true]);

        Livewire::test('pages::registrasi.index')->call('bukaForm', 'igd')->assertForbidden();
        Livewire::test('pages::registrasi.index')->set('formJenis', 'igd')->call('simpan')->assertForbidden();
    }

    public function test_user_igd_saja_tidak_bisa_registrasi_rawat_jalan(): void
    {
        $this->actingAsKhanza(['igd' => true]);

        Livewire::test('pages::registrasi.index')->call('bukaForm', 'ralan')->assertForbidden();
        Livewire::test('pages::registrasi.index')->call('simpan')->assertForbidden();
    }

    public function test_filter_jenis_daftar(): void
    {
        $service = app(RegistrasiIgdService::class);
        $rm = $this->pasienBebas();
        $ralan = $service->daftar($this->dataDaftar($rm) + ['kd_poli' => $this->kdPoli]);
        $igd = $service->daftar($this->dataDaftar($rm));

        $this->actingAsKhanza(['registrasi' => true, 'igd' => true]);

        Livewire::test('pages::registrasi.index')
            ->set('tglAwal', self::TGL)
            ->set('tglAkhir', self::TGL)
            ->assertSee($ralan['no_rawat'])
            ->assertSee($igd['no_rawat'])
            ->set('jenis', 'ralan')
            ->assertSee($ralan['no_rawat'])
            ->assertDontSee($igd['no_rawat'])
            ->set('jenis', 'igd')
            ->assertSee($igd['no_rawat'])
            ->assertDontSee($ralan['no_rawat']);

        // User yang hanya punya hak IGD selalu melihat IGD saja.
        $this->actingAsKhanza(['igd' => true]);
        Livewire::test('pages::registrasi.index')
            ->set('tglAwal', self::TGL)
            ->set('tglAkhir', self::TGL)
            ->set('jenis', '')
            ->assertSet('jenis', 'igd')
            ->assertDontSee($ralan['no_rawat']);
    }

    public function test_ubah_status_rawat_jalan_butuh_hak_registrasi(): void
    {
        $hasil = app(RegistrasiIgdService::class)->daftar($this->dataDaftar($this->pasienBebas()) + ['kd_poli' => $this->kdPoli]);

        $this->actingAsKhanza(['igd' => true]);
        Livewire::test('pages::registrasi.index')->call('ubahStatus', $hasil['no_rawat'], 'Batal')->assertForbidden();

        $this->actingAsKhanza(['registrasi' => true]);
        Livewire::test('pages::registrasi.index')->call('ubahStatus', $hasil['no_rawat'], 'Sudah')->assertDispatched('toast', type: 'success');
        $this->assertSame('Sudah', DB::table('reg_periksa')->where('no_rawat', $hasil['no_rawat'])->value('stts'));
    }

    public function test_edit_registrasi_rawat_jalan_memakai_tarif_poli_registrasi(): void
    {
        $service = app(RegistrasiIgdService::class);
        $hasil = $service->daftar($this->dataDaftar($this->pasienBebas()) + ['kd_poli' => $this->kdPoli]);
        DB::table('poliklinik')->where('kd_poli', $this->kdPoli)->update(['registrasilama' => 13000]);

        $service->ubah($hasil['no_rawat'], ['kd_dokter' => $this->kdDokter, 'kd_pj' => DB::table('reg_periksa')->where('no_rawat', $hasil['no_rawat'])->value('kd_pj')]);

        $reg = DB::table('reg_periksa')->where('no_rawat', $hasil['no_rawat'])->first();
        $this->assertSame($this->kdPoli, $reg->kd_poli);
        $this->assertEquals(13000, $reg->biaya_reg);
    }

    public function test_poli_tidak_aktif_ditolak(): void
    {
        DB::table('poliklinik')->where('kd_poli', $this->kdPoli)->update(['status' => '0']);

        $this->expectException(IgdException::class);
        app(RegistrasiIgdService::class)->daftar($this->dataDaftar($this->pasienBebas()) + ['kd_poli' => $this->kdPoli]);
    }

    public function test_pasien_baru_kembali_ke_form_jenis_asal(): void
    {
        $rm = $this->pasienBebas();
        $this->actingAsKhanza(['registrasi' => true, 'igd' => true]);

        Livewire::withQueryParams(['pasien' => $rm, 'baru' => 'igd'])
            ->test('pages::registrasi.index')
            ->assertSet('showForm', true)
            ->assertSet('formJenis', 'igd')
            ->assertSet('no_rkm_medis', $rm);

        Livewire::withQueryParams(['pasien' => $rm])
            ->test('pages::registrasi.index')
            ->assertSet('formJenis', 'ralan');
    }

    // ------------------------------------------------------------------

    private function pasienBebas(): string
    {
        $rm = DB::table('pasien')
            ->where('no_rkm_medis', '<>', '-')
            ->whereNotNull('tgl_lahir')
            ->where('tgl_lahir', '>', '1900-01-01')
            ->where('tgl_daftar', '<>', self::TGL)
            ->whereNotIn('no_rkm_medis', function ($q) {
                $q->select('reg_periksa.no_rkm_medis')
                    ->from('kamar_inap')
                    ->join('reg_periksa', 'reg_periksa.no_rawat', '=', 'kamar_inap.no_rawat')
                    ->where('kamar_inap.stts_pulang', '-');
            })
            ->orderBy('no_rkm_medis')
            ->value('no_rkm_medis');

        $this->assertNotNull($rm, 'Data tes butuh pasien yang tidak sedang ranap.');

        return $rm;
    }

    private function dataDaftar(string $rm): array
    {
        return [
            'no_rkm_medis' => $rm,
            'kd_dokter' => $this->kdDokter,
            'kd_pj' => DB::table('pasien')->where('no_rkm_medis', $rm)->value('kd_pj'),
            'tgl_registrasi' => self::TGL,
            'jam_reg' => '09:00:00',
        ];
    }
}
