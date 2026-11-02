<?php

namespace Tests\Feature\RawatJalan;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class PemeriksaanTest extends RawatJalanTestCase
{
    public function test_dokter_simpan_ubah_hapus_soap(): void
    {
        $reg = $this->buatRegistrasi();
        $this->actingAsKhanza(['tindakan_ralan' => true], kdDokter: self::DOKTER);

        $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])
            ->set('tab', 'soap')
            ->set('soapTgl', today()->toDateString())
            ->set('soapJam', '08:30')
            ->set('soap.suhu_tubuh', '36.8')
            ->set('soap.tensi', '120/80')
            ->set('soap.nadi', '80')
            ->set('soap.spo2', '98')
            ->set('soap.kesadaran', 'Alert')
            ->set('soap.keluhan', 'Demam 3 hari')
            ->set('soap.penilaian', 'Febris')
            ->set('soap.rtl', 'Paracetamol')
            ->set('soap.lingkar_perut', '80')
            ->call('simpanSoap')
            ->assertHasNoErrors();
        $this->assertToast($lw, 'success');

        $row = DB::table('pemeriksaan_ralan')->where('no_rawat', $reg->no_rawat)->first();
        $this->assertNotNull($row);
        $this->assertSame(self::DOKTER, $row->nip);
        $this->assertSame('08:30:00', $row->jam_rawat);
        $this->assertSame('Alert', $row->kesadaran);
        $this->assertSame('Febris', $row->penilaian);
        $this->assertSame('Paracetamol', $row->rtl);
        $this->assertSame('80', $row->lingkar_perut);
        $this->assertSame('', $row->instruksi);
        $this->assertSame('', $row->evaluasi);

        // Edit (termasuk pindah jam).
        $lw->call('editSoap', $row->tgl_perawatan, $row->jam_rawat)
            ->assertSet('soap.keluhan', 'Demam 3 hari')
            ->set('soap.keluhan', 'Demam 4 hari')
            ->set('soapJam', '08:45:00')
            ->call('simpanSoap')
            ->assertHasNoErrors()
            ->assertSet('editKunci', null);

        $this->assertSame(1, DB::table('pemeriksaan_ralan')->where('no_rawat', $reg->no_rawat)->count());
        $baru = DB::table('pemeriksaan_ralan')->where('no_rawat', $reg->no_rawat)->first();
        $this->assertSame('Demam 4 hari', $baru->keluhan);
        $this->assertSame('08:45:00', $baru->jam_rawat);

        $lw->call('hapusSoap', $baru->tgl_perawatan, $baru->jam_rawat);
        $this->assertFalse(DB::table('pemeriksaan_ralan')->where('no_rawat', $reg->no_rawat)->exists());
    }

    public function test_user_bukan_pegawai_ditolak(): void
    {
        $reg = $this->buatRegistrasi();
        $this->actingAsKhanza(['tindakan_ralan' => true], kode: 'BUKANPEGAWAI');

        $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])
            ->set('tab', 'soap')
            ->assertSee('User login bukan pegawai/petugas')
            ->set('soap.keluhan', 'Batuk')
            ->call('simpanSoap');

        $this->assertDispatchedMessage($lw, 'User login bukan pegawai/petugas.');
        $this->assertFalse(DB::table('pemeriksaan_ralan')->where('no_rawat', $reg->no_rawat)->exists());
    }

    public function test_soap_kosong_ditolak(): void
    {
        $reg = $this->buatRegistrasi();
        $this->actingAsKhanza(['tindakan_ralan' => true], kdDokter: self::DOKTER);

        $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])->call('simpanSoap');

        $this->assertToast($lw, 'error');
        $this->assertFalse(DB::table('pemeriksaan_ralan')->where('no_rawat', $reg->no_rawat)->exists());
    }

    public function test_admin_memilih_pemeriksa(): void
    {
        $reg = $this->buatRegistrasi();
        $this->actingAsAdmin();

        Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])
            ->assertSet('soapNip', self::DOKTER)
            ->set('soapNip', '')
            ->set('soap.keluhan', 'Pusing')
            ->call('simpanSoap')
            ->assertHasErrors(['soapNip'])
            ->set('soapNip', self::PETUGAS)
            ->call('simpanSoap')
            ->assertHasNoErrors();

        $this->assertSame(self::PETUGAS, DB::table('pemeriksaan_ralan')->where('no_rawat', $reg->no_rawat)->value('nip'));
    }

    public function test_tidak_boleh_ubah_atau_hapus_milik_orang_lain(): void
    {
        $reg = $this->buatRegistrasi();
        $pk = $this->insertSoap($reg->no_rawat, self::DOKTER_LAIN, now()->subHour());
        $this->actingAsKhanza(['tindakan_ralan' => true], kdDokter: self::DOKTER);

        $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])
            ->call('editSoap', $pk['tgl_perawatan'], $pk['jam_rawat'])
            ->assertSet('editKunci', null)
            ->call('hapusSoap', $pk['tgl_perawatan'], $pk['jam_rawat']);

        $this->assertToast($lw, 'error');
        $this->assertTrue(DB::table('pemeriksaan_ralan')->where($pk)->exists());
    }

    public function test_non_admin_dibatasi_48_jam_admin_tidak(): void
    {
        $reg = $this->buatRegistrasi(['tgl_registrasi' => today()->subDays(5)->toDateString()]);
        $pk = $this->insertSoap($reg->no_rawat, self::DOKTER, now()->subHours(50));
        $this->actingAsKhanza(['tindakan_ralan' => true], kdDokter: self::DOKTER);

        $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])
            ->call('hapusSoap', $pk['tgl_perawatan'], $pk['jam_rawat']);
        $this->assertToast($lw, 'error');
        $this->assertTrue(DB::table('pemeriksaan_ralan')->where($pk)->exists());

        $this->actingAsAdmin();
        Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])
            ->call('hapusSoap', $pk['tgl_perawatan'], $pk['jam_rawat']);
        $this->assertFalse(DB::table('pemeriksaan_ralan')->where($pk)->exists());
    }

    public function test_soap_ditolak_bila_terkunci(): void
    {
        $reg = $this->buatRegistrasi();
        $pk = $this->insertSoap($reg->no_rawat, self::DOKTER, now()->subHour());
        $this->kunciBilling($reg->no_rawat);
        $this->actingAsAdmin();

        $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])
            ->set('soap.keluhan', 'Baru')
            ->set('soapJam', '23:59:59')
            ->call('simpanSoap')
            ->call('hapusSoap', $pk['tgl_perawatan'], $pk['jam_rawat']);

        $this->assertToast($lw, 'error');
        $this->assertSame(1, DB::table('pemeriksaan_ralan')->where('no_rawat', $reg->no_rawat)->count());
    }

    public function test_daftar_soap_terbaru_di_atas_dengan_nama_pegawai(): void
    {
        $reg = $this->buatRegistrasi();
        $this->insertSoap($reg->no_rawat, self::DOKTER, now()->subHours(3), 'Keluhan lama');
        $this->insertSoap($reg->no_rawat, self::PETUGAS, now()->subHour(), 'Keluhan baru');
        $this->actingAsAdmin();

        Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])
            ->set('tab', 'soap')
            ->assertSeeInOrder(['Keluhan baru', 'Keluhan lama'])
            ->assertSee(DB::table('pegawai')->where('nik', self::PETUGAS)->value('nama'));
    }

    /**
     * @return array<string, string>
     */
    private function insertSoap(string $noRawat, string $nip, Carbon $waktu, string $keluhan = 'Tes'): array
    {
        $pk = ['no_rawat' => $noRawat, 'tgl_perawatan' => $waktu->toDateString(), 'jam_rawat' => $waktu->format('H:i:s')];

        DB::table('pemeriksaan_ralan')->insert($pk + [
            'suhu_tubuh' => '', 'tensi' => '', 'nadi' => '', 'respirasi' => '', 'tinggi' => '', 'berat' => '',
            'spo2' => '', 'gcs' => '', 'kesadaran' => 'Compos Mentis', 'keluhan' => $keluhan, 'pemeriksaan' => '',
            'alergi' => '', 'lingkar_perut' => '', 'rtl' => '', 'penilaian' => '', 'instruksi' => '', 'evaluasi' => '',
            'nip' => $nip,
        ]);

        return $pk;
    }

    private function assertDispatchedMessage($lw, string $message): void
    {
        $lw->assertDispatched('toast', fn ($name, $params) => ($params['message'] ?? null) === $message);
    }
}
