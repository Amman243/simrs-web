<?php

namespace Tests\Feature\RawatInap;

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Render semua tampilan dengan data dev yang ada (termasuk data lama yang tidak rapi).
 */
class HalamanTest extends RawatInapTestCase
{
    public function test_daftar_semua_mode_dan_ketersediaan_kamar(): void
    {
        $this->actingAsAdmin();

        Livewire::test('pages::rawat-inap.index')
            ->assertSee('Masih dirawat')
            ->set('tampilKamar', true)
            ->assertSee('Ketersediaan kamar')
            ->assertSee(self::KAMAR_A)
            ->set('mode', 'masuk')
            ->set('dari', '2026-01-01')
            ->set('sampai', '2026-12-31')
            ->assertSee('Pindah Kamar')
            ->set('mode', 'pulang')
            ->assertSee('Meninggal')
            ->set('bangsal', 'K3')
            ->set('cari', 'zzz-tidak-ada')
            ->assertSee('Tidak ada pasien rawat inap');
    }

    public function test_perawatan_semua_tab_untuk_data_dev(): void
    {
        $this->actingAsAdmin();
        $noRawat = DB::table('pemeriksaan_ranap')->value('no_rawat') ?? DB::table('kamar_inap')->value('no_rawat');

        $komponen = Livewire::test('pages::rawat-inap.show', ['noRawat' => $noRawat])->assertOk();
        foreach (['dr', 'pr', 'drpr'] as $jenis) {
            $komponen->set('jenis', $jenis)->assertOk();
        }
        $komponen->set('tab', 'soap')->assertSee('Pemeriksaan');
    }

    public function test_perawatan_registrasi_tanpa_kamar_inap(): void
    {
        $reg = $this->buatRegistrasi('2099/01/01/000001');

        $this->actingAsAdmin()->get(route('rawat-inap.show', ['noRawat' => $reg->no_rawat]))
            ->assertOk()
            ->assertSee('belum masuk kamar inap');
    }

    public function test_halaman_dokter_non_admin(): void
    {
        $this->actingAsKhanza(['kamar_inap' => true, 'tindakan_ranap' => true], kdDokter: 'D0000004');

        $this->get(route('rawat-inap.index'))->assertOk()->assertSee('Pasien saya');
        $this->get(route('rawat-inap.masuk'))->assertOk()->assertSee('Pilih registrasi');
    }
}
