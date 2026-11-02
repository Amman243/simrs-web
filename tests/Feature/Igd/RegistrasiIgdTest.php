<?php

namespace Tests\Feature\Igd;

use App\Services\Igd\IgdException;
use App\Services\Igd\RegistrasiIgdService;
use App\Services\Khanza\NomorRawat;
use App\Services\Khanza\Umur;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrasiIgdTest extends TestCase
{
    use DatabaseTransactions;

    private const TGL = '2099-01-03';

    private string $kdDokter;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('set_validasi_registrasi')->update(['wajib_closing_kasir' => 'No']);
        DB::table('poliklinik')->where('kd_poli', 'IGDK')->update(['registrasi' => 15000, 'registrasilama' => 7000]);
        $this->kdDokter = DB::table('dokter')->where('status', '1')->where('kd_dokter', '<>', '-')->value('kd_dokter');
    }

    // ------------------------------------------------------------------
    // Akses & tampilan
    // ------------------------------------------------------------------

    public function test_halaman_daftar_tampil_untuk_user_igd(): void
    {
        $this->actingAsKhanza(['igd' => true])
            ->get(route('igd.index'))
            ->assertOk()
            ->assertSee('Registrasi IGD')
            ->assertSee('Registrasi Baru')
            ->assertDontSee(route('igd.pasien-baru'));
    }

    public function test_tombol_pasien_baru_tampil_bila_punya_hak_pasien(): void
    {
        $this->actingAsKhanza(['igd' => true, 'pasien' => true])
            ->get(route('igd.index'))
            ->assertOk()
            ->assertSee(route('igd.pasien-baru'));
    }

    public function test_tanpa_hak_igd_ditolak(): void
    {
        $this->actingAsKhanza(['tindakan_ralan' => true])->get(route('igd.index'))->assertForbidden();
    }

    public function test_daftar_menampilkan_registrasi_igd_sesuai_tanggal_dan_pencarian(): void
    {
        $rm = $this->pasienBebas();
        $hasil = $this->service()->daftar($this->dataDaftar($rm));
        $nama = DB::table('pasien')->where('no_rkm_medis', $rm)->value('nm_pasien');

        $this->actingAsKhanza(['igd' => true]);

        Livewire::test('pages::igd.index')
            ->set('tglAwal', self::TGL)
            ->set('tglAkhir', self::TGL)
            ->assertSee($hasil['no_rawat'])
            ->set('cari', $rm)
            ->assertSee($nama)
            ->set('cari', 'TIDAK-ADA-PASIEN-INI')
            ->assertDontSee($hasil['no_rawat']);
    }

    // ------------------------------------------------------------------
    // Registrasi baru
    // ------------------------------------------------------------------

    public function test_registrasi_lewat_form_membuat_reg_periksa_sesuai_khanza(): void
    {
        $rm = $this->pasienBebas();
        $pasien = DB::table('pasien')->where('no_rkm_medis', $rm)->first();
        $statusPoli = DB::table('reg_periksa')->where('no_rkm_medis', $rm)->where('kd_poli', 'IGDK')->exists() ? 'Lama' : 'Baru';

        $this->actingAsKhanza(['igd' => true]);

        Livewire::test('pages::igd.index')
            ->call('bukaForm')
            ->assertSet('showForm', true)
            ->set('tgl_registrasi', self::TGL)
            ->set('jam_reg', '08:15')
            ->call('pilihPasien', $rm)
            ->assertSet('p_jawab', (string) $pasien->namakeluarga)
            ->assertSet('hubunganpj', (string) $pasien->keluarga)
            ->assertSet('kd_pj', $pasien->kd_pj)
            ->set('kd_dokter', $this->kdDokter)
            ->call('simpan')
            ->assertHasNoErrors()
            ->assertSet('showForm', false)
            ->assertDispatched('toast');

        $reg = DB::table('reg_periksa')->where('no_rawat', '2099/01/03/000001')->first();

        $this->assertNotNull($reg);
        $this->assertSame('001', $reg->no_reg);
        $this->assertSame(self::TGL, $reg->tgl_registrasi);
        $this->assertSame('08:15:00', $reg->jam_reg);
        $this->assertSame('IGDK', $reg->kd_poli);
        $this->assertSame($rm, $reg->no_rkm_medis);
        $this->assertSame($this->kdDokter, $reg->kd_dokter);
        $this->assertEquals(7000, $reg->biaya_reg); // pasien lama → registrasilama
        $this->assertSame('Belum', $reg->stts);
        $this->assertSame('Lama', $reg->stts_daftar);
        $this->assertSame('Ralan', $reg->status_lanjut);
        $this->assertSame('Belum Bayar', $reg->status_bayar);
        $this->assertSame($statusPoli, $reg->status_poli);
        $this->assertSame((string) $pasien->namakeluarga, $reg->p_jawab);

        $umur = Umur::untukRegistrasi($pasien->tgl_lahir);
        $this->assertSame($umur['umurdaftar'], (int) $reg->umurdaftar);
        $this->assertSame($umur['sttsumur'], $reg->sttsumur);
        $this->assertSame(Umur::teks($pasien->tgl_lahir), DB::table('pasien')->where('no_rkm_medis', $rm)->value('umur'));
    }

    public function test_pasien_baru_di_rs_memakai_biaya_registrasi_baru_dan_status_poli_lama_pada_kunjungan_kedua(): void
    {
        $rm = $this->pasienBebas();
        DB::table('pasien')->where('no_rkm_medis', $rm)->update(['tgl_daftar' => self::TGL]);
        $statusPoli = DB::table('reg_periksa')->where('no_rkm_medis', $rm)->where('kd_poli', 'IGDK')->exists() ? 'Lama' : 'Baru';

        $pertama = $this->service()->daftar($this->dataDaftar($rm));
        $kedua = $this->service()->daftar($this->dataDaftar($rm));

        $reg1 = DB::table('reg_periksa')->where('no_rawat', $pertama['no_rawat'])->first();
        $this->assertSame('Baru', $reg1->stts_daftar);
        $this->assertEquals(15000, $reg1->biaya_reg);
        $this->assertSame($statusPoli, $reg1->status_poli);

        $this->assertSame('2099/01/03/000002', $kedua['no_rawat']);
        $this->assertSame('002', $kedua['no_reg']);
        $this->assertSame('Lama', DB::table('reg_periksa')->where('no_rawat', $kedua['no_rawat'])->value('status_poli'));
    }

    public function test_asal_rujukan_membuat_rujuk_masuk(): void
    {
        $rm = $this->pasienBebas();
        $hasil = $this->service()->daftar($this->dataDaftar($rm) + ['asal_rujukan' => 'PUSKESMAS SEHAT', 'alamat_perujuk' => '']);

        $rujuk = DB::table('rujuk_masuk')->where('no_rawat', $hasil['no_rawat'])->first();
        $this->assertNotNull($rujuk);
        $this->assertSame('PUSKESMAS SEHAT', $rujuk->perujuk);
        $this->assertSame('PUSKESMAS SEHAT', $rujuk->dokter_perujuk);
        $this->assertSame('-', $rujuk->alamat);
        $this->assertSame('-', $rujuk->no_rujuk);
        $this->assertSame('BR/2099/01/03/0001', $rujuk->no_balasan);
    }

    public function test_pasien_sedang_dirawat_inap_ditolak(): void
    {
        $rm = DB::table('kamar_inap')
            ->join('reg_periksa', 'reg_periksa.no_rawat', '=', 'kamar_inap.no_rawat')
            ->where('kamar_inap.stts_pulang', '-')
            ->value('reg_periksa.no_rkm_medis');
        $this->assertNotNull($rm, 'Data tes butuh pasien yang sedang ranap.');

        $this->actingAsKhanza(['igd' => true]);

        Livewire::test('pages::igd.index')
            ->call('bukaForm')
            ->set('tgl_registrasi', self::TGL)
            ->call('pilihPasien', $rm)
            ->set('kd_dokter', $this->kdDokter)
            ->call('simpan')
            ->assertHasErrors(['no_rkm_medis'])
            ->assertSee(RegistrasiIgdService::PESAN_RANAP);

        $this->assertFalse(DB::table('reg_periksa')->where('tgl_registrasi', self::TGL)->exists());
    }

    public function test_wajib_closing_kasir_menolak_pasien_dengan_tagihan_belum_lunas(): void
    {
        $rm = $this->pasienBebas();
        $this->service()->daftar($this->dataDaftar($rm)); // kunjungan Belum Bayar
        DB::table('set_validasi_registrasi')->update(['wajib_closing_kasir' => 'Yes']);

        $this->expectException(IgdException::class);
        $this->expectExceptionMessage(RegistrasiIgdService::PESAN_BELUM_LUNAS);

        $this->service()->daftar($this->dataDaftar($rm));
    }

    public function test_insert_dicoba_ulang_saat_no_rawat_duplikat(): void
    {
        $rm = $this->pasienBebas();
        $this->service()->daftar($this->dataDaftar($rm)); // 2099/01/03/000001

        // Percobaan pertama memakai nomor yang sudah dipakai → duplikat → nomor dibuat ulang.
        $service = new class extends RegistrasiIgdService
        {
            public int $panggilan = 0;

            protected function nomorBaru(string $kdDokter, string $kdPoli, CarbonInterface $tanggal): array
            {
                return $this->panggilan++ === 0
                    ? ['001', '2099/01/03/000001']
                    : [NomorRawat::noReg($kdDokter, $kdPoli, $tanggal), NomorRawat::noRawat($tanggal)];
            }
        };

        $hasil = $service->daftar($this->dataDaftar($rm));

        $this->assertSame(2, $service->panggilan);
        $this->assertSame('2099/01/03/000002', $hasil['no_rawat']);
        $this->assertSame(2, DB::table('reg_periksa')->where('tgl_registrasi', self::TGL)->count());
    }

    public function test_query_string_pasien_membuka_form_dengan_pasien_terpilih(): void
    {
        $rm = $this->pasienBebas();
        $this->actingAsKhanza(['igd' => true]);

        Livewire::withQueryParams(['pasien' => $rm])
            ->test('pages::igd.index')
            ->assertSet('showForm', true)
            ->assertSet('no_rkm_medis', $rm)
            ->assertSet('pasienInfo.no_rkm_medis', $rm);
    }

    public function test_cari_pasien_mengembalikan_hasil(): void
    {
        $rm = $this->pasienBebas();
        $this->actingAsKhanza(['igd' => true]);

        Livewire::test('pages::igd.index')
            ->call('bukaForm')
            ->set('cariPasien', $rm)
            ->assertSee("pilihPasien('{$rm}')", false);
    }

    // ------------------------------------------------------------------
    // Status
    // ------------------------------------------------------------------

    public function test_batal_menolkan_biaya_dan_mengunci_registrasi(): void
    {
        $hasil = $this->service()->daftar($this->dataDaftar($this->pasienBebas()));
        $this->actingAsKhanza(['igd' => true]);

        Livewire::test('pages::igd.index')
            ->call('ubahStatus', $hasil['no_rawat'], 'Batal')
            ->assertDispatched('toast', type: 'success');

        $reg = DB::table('reg_periksa')->where('no_rawat', $hasil['no_rawat'])->first();
        $this->assertSame('Batal', $reg->stts);
        $this->assertEquals(0, $reg->biaya_reg);

        // Sudah Batal → terkunci, status tidak bisa diubah lagi.
        Livewire::test('pages::igd.index')
            ->call('ubahStatus', $hasil['no_rawat'], 'Sudah')
            ->assertDispatched('toast', type: 'error');
        $this->assertSame('Batal', DB::table('reg_periksa')->where('no_rawat', $hasil['no_rawat'])->value('stts'));
    }

    public function test_ubah_status_sudah(): void
    {
        $hasil = $this->service()->daftar($this->dataDaftar($this->pasienBebas()));
        $this->service()->ubahStatus($hasil['no_rawat'], 'Sudah');

        $this->assertSame('Sudah', DB::table('reg_periksa')->where('no_rawat', $hasil['no_rawat'])->value('stts'));
        $this->assertEquals(7000, DB::table('reg_periksa')->where('no_rawat', $hasil['no_rawat'])->value('biaya_reg'));
    }

    public function test_ubah_status_ditolak_bila_sudah_masuk_kamar_inap(): void
    {
        $hasil = $this->service()->daftar($this->dataDaftar($this->pasienBebas()));
        $template = (array) DB::table('kamar_inap')->first();
        DB::table('kamar_inap')->insert(array_merge($template, ['no_rawat' => $hasil['no_rawat']]));

        $this->expectException(IgdException::class);
        $this->expectExceptionMessage(RegistrasiIgdService::PESAN_KAMAR_INAP);

        $this->service()->ubahStatus($hasil['no_rawat'], 'Sudah');
    }

    public function test_ubah_status_butuh_hak_igd(): void
    {
        $hasil = $this->service()->daftar($this->dataDaftar($this->pasienBebas()));
        $this->actingAsKhanza(['igd' => false]);

        Livewire::test('pages::igd.index')
            ->call('ubahStatus', $hasil['no_rawat'], 'Batal')
            ->assertForbidden();

        $this->assertSame('Belum', DB::table('reg_periksa')->where('no_rawat', $hasil['no_rawat'])->value('stts'));
    }

    // ------------------------------------------------------------------
    // Edit
    // ------------------------------------------------------------------

    public function test_edit_ditolak_tanpa_hak_edit_registrasi(): void
    {
        $hasil = $this->service()->daftar($this->dataDaftar($this->pasienBebas()));
        $this->actingAsKhanza(['igd' => true]);

        Livewire::test('pages::igd.index')->call('edit', $hasil['no_rawat'])->assertForbidden();
        Livewire::test('pages::igd.index')->set('editNoRawat', $hasil['no_rawat'])->call('simpanEdit')->assertForbidden();
    }

    public function test_edit_registrasi_dengan_hak_edit(): void
    {
        $hasil = $this->service()->daftar($this->dataDaftar($this->pasienBebas()));
        $dokterLain = DB::table('dokter')->where('status', '1')->whereNotIn('kd_dokter', ['-', $this->kdDokter])->value('kd_dokter');
        $this->actingAsKhanza(['igd' => true, 'edit_registrasi' => true]);

        Livewire::test('pages::igd.index')
            ->call('edit', $hasil['no_rawat'])
            ->assertSet('showForm', true)
            ->set('kd_dokter', $dokterLain)
            ->set('p_jawab', 'BUDI')
            ->set('hubunganpj', 'SAUDARA')
            ->call('simpanEdit')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $reg = DB::table('reg_periksa')->where('no_rawat', $hasil['no_rawat'])->first();
        $this->assertSame($dokterLain, $reg->kd_dokter);
        $this->assertSame('BUDI', $reg->p_jawab);
        $this->assertSame('SAUDARA', $reg->hubunganpj);
        $this->assertSame($hasil['no_reg'], $reg->no_reg);
    }

    public function test_edit_ditolak_bila_terkunci(): void
    {
        $hasil = $this->service()->daftar($this->dataDaftar($this->pasienBebas()));
        $this->service()->ubahStatus($hasil['no_rawat'], 'Batal');

        $this->expectException(IgdException::class);
        $this->expectExceptionMessage(RegistrasiIgdService::PESAN_TERKUNCI);

        $this->service()->ubah($hasil['no_rawat'], ['kd_dokter' => $this->kdDokter, 'kd_pj' => '-']);
    }

    // ------------------------------------------------------------------

    private function service(): RegistrasiIgdService
    {
        return app(RegistrasiIgdService::class);
    }

    /**
     * Pasien yang tidak sedang dirawat inap dan punya tanggal lahir.
     */
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
        $kdPj = DB::table('pasien')->where('no_rkm_medis', $rm)->value('kd_pj');

        return [
            'no_rkm_medis' => $rm,
            'kd_dokter' => $this->kdDokter,
            'kd_pj' => $kdPj,
            'tgl_registrasi' => Carbon::parse(self::TGL)->toDateString(),
            'jam_reg' => '09:00:00',
            'p_jawab' => 'PJ TES',
            'hubunganpj' => 'SAUDARA',
            'almt_pj' => 'ALAMAT TES',
        ];
    }
}
