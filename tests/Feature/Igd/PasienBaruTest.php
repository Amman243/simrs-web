<?php

namespace Tests\Feature\Igd;

use App\Services\Igd\PasienBaruService;
use App\Services\Khanza\Umur;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PasienBaruTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aturNomor('Straight', 'No', 'No', 'Depan');
        DB::table('set_no_rkm_medis')->delete();
        DB::table('set_no_rkm_medis')->insert(['no_rkm_medis' => '900000']);
    }

    public function test_halaman_butuh_hak_pasien(): void
    {
        $this->actingAsKhanza(['igd' => true])->get(route('igd.pasien-baru'))->assertForbidden();
        $this->actingAsKhanza(['igd' => true, 'pasien' => true])->get(route('igd.pasien-baru'))->assertOk()->assertSee('900001');
    }

    public function test_simpan_pasien_baru_lalu_kembali_ke_registrasi_igd(): void
    {
        $this->actingAsKhanza(['igd' => true, 'pasien' => true]);
        $kdPj = DB::table('penjab')->where('status', '1')->value('kd_pj');

        Livewire::test('pages::igd.pasien-baru')
            ->set('nm_pasien', 'PASIEN UJI IGD')
            ->set('no_ktp', '3201010101010001')
            ->set('jk', 'P')
            ->set('tmp_lahir', 'BANDUNG')
            ->set('tgl_lahir', '1990-05-17')
            ->set('nm_ibu', 'IBU UJI')
            ->set('alamat', 'JL. MAWAR 1')
            ->set('kelurahan', 'KELURAHAN UJI IGD')
            ->set('kecamatan', 'KECAMATAN UJI IGD')
            ->set('kabupaten', 'KABUPATEN UJI IGD')
            ->set('propinsi', 'PROPINSI UJI IGD')
            ->set('kd_pj', $kdPj)
            ->set('keluarga', 'SUAMI')
            ->set('namakeluarga', 'SUAMI UJI')
            ->call('simpan')
            ->assertHasNoErrors()
            ->assertRedirect(route('igd.index', ['pasien' => '900001']));

        $p = DB::table('pasien')->where('no_rkm_medis', '900001')->first();
        $this->assertNotNull($p);
        $this->assertSame('PASIEN UJI IGD', $p->nm_pasien);
        $this->assertSame('P', $p->jk);
        $this->assertSame('1990-05-17', $p->tgl_lahir);
        $this->assertSame(today()->toDateString(), $p->tgl_daftar);
        $this->assertSame(Umur::teks('1990-05-17'), $p->umur);
        $this->assertSame($kdPj, $p->kd_pj);
        $this->assertSame('SUAMI', $p->keluarga);
        $this->assertSame('SUAMI UJI', $p->namakeluarga);
        $this->assertSame('-', $p->pekerjaan);

        // Wilayah dibuat lewat insert ignore lalu dicari berdasarkan nama.
        $this->assertSame('KELURAHAN UJI IGD', DB::table('kelurahan')->where('kd_kel', $p->kd_kel)->value('nm_kel'));
        $this->assertSame('KECAMATAN UJI IGD', DB::table('kecamatan')->where('kd_kec', $p->kd_kec)->value('nm_kec'));
        $this->assertSame('KABUPATEN UJI IGD', DB::table('kabupaten')->where('kd_kab', $p->kd_kab)->value('nm_kab'));
        $this->assertSame('PROPINSI UJI IGD', DB::table('propinsi')->where('kd_prop', $p->kd_prop)->value('nm_prop'));
        // Alamat P.J. disamakan dengan pasien (default form).
        $this->assertSame('JL. MAWAR 1', $p->alamatpj);
        $this->assertSame('KELURAHAN UJI IGD', $p->kelurahanpj);

        // Default baris '-' untuk master wajib.
        $this->assertSame((int) DB::table('suku_bangsa')->where('nama_suku_bangsa', '-')->value('id'), (int) $p->suku_bangsa);
        $this->assertSame('-', $p->perusahaan_pasien);

        // set_no_rkm_medis diganti dengan nomor terakhir (satu baris).
        $this->assertSame(['900001'], DB::table('set_no_rkm_medis')->pluck('no_rkm_medis')->all());
    }

    public function test_wilayah_kosong_memakai_baris_strip(): void
    {
        $noRm = app(PasienBaruService::class)->simpan(['nm_pasien' => 'TANPA ALAMAT', 'kd_pj' => '-', 'tgl_lahir' => '2000-01-01']);

        $p = DB::table('pasien')->where('no_rkm_medis', $noRm)->first();
        $this->assertSame('-', DB::table('kelurahan')->where('kd_kel', $p->kd_kel)->value('nm_kel'));
        $this->assertSame('-', DB::table('propinsi')->where('kd_prop', $p->kd_prop)->value('nm_prop'));
        $this->assertSame('-', $p->nm_ibu);
        $this->assertSame('DIRI SENDIRI', $p->keluarga);
    }

    public function test_validasi_nama_dan_cara_bayar_wajib(): void
    {
        $this->actingAsKhanza(['igd' => true, 'pasien' => true]);

        Livewire::test('pages::igd.pasien-baru')
            ->set('tgl_lahir', '2000-01-01')
            ->call('simpan')
            ->assertHasErrors(['nm_pasien' => 'required', 'kd_pj' => 'required']);

        $this->assertFalse(DB::table('pasien')->where('no_rkm_medis', '900001')->exists());
    }

    public function test_simpan_butuh_hak_pasien(): void
    {
        $this->actingAsKhanza(['igd' => true]);

        Livewire::test('pages::igd.pasien-baru')
            ->set('nm_pasien', 'X')
            ->set('kd_pj', '-')
            ->set('tgl_lahir', '2000-01-01')
            ->call('simpan')
            ->assertForbidden();
    }

    public function test_nomor_dicoba_ulang_bila_sudah_dipakai(): void
    {
        // set_no_rkm_medis tertinggal: nomor berikutnya (900001) sudah dipakai pasien lain.
        app(PasienBaruService::class)->simpan(['nm_pasien' => 'PERTAMA', 'kd_pj' => '-', 'tgl_lahir' => '2000-01-01']);
        DB::table('set_no_rkm_medis')->update(['no_rkm_medis' => '900000']);

        $noRm = app(PasienBaruService::class)->simpan(['nm_pasien' => 'KEDUA', 'kd_pj' => '-', 'tgl_lahir' => '2000-01-01']);

        $this->assertSame('900002', $noRm);
        $this->assertSame('KEDUA', DB::table('pasien')->where('no_rkm_medis', '900002')->value('nm_pasien'));
    }

    public function test_format_nomor_rm_sesuai_setting(): void
    {
        $svc = app(PasienBaruService::class);
        $tgl = Carbon::parse('2026-11-01');

        DB::table('set_no_rkm_medis')->update(['no_rkm_medis' => '000064']);
        $this->assertSame('000065', $svc->nomorBerikutnya($tgl));

        $this->aturNomor('Straight', 'Yes', 'Yes', 'Depan');
        DB::table('set_no_rkm_medis')->update(['no_rkm_medis' => '2610000064']);
        $this->assertSame('2611000065', $svc->nomorBerikutnya($tgl));

        $this->aturNomor('Straight', 'Yes', 'Yes', 'Belakang');
        DB::table('set_no_rkm_medis')->update(['no_rkm_medis' => '000064-1026']);
        $this->assertSame('000065-1126', $svc->nomorBerikutnya($tgl));

        $this->aturNomor('Straight', 'No', 'Yes', 'Belakang');
        $this->assertSame('000065-11', $svc->nomorBerikutnya($tgl));

        // Terminal: 123456 disimpan sebagai 563412 → berikutnya 123457 → 573412.
        $this->aturNomor('Terminal', 'No', 'No', 'Depan');
        DB::table('set_no_rkm_medis')->update(['no_rkm_medis' => '563412']);
        $this->assertSame('573412', $svc->nomorBerikutnya($tgl));

        // Middle: 123456 disimpan sebagai 341256 → berikutnya 123457 → 341257.
        $this->aturNomor('Middle', 'No', 'No', 'Depan');
        DB::table('set_no_rkm_medis')->update(['no_rkm_medis' => '341256']);
        $this->assertSame('341257', $svc->nomorBerikutnya($tgl));
    }

    private function aturNomor(string $urutan, string $tahun, string $bulan, string $posisi): void
    {
        DB::table('set_urut_no_rkm_medis')->delete();
        DB::table('set_urut_no_rkm_medis')->insert([
            'urutan' => $urutan, 'tahun' => $tahun, 'bulan' => $bulan, 'posisi_tahun_bulan' => $posisi,
        ]);
    }
}
