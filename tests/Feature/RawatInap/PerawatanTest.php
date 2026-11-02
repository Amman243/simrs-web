<?php

namespace Tests\Feature\RawatInap;

use App\Services\RawatInap\PerawatanService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class PerawatanTest extends RawatInapTestCase
{
    private string $noRawat = '2099/01/01/000001';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2099-01-02 10:00:00');

        DB::table('set_tarif')->update(['cara_bayar_ranap' => 'No', 'ruang_ranap' => 'No', 'kelas_ranap' => 'No']);
        $this->buatRegistrasi($this->noRawat, override: ['status_lanjut' => 'Ranap', 'stts' => 'Dirawat']);
        $this->buatRawatInap($this->noRawat, self::KAMAR_B, '2099-01-01', '10:00:00');
        DB::table('dpjp_ranap')->insert(['no_rawat' => $this->noRawat, 'kd_dokter' => 'D0000004']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tindakanKatalog(string $kolomTotal): object
    {
        return DB::table('jns_perawatan_inap')
            ->join('kategori_perawatan', 'jns_perawatan_inap.kd_kategori', '=', 'kategori_perawatan.kd_kategori')
            ->where('jns_perawatan_inap.status', '1')
            ->where("jns_perawatan_inap.$kolomTotal", '>', 0)
            ->orderBy('jns_perawatan_inap.kd_jenis_prw')
            ->first(['jns_perawatan_inap.*']);
    }

    private function komponen()
    {
        return Livewire::test('pages::rawat-inap.show', ['noRawat' => $this->noRawat]);
    }

    // ------------------------------------------------------------------ Halaman

    public function test_no_rawat_tidak_dikenal_404(): void
    {
        $this->actingAsAdmin()->get(route('rawat-inap.show', ['noRawat' => '2099/12/31/999999']))->assertNotFound();
    }

    public function test_halaman_perawatan_tampil(): void
    {
        $nama = DB::table('pasien')->join('reg_periksa', 'reg_periksa.no_rkm_medis', '=', 'pasien.no_rkm_medis')
            ->where('reg_periksa.no_rawat', $this->noRawat)->value('nm_pasien');

        $this->actingAsAdmin()->get(route('rawat-inap.show', ['noRawat' => $this->noRawat]))
            ->assertOk()
            ->assertSee($nama)
            ->assertSee(self::KAMAR_B)
            ->assertSee('dr. Hilyatul Nadia');
    }

    public function test_dokter_default_dari_dpjp(): void
    {
        $this->actingAsAdmin();
        $this->komponen()->assertSet('tDokter', 'D0000004');

        $this->assertSame('D0000004', app(PerawatanService::class)->dokterDefault($this->noRawat));
        DB::table('dpjp_ranap')->where('no_rawat', $this->noRawat)->delete();
        $this->assertSame('D0000003', app(PerawatanService::class)->dokterDefault($this->noRawat));
    }

    // ------------------------------------------------------------------ Katalog

    public function test_katalog_mengikuti_set_tarif(): void
    {
        $service = app(PerawatanService::class);
        DB::table('jns_perawatan_inap')->insert([
            'kd_jenis_prw' => 'TES.RANAP.1', 'nm_perawatan' => 'Tes Ranap Lain Bangsal', 'kd_kategori' => DB::table('kategori_perawatan')->value('kd_kategori'),
            'material' => 1000, 'bhp' => 0, 'tarif_tindakandr' => 9000, 'tarif_tindakanpr' => 0, 'kso' => 0, 'menejemen' => 0,
            'total_byrdr' => 10000, 'total_byrpr' => 0, 'total_byrdrpr' => 0, 'kd_pj' => '-', 'kd_bangsal' => 'ICU', 'status' => '1', 'kelas' => '-',
        ]);

        $this->assertTrue($service->katalog('dr', $this->noRawat, 'TES.RANAP.1')->isNotEmpty());
        $this->assertTrue($service->katalog('pr', $this->noRawat, 'TES.RANAP.1')->isEmpty(), 'tarif 0 disembunyikan');

        DB::table('set_tarif')->update(['ruang_ranap' => 'Yes']);
        $this->assertTrue($service->katalog('dr', $this->noRawat, 'TES.RANAP.1')->isEmpty(), 'bangsal ICU ≠ K3');

        DB::table('jns_perawatan_inap')->where('kd_jenis_prw', 'TES.RANAP.1')->update(['kd_bangsal' => '-', 'kelas' => 'Kelas 1']);
        $this->assertTrue($service->katalog('dr', $this->noRawat, 'TES.RANAP.1')->isNotEmpty());
        DB::table('set_tarif')->update(['kelas_ranap' => 'Yes']);
        $this->assertTrue($service->katalog('dr', $this->noRawat, 'TES.RANAP.1')->isEmpty(), 'kelas 1 ≠ Kelas 3');
    }

    // ------------------------------------------------------------------ Tindakan

    public function test_tambah_dan_hapus_tindakan_dokter(): void
    {
        $item = $this->tindakanKatalog('total_byrdr');
        $this->actingAsKhanza(['kamar_inap' => true, 'tindakan_ranap' => true]);

        $this->komponen()
            ->set('jenis', 'dr')
            ->set('tTgl', '2099-01-02')
            ->set('tJam', '09:00:00')
            ->set('pilihan', [$item->kd_jenis_prw])
            ->call('simpanTindakan')
            ->assertHasNoErrors();

        $row = DB::table('rawat_inap_dr')->where('no_rawat', $this->noRawat)->first();
        $this->assertNotNull($row);
        $this->assertSame($item->kd_jenis_prw, $row->kd_jenis_prw);
        $this->assertSame('D0000004', $row->kd_dokter);
        $this->assertSame('2099-01-02', $row->tgl_perawatan);
        $this->assertSame('09:00:00', $row->jam_rawat);
        $this->assertEquals($item->tarif_tindakandr, $row->tarif_tindakandr);
        $this->assertEquals($item->material, $row->material);
        $this->assertEquals($item->total_byrdr, $row->biaya_rawat);

        $this->komponen()
            ->set('jenis', 'dr')
            ->call('hapusTindakan', [
                'kd_jenis_prw' => $row->kd_jenis_prw, 'kd_dokter' => $row->kd_dokter,
                'tgl_perawatan' => $row->tgl_perawatan, 'jam_rawat' => $row->jam_rawat,
            ])
            ->assertDispatched('toast');

        $this->assertFalse(DB::table('rawat_inap_dr')->where('no_rawat', $this->noRawat)->exists());
    }

    public function test_tambah_tindakan_petugas_memakai_nip_user_petugas(): void
    {
        $item = $this->tindakanKatalog('total_byrpr');
        $this->actingAsKhanza(['kamar_inap' => true, 'tindakan_ranap' => true], nip: '156798');

        $this->komponen()
            ->assertSet('tNip', '156798')
            ->set('jenis', 'pr')
            ->set('tNip', '01010101') // dicoba diubah, server tetap memakai nip user
            ->set('pilihan', [$item->kd_jenis_prw])
            ->call('simpanTindakan')
            ->assertHasNoErrors();

        $row = DB::table('rawat_inap_pr')->where('no_rawat', $this->noRawat)->first();
        $this->assertSame('156798', $row->nip);
        $this->assertEquals($item->tarif_tindakanpr, $row->tarif_tindakanpr);
        $this->assertEquals($item->total_byrpr, $row->biaya_rawat);
    }

    public function test_tambah_tindakan_dokter_dan_petugas(): void
    {
        $item = $this->tindakanKatalog('total_byrdrpr');
        $this->actingAsAdmin();

        $this->komponen()
            ->set('jenis', 'drpr')
            ->set('tDokter', 'D0000005')
            ->set('tNip', '01010101')
            ->set('pilihan', [$item->kd_jenis_prw])
            ->call('simpanTindakan')
            ->assertHasNoErrors();

        $row = DB::table('rawat_inap_drpr')->where('no_rawat', $this->noRawat)->first();
        $this->assertSame('D0000005', $row->kd_dokter);
        $this->assertSame('01010101', $row->nip);
        $this->assertEquals($item->total_byrdrpr, $row->biaya_rawat);
    }

    public function test_tindakan_di_luar_katalog_ditolak(): void
    {
        $this->actingAsAdmin();

        $this->komponen()
            ->set('pilihan', ['TIDAK-ADA'])
            ->call('simpanTindakan')
            ->assertHasErrors('pilihan');

        $this->assertFalse(DB::table('rawat_inap_dr')->where('no_rawat', $this->noRawat)->exists());
    }

    public function test_hapus_tindakan_lebih_48_jam_ditolak_untuk_non_admin(): void
    {
        $item = $this->tindakanKatalog('total_byrdr');
        $lama = [
            'no_rawat' => $this->noRawat, 'kd_jenis_prw' => $item->kd_jenis_prw, 'kd_dokter' => 'D0000004',
            'tgl_perawatan' => '2098-12-30', 'jam_rawat' => '09:00:00', 'material' => 0, 'bhp' => 0,
            'tarif_tindakandr' => 0, 'kso' => 0, 'menejemen' => 0, 'biaya_rawat' => 0,
        ];
        DB::table('rawat_inap_dr')->insert($lama);
        $kunci = array_intersect_key($lama, array_flip(['kd_jenis_prw', 'kd_dokter', 'tgl_perawatan', 'jam_rawat']));

        $this->actingAsKhanza(['kamar_inap' => true, 'tindakan_ranap' => true]);
        $this->komponen()->call('hapusTindakan', $kunci)->assertDispatched('toast', type: 'error');
        $this->assertTrue(DB::table('rawat_inap_dr')->where($lama)->exists());

        // Kunci tidak lengkap tidak boleh menghapus apa pun, bahkan untuk admin.
        $this->actingAsAdmin();
        $this->komponen()->call('hapusTindakan', ['kd_jenis_prw' => $item->kd_jenis_prw])->assertDispatched('toast', type: 'error');
        $this->assertTrue(DB::table('rawat_inap_dr')->where($lama)->exists());

        $this->actingAsAdmin();
        $this->komponen()->call('hapusTindakan', $kunci);
        $this->assertFalse(DB::table('rawat_inap_dr')->where('no_rawat', $this->noRawat)->exists());
    }

    public function test_tindakan_ditolak_saat_terkunci_atau_tanpa_hak(): void
    {
        $item = $this->tindakanKatalog('total_byrdr');

        $this->actingAsKhanza(['kamar_inap' => true]);
        $this->komponen()->set('pilihan', [$item->kd_jenis_prw])->call('simpanTindakan')->assertForbidden();

        DB::table('billing')->insert([
            'noindex' => 1, 'no_rawat' => $this->noRawat, 'tgl_byr' => '2099-01-02', 'no' => '-', 'nm_perawatan' => 'Tes',
            'pemisah' => '-', 'biaya' => 0, 'jumlah' => 0, 'tambahan' => 0, 'totalbiaya' => 0, 'status' => '-',
        ]);
        $this->actingAsAdmin();
        $this->komponen()
            ->assertSee('Hanya baca')
            ->set('pilihan', [$item->kd_jenis_prw])
            ->call('simpanTindakan')
            ->assertHasErrors('pilihan');

        $this->assertFalse(DB::table('rawat_inap_dr')->where('no_rawat', $this->noRawat)->exists());
    }

    // ------------------------------------------------------------------ SOAP

    public function test_simpan_soap_oleh_petugas(): void
    {
        $this->actingAsKhanza(['kamar_inap' => true, 'tindakan_ranap' => true], nip: '156798');

        $this->komponen()
            ->set('tab', 'soap')
            ->set('soapTgl', '2099-01-02')
            ->set('soapJam', '08:15:00')
            ->set('soap.suhu_tubuh', '38.5')
            ->set('soap.tensi', '120/80')
            ->set('soap.nadi', '88')
            ->set('soap.kesadaran', 'Compos Mentis')
            ->set('soap.keluhan', 'Demam 3 hari')
            ->set('soap.penilaian', 'Suspek DHF')
            ->set('soap.rtl', 'Cek trombosit')
            ->call('simpanSoap')
            ->assertHasNoErrors();

        $row = DB::table('pemeriksaan_ranap')->where('no_rawat', $this->noRawat)->first();
        $this->assertNotNull($row);
        $this->assertSame('2099-01-02', $row->tgl_perawatan);
        $this->assertSame('08:15:00', $row->jam_rawat);
        $this->assertSame('38.5', $row->suhu_tubuh);
        $this->assertSame('120/80', $row->tensi);
        $this->assertSame('Demam 3 hari', $row->keluhan);
        $this->assertSame('Suspek DHF', $row->penilaian);
        $this->assertSame('Cek trombosit', $row->rtl);
        $this->assertSame('', $row->instruksi);
        $this->assertSame('156798', $row->nip);
    }

    public function test_ubah_dan_hapus_soap_hanya_milik_sendiri(): void
    {
        DB::table('pemeriksaan_ranap')->insert([
            'no_rawat' => $this->noRawat, 'tgl_perawatan' => '2099-01-02', 'jam_rawat' => '07:00:00',
            'suhu_tubuh' => '37', 'tensi' => '110/70', 'nadi' => '80', 'respirasi' => '20', 'tinggi' => '', 'berat' => '',
            'spo2' => '98', 'gcs' => '15', 'kesadaran' => 'Compos Mentis', 'keluhan' => 'Pusing', 'pemeriksaan' => '',
            'alergi' => '', 'penilaian' => '', 'rtl' => '', 'instruksi' => '', 'evaluasi' => '', 'nip' => '01010101',
        ]);
        $pk = ['no_rawat' => $this->noRawat, 'tgl_perawatan' => '2099-01-02', 'jam_rawat' => '07:00:00'];

        // Petugas lain tidak boleh menghapus.
        $this->actingAsKhanza(['kamar_inap' => true, 'tindakan_ranap' => true], nip: '156798');
        $this->komponen()->call('hapusSoap', '2099-01-02', '07:00:00')->assertDispatched('toast', type: 'error');
        $this->assertTrue(DB::table('pemeriksaan_ranap')->where($pk)->exists());

        // Pemilik boleh mengubah lalu menghapus.
        $this->actingAsKhanza(['kamar_inap' => true, 'tindakan_ranap' => true], nip: '01010101');
        $this->komponen()
            ->call('ubahSoap', '2099-01-02', '07:00:00')
            ->assertSet('soap.keluhan', 'Pusing')
            ->set('soap.keluhan', 'Pusing berkurang')
            ->call('simpanSoap')
            ->assertHasNoErrors();
        $this->assertSame('Pusing berkurang', DB::table('pemeriksaan_ranap')->where($pk)->value('keluhan'));

        $this->komponen()->call('hapusSoap', '2099-01-02', '07:00:00');
        $this->assertFalse(DB::table('pemeriksaan_ranap')->where($pk)->exists());
    }

    // ------------------------------------------------------------------ DPJP

    public function test_tambah_dan_hapus_dpjp(): void
    {
        $this->actingAsKhanza(['kamar_inap' => true, 'dpjp_ranap' => true]);

        $this->komponen()->set('dpjpBaru', 'D0000002')->call('tambahDpjp')->assertHasNoErrors();
        $this->assertTrue(DB::table('dpjp_ranap')->where(['no_rawat' => $this->noRawat, 'kd_dokter' => 'D0000002'])->exists());

        $this->komponen()->call('hapusDpjp', 'D0000004');
        $this->assertFalse(DB::table('dpjp_ranap')->where(['no_rawat' => $this->noRawat, 'kd_dokter' => 'D0000004'])->exists());

        $this->actingAsKhanza(['kamar_inap' => true]);
        $this->komponen()->set('dpjpBaru', 'D0000005')->call('tambahDpjp')->assertForbidden();
    }
}
