<?php

namespace Tests\Feature\RawatJalan;

use App\Services\RawatJalan\TindakanService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class TindakanTest extends RawatJalanTestCase
{
    public function test_halaman_kunjungan_tampil_dan_404_bila_tidak_ada(): void
    {
        $reg = $this->buatRegistrasi();

        $this->actingAsAdmin()
            ->get(route('rawat-jalan.show', ['noRawat' => $reg->no_rawat]))
            ->assertOk()
            ->assertSee($reg->no_rawat)
            ->assertSee('Selesai diperiksa');

        $this->actingAsAdmin()->get(route('rawat-jalan.show', ['noRawat' => '2099/12/31/999999']))->assertNotFound();
    }

    public function test_simpan_dan_hapus_tindakan_dokter(): void
    {
        $reg = $this->buatRegistrasi();
        $t = $this->tindakanKatalog('total_byrdr');
        $this->actingAsAdmin();

        $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])
            ->call('pilihJenis', 'dr')
            ->assertSet('kdDokter', self::DOKTER)
            ->set('pilih', [$t->kd_jenis_prw])
            ->set('tglTindakan', today()->toDateString())
            ->set('jamTindakan', '09:15')
            ->call('simpanTindakan')
            ->assertHasNoErrors();
        $this->assertToast($lw, 'success');

        $row = DB::table('rawat_jl_dr')->where('no_rawat', $reg->no_rawat)->first();
        $this->assertNotNull($row);
        $this->assertSame(self::DOKTER, $row->kd_dokter);
        $this->assertSame('09:15:00', $row->jam_rawat);
        $this->assertEquals($t->total_byrdr, $row->biaya_rawat);
        $this->assertEquals($t->tarif_tindakandr, $row->tarif_tindakandr);
        $this->assertEquals((float) $t->material, $row->material);
        $this->assertSame('Belum', $row->stts_bayar);
        $lw->assertSet('pilih', []);

        $lw->call('hapusTindakan', 'dr', [
            'kd_jenis_prw' => $row->kd_jenis_prw, 'kd_dokter' => $row->kd_dokter,
            'tgl_perawatan' => $row->tgl_perawatan, 'jam_rawat' => $row->jam_rawat,
        ]);
        $this->assertFalse(DB::table('rawat_jl_dr')->where('no_rawat', $reg->no_rawat)->exists());
    }

    public function test_simpan_dan_hapus_tindakan_petugas(): void
    {
        $reg = $this->buatRegistrasi();
        $t = $this->tindakanKatalog('total_byrpr');
        $this->actingAsKhanza(['tindakan_ralan' => true], nip: self::PETUGAS);

        $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])
            ->call('pilihJenis', 'pr')
            ->assertSet('nip', self::PETUGAS)
            ->set('pilih', [$t->kd_jenis_prw])
            ->call('simpanTindakan')
            ->assertHasNoErrors();

        $row = DB::table('rawat_jl_pr')->where('no_rawat', $reg->no_rawat)->first();
        $this->assertNotNull($row);
        $this->assertSame(self::PETUGAS, $row->nip);
        $this->assertEquals($t->total_byrpr, $row->biaya_rawat);
        $this->assertEquals($t->tarif_tindakanpr, $row->tarif_tindakanpr);
        $this->assertSame('Belum', $row->stts_bayar);

        $lw->call('hapusTindakan', 'pr', [
            'kd_jenis_prw' => $row->kd_jenis_prw, 'nip' => $row->nip,
            'tgl_perawatan' => $row->tgl_perawatan, 'jam_rawat' => $row->jam_rawat,
        ]);
        $this->assertFalse(DB::table('rawat_jl_pr')->where('no_rawat', $reg->no_rawat)->exists());
    }

    public function test_simpan_dan_hapus_tindakan_dokter_petugas(): void
    {
        $reg = $this->buatRegistrasi();
        $t = $this->tindakanKatalog('total_byrdrpr');
        $this->actingAsAdmin();

        $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])
            ->call('pilihJenis', 'drpr')
            ->set('nip', self::PETUGAS)
            ->set('pilih', [$t->kd_jenis_prw])
            ->call('simpanTindakan')
            ->assertHasNoErrors();

        $row = DB::table('rawat_jl_drpr')->where('no_rawat', $reg->no_rawat)->first();
        $this->assertNotNull($row);
        $this->assertSame(self::DOKTER, $row->kd_dokter);
        $this->assertSame(self::PETUGAS, $row->nip);
        $this->assertEquals($t->total_byrdrpr, $row->biaya_rawat);
        $this->assertEquals($t->tarif_tindakandr, $row->tarif_tindakandr);
        $this->assertEquals($t->tarif_tindakanpr, $row->tarif_tindakanpr);
        $this->assertSame('Belum', $row->stts_bayar);

        $lw->call('hapusTindakan', 'drpr', [
            'kd_jenis_prw' => $row->kd_jenis_prw, 'kd_dokter' => $row->kd_dokter, 'nip' => $row->nip,
            'tgl_perawatan' => $row->tgl_perawatan, 'jam_rawat' => $row->jam_rawat,
        ]);
        $this->assertFalse(DB::table('rawat_jl_drpr')->where('no_rawat', $reg->no_rawat)->exists());
    }

    public function test_petugas_wajib_untuk_jenis_petugas(): void
    {
        $reg = $this->buatRegistrasi();
        $t = $this->tindakanKatalog('total_byrpr');
        $this->actingAsAdmin();

        Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])
            ->call('pilihJenis', 'pr')
            ->set('nip', '')
            ->set('pilih', [$t->kd_jenis_prw])
            ->call('simpanTindakan')
            ->assertHasErrors(['nip']);

        $this->assertFalse(DB::table('rawat_jl_pr')->where('no_rawat', $reg->no_rawat)->exists());
    }

    public function test_duplikat_ditolak_dengan_rapi(): void
    {
        $reg = $this->buatRegistrasi();
        $t = $this->tindakanKatalog('total_byrdr');
        $this->actingAsAdmin();

        $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])
            ->set('jamTindakan', '10:00:00');
        $lw->set('pilih', [$t->kd_jenis_prw])->call('simpanTindakan');
        $lw->set('jamTindakan', '10:00:00')->set('pilih', [$t->kd_jenis_prw])->call('simpanTindakan');

        $this->assertToast($lw, 'error');
        $this->assertSame(1, DB::table('rawat_jl_dr')->where('no_rawat', $reg->no_rawat)->count());
    }

    public function test_non_admin_hanya_boleh_hapus_dalam_48_jam(): void
    {
        $reg = $this->buatRegistrasi(['tgl_registrasi' => today()->subDays(5)->toDateString()]);
        $t = $this->tindakanKatalog('total_byrdr');
        $lama = $this->insertTindakanDr($reg->no_rawat, $t, now()->subHours(49));
        $baru = $this->insertTindakanDr($reg->no_rawat, $t, now()->subHours(47));

        $this->actingAsKhanza(['tindakan_ralan' => true], kdDokter: self::DOKTER);
        $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat]);

        $lw->call('hapusTindakan', 'dr', $lama);
        $this->assertToast($lw, 'error');
        $this->assertTrue(DB::table('rawat_jl_dr')->where($lama)->exists());

        $lw->call('hapusTindakan', 'dr', $baru);
        $this->assertFalse(DB::table('rawat_jl_dr')->where($baru)->exists());

        // Admin tidak dibatasi 48 jam.
        $this->actingAsAdmin();
        Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])->call('hapusTindakan', 'dr', $lama);
        $this->assertFalse(DB::table('rawat_jl_dr')->where($lama)->exists());
    }

    public function test_tindakan_sudah_dibayar_tidak_bisa_dihapus(): void
    {
        $reg = $this->buatRegistrasi();
        $t = $this->tindakanKatalog('total_byrdr');
        $pk = $this->insertTindakanDr($reg->no_rawat, $t, now()->subHour(), 'Sudah');
        $this->actingAsAdmin();

        $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])->call('hapusTindakan', 'dr', $pk);

        $this->assertToast($lw, 'error');
        $this->assertTrue(DB::table('rawat_jl_dr')->where($pk)->exists());
    }

    public function test_registrasi_terkunci_menolak_tambah_dan_hapus(): void
    {
        $reg = $this->buatRegistrasi();
        $t = $this->tindakanKatalog('total_byrdr');
        $pk = $this->insertTindakanDr($reg->no_rawat, $t, now()->subHour());
        $this->kunciBilling($reg->no_rawat);
        $this->actingAsAdmin();

        $this->get(route('rawat-jalan.show', ['noRawat' => $reg->no_rawat]))->assertSee('Registrasi terkunci');

        $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])
            ->set('pilih', [$t->kd_jenis_prw])
            ->set('jamTindakan', '23:59:59')
            ->call('simpanTindakan')
            ->call('hapusTindakan', 'dr', $pk);

        $this->assertToast($lw, 'error');
        $this->assertSame(1, DB::table('rawat_jl_dr')->where('no_rawat', $reg->no_rawat)->count());
    }

    public function test_non_admin_tidak_boleh_input_sebelum_jam_registrasi(): void
    {
        $reg = $this->buatRegistrasi(['tgl_registrasi' => today()->toDateString(), 'jam_reg' => '10:00:00']);
        $t = $this->tindakanKatalog('total_byrdr');
        $this->actingAsKhanza(['tindakan_ralan' => true], kdDokter: self::DOKTER);

        $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])
            ->set('tglTindakan', today()->toDateString())
            ->set('jamTindakan', '09:00:00')
            ->set('pilih', [$t->kd_jenis_prw])
            ->call('simpanTindakan');

        $this->assertToast($lw, 'error');
        $this->assertFalse(DB::table('rawat_jl_dr')->where('no_rawat', $reg->no_rawat)->exists());
    }

    public function test_katalog_mengikuti_set_tarif(): void
    {
        $service = app(TindakanService::class);
        $semua = $service->katalogQuery('dr', 'A09', 'U0009')->count();

        DB::table('set_tarif')->update(['poli_ralan' => 'Yes', 'cara_bayar_ralan' => 'Yes']);
        $terfilter = $service->katalogQuery('dr', 'A09', 'U0009')->count();
        $manual = DB::table('jns_perawatan')
            ->join('kategori_perawatan', 'jns_perawatan.kd_kategori', '=', 'kategori_perawatan.kd_kategori')
            ->where('total_byrdr', '>', 0)->where('status', '1')
            ->whereIn('jns_perawatan.kd_pj', ['A09', '-'])->whereIn('jns_perawatan.kd_poli', ['U0009', '-'])
            ->count();

        $this->assertSame($manual, $terfilter);
        $this->assertLessThanOrEqual($semua, $terfilter);
        $this->assertTrue($service->katalog('dr', 'A09', 'U0009')->every(fn ($r) => $r->total > 0));
    }

    public function test_aksi_tindakan_butuh_hak_akses(): void
    {
        $reg = $this->buatRegistrasi();
        $t = $this->tindakanKatalog('total_byrdr');
        $this->actingAsAdmin();
        $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat]);

        $this->actingAsKhanza([]);
        $lw->set('pilih', [$t->kd_jenis_prw])->call('simpanTindakan')->assertForbidden();

        $this->assertFalse(DB::table('rawat_jl_dr')->where('no_rawat', $reg->no_rawat)->exists());
    }

    /**
     * @return array<string, string>
     */
    private function insertTindakanDr(string $noRawat, object $t, Carbon $waktu, string $sttsBayar = 'Belum'): array
    {
        $pk = [
            'no_rawat' => $noRawat, 'kd_jenis_prw' => $t->kd_jenis_prw, 'kd_dokter' => self::DOKTER,
            'tgl_perawatan' => $waktu->toDateString(), 'jam_rawat' => $waktu->format('H:i:s'),
        ];

        DB::table('rawat_jl_dr')->insert($pk + [
            'material' => 0, 'bhp' => 0, 'tarif_tindakandr' => 0, 'kso' => 0, 'menejemen' => 0,
            'biaya_rawat' => $t->total_byrdr, 'stts_bayar' => $sttsBayar,
        ]);

        return $pk;
    }
}
