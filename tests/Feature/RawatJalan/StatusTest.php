<?php

namespace Tests\Feature\RawatJalan;

use App\Services\RawatJalan\AturanException;
use App\Services\RawatJalan\StatusService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class StatusTest extends RawatJalanTestCase
{
    public function test_sudah_mengubah_status_dan_mutasi_berkas(): void
    {
        $reg = $this->buatRegistrasi();
        $this->actingAsAdmin();

        $lw = Livewire::test('pages::rawat-jalan.index')->call('ubahStatus', $reg->no_rawat, 'Sudah');
        $this->assertToast($lw, 'success');

        $this->assertSame('Sudah', $this->reg($reg->no_rawat)->stts);
        $this->assertSame('Sudah Kembali', DB::table('mutasi_berkas')->where('no_rawat', $reg->no_rawat)->value('status'));

        // Kedua kali: update baris mutasi_berkas yang sama (upsert).
        app(StatusService::class)->ubah($reg->no_rawat, 'Sudah');
        $this->assertSame(1, DB::table('mutasi_berkas')->where('no_rawat', $reg->no_rawat)->count());
    }

    public function test_belum(): void
    {
        $reg = $this->buatRegistrasi(['stts' => 'Sudah']);

        app(StatusService::class)->ubah($reg->no_rawat, 'Belum');

        $this->assertSame('Belum', $this->reg($reg->no_rawat)->stts);
    }

    public function test_batal_menolkan_biaya_registrasi(): void
    {
        $reg = $this->buatRegistrasi(['biaya_reg' => 25000]);

        app(StatusService::class)->ubah($reg->no_rawat, 'Batal');

        $row = $this->reg($reg->no_rawat);
        $this->assertSame('Batal', $row->stts);
        $this->assertEquals(0, $row->biaya_reg);
    }

    public function test_ditolak_bila_sudah_masuk_kamar_inap(): void
    {
        $reg = $this->buatRegistrasi();
        $this->masukKamarInap($reg->no_rawat);
        $this->actingAsAdmin();

        foreach (['Sudah', 'Belum', 'Batal'] as $status) {
            $lw = Livewire::test('pages::rawat-jalan.index')->call('ubahStatus', $reg->no_rawat, $status);
            $this->assertToast($lw, 'error');
        }

        $this->assertSame('Belum', $this->reg($reg->no_rawat)->stts);
    }

    public function test_batal_ditolak_bila_billing_terkunci(): void
    {
        $reg = $this->buatRegistrasi();
        $this->kunciBilling($reg->no_rawat);

        try {
            app(StatusService::class)->ubah($reg->no_rawat, 'Batal');
            $this->fail('Batal seharusnya ditolak');
        } catch (AturanException) {
        }

        $this->assertSame('Belum', $this->reg($reg->no_rawat)->stts);

        // Seperti Khanza, Sudah tetap boleh walau billing ada.
        app(StatusService::class)->ubah($reg->no_rawat, 'Sudah');
        $this->assertSame('Sudah', $this->reg($reg->no_rawat)->stts);
    }

    public function test_status_tidak_dikenal_ditolak(): void
    {
        $reg = $this->buatRegistrasi();

        $this->expectException(AturanException::class);
        app(StatusService::class)->ubah($reg->no_rawat, 'Dirawat');
    }

    public function test_ubah_status_butuh_hak_akses(): void
    {
        $reg = $this->buatRegistrasi();
        $this->actingAsKhanza(['tindakan_ralan' => true]);
        $lw = Livewire::test('pages::rawat-jalan.index');

        // Hak dicabut setelah halaman terbuka.
        $this->actingAsKhanza([]);
        $lw->call('ubahStatus', $reg->no_rawat, 'Sudah')->assertForbidden();

        $this->assertSame('Belum', $this->reg($reg->no_rawat)->stts);
    }

    public function test_selesai_diperiksa_dari_halaman_kunjungan(): void
    {
        $reg = $this->buatRegistrasi();
        $this->actingAsAdmin();

        Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])->call('selesai');

        $this->assertSame('Sudah', $this->reg($reg->no_rawat)->stts);
    }

    public function test_selesai_diperiksa_ditolak_bila_terkunci_atau_kamar_inap(): void
    {
        $terkunci = $this->buatRegistrasi();
        $this->kunciBilling($terkunci->no_rawat);
        $ranap = $this->buatRegistrasi();
        $this->masukKamarInap($ranap->no_rawat);
        $this->actingAsAdmin();

        foreach ([$terkunci, $ranap] as $reg) {
            $lw = Livewire::test('pages::rawat-jalan.show', ['noRawat' => $reg->no_rawat])->call('selesai');
            $this->assertToast($lw, 'error');
            $this->assertSame('Belum', $this->reg($reg->no_rawat)->stts);
        }
    }

    private function reg(string $noRawat): object
    {
        return DB::table('reg_periksa')->where('no_rawat', $noRawat)->first();
    }
}
