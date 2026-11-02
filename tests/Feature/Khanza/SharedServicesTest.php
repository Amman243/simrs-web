<?php

namespace Tests\Feature\Khanza;

use App\Services\Khanza\NomorRawat;
use App\Services\Khanza\Registrasi;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SharedServicesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_no_rawat_melanjutkan_counter_per_tanggal(): void
    {
        $tanggal = Carbon::parse('2099-01-02');
        $this->assertSame('2099/01/02/000001', NomorRawat::noRawat($tanggal));

        $this->insertReg('2099/01/02/000007', $tanggal, '005');

        $this->assertSame('2099/01/02/000008', NomorRawat::noRawat($tanggal));
    }

    public function test_no_reg_per_dokter_dan_poli(): void
    {
        config(['khanza.urut_no_reg' => 'dokter + poli']);
        $tanggal = Carbon::parse('2099-01-02');
        $reg = $this->insertReg('2099/01/02/000001', $tanggal, '004');

        $this->assertSame('005', NomorRawat::noReg($reg->kd_dokter, $reg->kd_poli, $tanggal));
        $this->assertSame('001', NomorRawat::noReg($reg->kd_dokter, 'XXXXX', $tanggal));
    }

    public function test_registrasi_terkunci_bila_batal(): void
    {
        $reg = $this->insertReg('2099/01/02/000001', Carbon::parse('2099-01-02'), '001');
        $this->assertFalse(Registrasi::terkunci($reg->no_rawat));

        DB::table('reg_periksa')->where('no_rawat', $reg->no_rawat)->update(['stts' => 'Batal']);
        $this->assertTrue(Registrasi::terkunci($reg->no_rawat));
    }

    public function test_sedang_dirawat_inap_sesuai_data(): void
    {
        $rm = DB::table('kamar_inap')
            ->join('reg_periksa', 'reg_periksa.no_rawat', '=', 'kamar_inap.no_rawat')
            ->where('kamar_inap.stts_pulang', '-')
            ->value('reg_periksa.no_rkm_medis');

        if ($rm) {
            $this->assertTrue(Registrasi::sedangDirawatInap($rm));
        }
        $this->assertFalse(Registrasi::sedangDirawatInap('TIDAK-ADA'));
    }

    private function insertReg(string $noRawat, Carbon $tanggal, string $noReg): object
    {
        $template = (array) DB::table('reg_periksa')->first();
        $row = array_merge($template, [
            'no_rawat' => $noRawat,
            'no_reg' => $noReg,
            'tgl_registrasi' => $tanggal->toDateString(),
            'stts' => 'Belum',
        ]);
        DB::table('reg_periksa')->insert($row);

        return (object) $row;
    }
}
