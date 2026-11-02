<?php

namespace Tests\Feature\RawatInap;

use App\Services\RawatInap\KamarInapService;
use App\Services\RawatInap\RawatInapException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class KamarInapTest extends RawatInapTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2099-01-01 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ Akses & halaman

    public function test_tanpa_hak_kamar_inap_ditolak_403(): void
    {
        $this->actingAsKhanza(['tindakan_ranap' => true]);

        $this->get(route('rawat-inap.index'))->assertForbidden();
        $this->get(route('rawat-inap.masuk'))->assertForbidden();
        $this->get(route('rawat-inap.show', ['noRawat' => '2026/10/31/000001']))->assertForbidden();
    }

    public function test_halaman_daftar_tampil_dengan_pasien_dirawat(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001');
        $this->buatRawatInap($reg->no_rawat, self::KAMAR_A, '2098-12-30', '10:00:00');
        $nama = DB::table('pasien')->where('no_rkm_medis', $reg->no_rkm_medis)->value('nm_pasien');

        $this->actingAsAdmin()->get(route('rawat-inap.index'))
            ->assertOk()
            ->assertSee('Rawat Inap')
            ->assertSee($reg->no_rawat)
            ->assertSee($nama);
    }

    public function test_daftar_menghitung_lama_berjalan_tanpa_menulis(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001');
        $this->buatRawatInap($reg->no_rawat, self::KAMAR_A, '2098-12-30', '10:00:00');
        $this->actingAsAdmin();

        $rows = Livewire::test('pages::rawat-inap.index')->set('cari', $reg->no_rawat)->instance()->rows;

        $this->assertSame(1, $rows->total());
        $this->assertSame(2, $rows->first()->lama);
        $this->assertEquals(1000000, $rows->first()->ttl_biaya);
        $this->assertEquals(0, $this->kamarInap($reg->no_rawat)->first()->lama);
    }

    public function test_filter_pasien_saya_untuk_dokter(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001');
        $this->buatRawatInap($reg->no_rawat, self::KAMAR_A);
        DB::table('dpjp_ranap')->insert(['no_rawat' => $reg->no_rawat, 'kd_dokter' => 'D0000005']);

        $this->actingAsKhanza(['kamar_inap' => true], kdDokter: 'D0000005');
        $komponen = Livewire::test('pages::rawat-inap.index')->assertSet('pasienSaya', true);
        $this->assertSame([$reg->no_rawat], collect($komponen->instance()->rows->items())->pluck('no_rawat')->all());

        $this->actingAsKhanza(['kamar_inap' => true], kdDokter: 'D0000002');
        $this->assertNotContains($reg->no_rawat, collect(Livewire::test('pages::rawat-inap.index')->instance()->rows->items())->pluck('no_rawat'));
    }

    // ------------------------------------------------------------------ Masuk kamar

    public function test_masuk_kamar_menyimpan_semua_tabel(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001');
        $this->actingAsAdmin();

        Livewire::withQueryParams(['no_rawat' => $reg->no_rawat])
            ->test('pages::rawat-inap.masuk')
            ->assertSet('kd_dokter', 'D0000003')
            ->set('kd_kamar', self::KAMAR_A)
            ->set('diagnosa_awal', 'Demam berdarah')
            ->set('tgl_masuk', '2099-01-01')
            ->set('jam_masuk', '01:00:00')
            ->set('kd_dokter', 'D0000004')
            ->call('simpan')
            ->assertHasNoErrors()
            ->assertRedirect(route('rawat-inap.index'));

        $rows = $this->kamarInap($reg->no_rawat);
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame(self::KAMAR_A, $row->kd_kamar);
        $this->assertEquals(500000, $row->trf_kamar);
        $this->assertSame('Demam berdarah', $row->diagnosa_awal);
        $this->assertSame('', $row->diagnosa_akhir);
        $this->assertSame('01:00:00', $row->jam_masuk);
        $this->assertSame('0000-00-00', $row->tgl_keluar);
        $this->assertSame('00:00:00', $row->jam_keluar);
        $this->assertEquals(1, $row->lama); // 14 jam > lamajam 12
        $this->assertEquals(500000, $row->ttl_biaya);
        $this->assertSame('-', $row->stts_pulang);

        $this->assertSame('ISI', $this->statusKamar(self::KAMAR_A));
        $regBaru = DB::table('reg_periksa')->where('no_rawat', $reg->no_rawat)->first();
        $this->assertSame('Ranap', $regBaru->status_lanjut);
        $this->assertSame('Dirawat', $regBaru->stts);
        $this->assertTrue(DB::table('dpjp_ranap')->where(['no_rawat' => $reg->no_rawat, 'kd_dokter' => 'D0000004'])->exists());
    }

    public function test_masuk_kamar_memakai_tarif_penjab(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001', override: ['kd_pj' => 'BPJ']);
        DB::table('set_harga_kamar')->insert(['kd_kamar' => self::KAMAR_A, 'kd_pj' => 'BPJ', 'tarif' => 250000]);
        $this->actingAsAdmin();

        Livewire::withQueryParams(['no_rawat' => $reg->no_rawat])
            ->test('pages::rawat-inap.masuk')
            ->set('kd_kamar', self::KAMAR_A)
            ->set('diagnosa_awal', 'Tifoid')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertEquals(250000, $this->kamarInap($reg->no_rawat)->first()->trf_kamar);
    }

    public function test_masuk_kamar_wajib_diagnosa_awal(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001');
        $this->actingAsAdmin();

        Livewire::withQueryParams(['no_rawat' => $reg->no_rawat])
            ->test('pages::rawat-inap.masuk')
            ->set('kd_kamar', self::KAMAR_A)
            ->call('simpan')
            ->assertHasErrors(['diagnosa_awal' => 'required']);

        $this->assertCount(0, $this->kamarInap($reg->no_rawat));
    }

    public function test_masuk_kamar_ditolak_bila_kamar_tidak_kosong(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001');
        DB::table('kamar')->where('kd_kamar', self::KAMAR_A)->update(['status' => 'ISI']);
        $this->actingAsAdmin();

        Livewire::withQueryParams(['no_rawat' => $reg->no_rawat])
            ->test('pages::rawat-inap.masuk')
            ->set('kd_kamar', self::KAMAR_A)
            ->set('diagnosa_awal', 'Demam')
            ->call('simpan')
            ->assertHasErrors('kd_kamar');

        $this->assertCount(0, $this->kamarInap($reg->no_rawat));
        $this->assertSame('Ralan', DB::table('reg_periksa')->where('no_rawat', $reg->no_rawat)->value('status_lanjut'));
    }

    public function test_masuk_kamar_ditolak_bila_pasien_masih_dirawat(): void
    {
        $lama = $this->buatRegistrasi('2099/01/01/000001');
        $this->buatRawatInap($lama->no_rawat, self::KAMAR_B);
        $baru = $this->buatRegistrasi('2099/01/01/000002', $lama->no_rkm_medis, ['no_reg' => '902']);
        $this->actingAsAdmin();

        Livewire::withQueryParams(['no_rawat' => $baru->no_rawat])
            ->test('pages::rawat-inap.masuk')
            ->assertSee('Pasien masih dirawat inap')
            ->set('kd_kamar', self::KAMAR_A)
            ->set('diagnosa_awal', 'Demam')
            ->call('simpan')
            ->assertHasErrors('kd_kamar');

        $this->assertCount(0, $this->kamarInap($baru->no_rawat));
        $this->assertSame('KOSONG', $this->statusKamar(self::KAMAR_A));
    }

    public function test_masuk_kamar_ditolak_bila_sudah_punya_kamar_inap(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001');
        $this->buatRawatInap($reg->no_rawat, self::KAMAR_B);
        $this->actingAsAdmin();

        Livewire::withQueryParams(['no_rawat' => $reg->no_rawat])
            ->test('pages::rawat-inap.masuk')
            ->assertSee('sudah memiliki data kamar inap')
            ->assertSee(self::KAMAR_B)
            ->set('kd_kamar', self::KAMAR_A)
            ->set('diagnosa_awal', 'Demam')
            ->call('simpan')
            ->assertHasErrors('kd_kamar');

        $this->assertCount(1, $this->kamarInap($reg->no_rawat));
    }

    public function test_masuk_kamar_ditolak_bila_terkunci(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001', override: ['stts' => 'Batal']);
        $this->actingAsAdmin();

        Livewire::withQueryParams(['no_rawat' => $reg->no_rawat])
            ->test('pages::rawat-inap.masuk')
            ->assertSee('Batal')
            ->set('kd_kamar', self::KAMAR_A)
            ->set('diagnosa_awal', 'Demam')
            ->call('simpan')
            ->assertHasErrors('kd_kamar');

        $reg2 = $this->buatRegistrasi('2099/01/01/000002', $this->pasienBebas(1), ['no_reg' => '902']);
        DB::table('billing')->insert([
            'noindex' => 1, 'no_rawat' => $reg2->no_rawat, 'tgl_byr' => '2099-01-01', 'no' => '-', 'nm_perawatan' => 'Tes',
            'pemisah' => '-', 'biaya' => 0, 'jumlah' => 0, 'tambahan' => 0, 'totalbiaya' => 0, 'status' => '-',
        ]);

        Livewire::withQueryParams(['no_rawat' => $reg2->no_rawat])
            ->test('pages::rawat-inap.masuk')
            ->assertSee('billing sudah terverifikasi')
            ->set('kd_kamar', self::KAMAR_A)
            ->set('diagnosa_awal', 'Demam')
            ->call('simpan')
            ->assertHasErrors('kd_kamar');

        $this->assertCount(0, $this->kamarInap($reg->no_rawat));
        $this->assertCount(0, $this->kamarInap($reg2->no_rawat));
        $this->assertSame('KOSONG', $this->statusKamar(self::KAMAR_A));
    }

    public function test_halaman_masuk_tanpa_no_rawat_menampilkan_pencarian(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001', override: ['tgl_registrasi' => '2099-01-01']);
        $this->actingAsAdmin();

        Livewire::test('pages::rawat-inap.masuk')
            ->assertSee('Pilih registrasi')
            ->assertSee($reg->no_rawat)
            ->call('pilih', $reg->no_rawat)
            ->assertSet('noRawat', $reg->no_rawat)
            ->assertSee('Data masuk kamar');
    }

    // ------------------------------------------------------------------ Pindah kamar

    public function test_pindah_kamar_mode_standar(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001');
        $this->buatRawatInap($reg->no_rawat, self::KAMAR_A, '2098-12-29', '10:00:00');
        $this->actingAsAdmin();

        Livewire::test('pages::rawat-inap.index')
            ->call('bukaPindah', $reg->no_rawat, '2098-12-29', '10:00:00')
            ->assertSet('showPindah', true)
            ->set('kamarBaru', self::KAMAR_B)
            ->set('tglPindah', '2099-01-01')
            ->set('jamPindah', '09:30:00')
            ->call('simpanPindah')
            ->assertHasNoErrors()
            ->assertSet('showPindah', false);

        $rows = $this->kamarInap($reg->no_rawat);
        $this->assertCount(2, $rows);

        [$lama, $baru] = [$rows[0], $rows[1]];
        $this->assertSame(self::KAMAR_A, $lama->kd_kamar);
        $this->assertSame('Pindah Kamar', $lama->stts_pulang);
        $this->assertSame('2099-01-01', $lama->tgl_keluar);
        $this->assertSame('09:30:00', $lama->jam_keluar);
        $this->assertEquals(3, $lama->lama);
        $this->assertEquals(1500000, $lama->ttl_biaya);

        $this->assertSame(self::KAMAR_B, $baru->kd_kamar);
        $this->assertSame('-', $baru->stts_pulang);
        $this->assertSame('2099-01-01', $baru->tgl_masuk);
        $this->assertSame('09:30:00', $baru->jam_masuk);
        $this->assertSame('0000-00-00', $baru->tgl_keluar);
        $this->assertEquals(100000, $baru->trf_kamar);
        $this->assertSame('Demam', $baru->diagnosa_awal);

        $this->assertSame('KOSONG', $this->statusKamar(self::KAMAR_A));
        $this->assertSame('ISI', $this->statusKamar(self::KAMAR_B));
    }

    public function test_pindah_kamar_ditolak_bila_kamar_tujuan_terisi(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001');
        $this->buatRawatInap($reg->no_rawat, self::KAMAR_A, '2098-12-29', '10:00:00');
        DB::table('kamar')->where('kd_kamar', self::KAMAR_B)->update(['status' => 'ISI']);
        $this->actingAsAdmin();

        Livewire::test('pages::rawat-inap.index')
            ->call('bukaPindah', $reg->no_rawat, '2098-12-29', '10:00:00')
            ->set('kamarBaru', self::KAMAR_B)
            ->call('simpanPindah')
            ->assertHasErrors('kamarBaru');

        $this->assertCount(1, $this->kamarInap($reg->no_rawat));
        $this->assertSame('ISI', $this->statusKamar(self::KAMAR_A));
    }

    // ------------------------------------------------------------------ Pulang

    public function test_pulang_mengubah_baris_dan_mengosongkan_kamar(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001', override: ['status_lanjut' => 'Ranap', 'stts' => 'Dirawat']);
        $this->buatRawatInap($reg->no_rawat, self::KAMAR_A, '2098-12-30', '22:00:00');
        $regSebelum = (array) DB::table('reg_periksa')->where('no_rawat', $reg->no_rawat)->first();
        $this->actingAsAdmin();

        Livewire::test('pages::rawat-inap.index')
            ->call('bukaPulang', $reg->no_rawat, '2098-12-30', '22:00:00')
            ->assertSet('showPulang', true)
            ->set('sttsPulang', 'Membaik')
            ->set('diagnosaAkhir', 'DHF grade I')
            ->set('tglKeluar', '2099-01-01')
            ->set('jamKeluar', '08:00')
            ->call('simpanPulang')
            ->assertHasNoErrors()
            ->assertSet('showPulang', false);

        $row = $this->kamarInap($reg->no_rawat)->first();
        $this->assertSame('Membaik', $row->stts_pulang);
        $this->assertSame('DHF grade I', $row->diagnosa_akhir);
        $this->assertSame('2099-01-01', $row->tgl_keluar);
        $this->assertSame('08:00:00', $row->jam_keluar);
        $this->assertEquals(2, $row->lama);
        $this->assertEquals(1000000, $row->ttl_biaya);
        $this->assertSame('KOSONG', $this->statusKamar(self::KAMAR_A));
        $this->assertSame($regSebelum, (array) DB::table('reg_periksa')->where('no_rawat', $reg->no_rawat)->first());
    }

    public function test_pulang_wajib_status_dan_diagnosa_akhir(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001');
        $this->buatRawatInap($reg->no_rawat, self::KAMAR_A);
        $this->actingAsAdmin();

        Livewire::test('pages::rawat-inap.index')
            ->call('bukaPulang', $reg->no_rawat, '2099-01-01', '10:00:00')
            ->set('sttsPulang', 'Pindah Kamar')
            ->call('simpanPulang')
            ->assertHasErrors(['sttsPulang', 'diagnosaAkhir']);

        $this->assertSame('-', $this->kamarInap($reg->no_rawat)->first()->stts_pulang);
        $this->assertSame('ISI', $this->statusKamar(self::KAMAR_A));
    }

    public function test_pulang_ditolak_bila_terkunci(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001');
        $this->buatRawatInap($reg->no_rawat, self::KAMAR_A);
        DB::table('reg_periksa')->where('no_rawat', $reg->no_rawat)->update(['stts' => 'Batal']);
        $this->actingAsAdmin();

        Livewire::test('pages::rawat-inap.index')
            ->call('bukaPulang', $reg->no_rawat, '2099-01-01', '10:00:00')
            ->assertSet('showPulang', false)
            ->assertDispatched('toast');

        $this->expectException(RawatInapException::class);
        app(KamarInapService::class)
            ->pulang($reg->no_rawat, '2099-01-01', '10:00:00', 'Sehat', 'Sembuh', now());
    }

    // ------------------------------------------------------------------ Batal pulang

    public function test_batalkan_pulang_mengembalikan_status_dirawat(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001');
        $this->buatRawatInap($reg->no_rawat, self::KAMAR_A, '2098-12-30', '10:00:00', [
            'tgl_keluar' => '2099-01-01', 'jam_keluar' => '09:00:00', 'lama' => 2, 'ttl_biaya' => 1000000,
            'stts_pulang' => 'Sehat', 'diagnosa_akhir' => 'Sembuh',
        ]);
        $this->actingAsAdmin();

        Livewire::test('pages::rawat-inap.index')
            ->call('batalPulang', $reg->no_rawat, '2098-12-30', '10:00:00')
            ->assertDispatched('toast');

        $row = $this->kamarInap($reg->no_rawat)->first();
        $this->assertSame('-', $row->stts_pulang);
        $this->assertSame('0000-00-00', $row->tgl_keluar);
        $this->assertSame('00:00:00', $row->jam_keluar);
        $this->assertSame('ISI', $this->statusKamar(self::KAMAR_A));
    }

    public function test_batalkan_pulang_ditolak_bila_kamar_sudah_terisi(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001');
        $this->buatRawatInap($reg->no_rawat, self::KAMAR_A, '2098-12-30', '10:00:00', [
            'tgl_keluar' => '2099-01-01', 'jam_keluar' => '09:00:00', 'stts_pulang' => 'Sehat',
        ]);
        DB::table('kamar')->where('kd_kamar', self::KAMAR_A)->update(['status' => 'ISI']);
        $this->actingAsAdmin();

        Livewire::test('pages::rawat-inap.index')
            ->call('batalPulang', $reg->no_rawat, '2098-12-30', '10:00:00')
            ->assertDispatched('toast', type: 'error');

        $this->assertSame('Sehat', $this->kamarInap($reg->no_rawat)->first()->stts_pulang);
    }

    public function test_aksi_kamar_butuh_hak_kamar_inap(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001');
        $this->buatRawatInap($reg->no_rawat, self::KAMAR_A);

        // Komponen bisa dirender di luar route; aksi tetap dicek di server.
        $this->actingAsKhanza(['tindakan_ranap' => true]);

        Livewire::test('pages::rawat-inap.index')
            ->call('bukaPulang', $reg->no_rawat, '2099-01-01', '10:00:00')
            ->assertForbidden();
    }
}
