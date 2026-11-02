<?php

namespace App\Services\RawatJalan;

use App\Services\Khanza\Registrasi;
use Illuminate\Support\Facades\DB;

/**
 * Ubah reg_periksa.stts dari worklist rawat jalan (DlgKasirRalan).
 *
 * - Sudah (MnSudah 7723-7739): stts='Sudah' + upsert mutasi_berkas 'Sudah Kembali'.
 * - Belum (MnBelum 7741-7755): stts='Belum'.
 * - Batal (MnBatal 8247-8264): stts='Batal', biaya_reg=0; diblokir bila cariRegistrasi > 0.
 * Semua ditolak bila sudah ada baris kamar_inap.
 */
class StatusService
{
    public const DIIZINKAN = ['Sudah', 'Belum', 'Batal'];

    public function ubah(string $noRawat, string $status): void
    {
        if (! in_array($status, self::DIIZINKAN, true)) {
            throw new AturanException('Status tidak dikenal.');
        }

        if (! DB::table('reg_periksa')->where('no_rawat', $noRawat)->exists()) {
            throw new AturanException('Data registrasi tidak ditemukan.');
        }

        if (Registrasi::punyaKamarInap($noRawat)) {
            throw new AturanException('Maaf, pasien sudah masuk kamar inap. Gunakan billing ranap.');
        }

        if ($status === 'Batal' && Registrasi::terkunci($noRawat)) {
            throw new AturanException('Data billing sudah terverifikasi, registrasi tidak bisa dibatalkan.');
        }

        DB::transaction(function () use ($noRawat, $status) {
            $kolom = ['stts' => $status];
            if ($status === 'Batal') {
                $kolom['biaya_reg'] = 0;
            }

            DB::table('reg_periksa')->where('no_rawat', $noRawat)->update($kolom);

            if ($status === 'Sudah') {
                $this->berkasKembali($noRawat);
            }
        });
    }

    /**
     * Sequel.menyimpan("mutasi_berkas", ..., "status='Sudah Kembali',kembali=now()"):
     * insert baru, atau update bila no_rawat sudah ada.
     */
    private function berkasKembali(string $noRawat): void
    {
        $sekarang = now()->toDateTimeString();
        $nol = '0000-00-00 00:00:00';

        if (DB::table('mutasi_berkas')->where('no_rawat', $noRawat)->exists()) {
            DB::table('mutasi_berkas')->where('no_rawat', $noRawat)->update([
                'status' => 'Sudah Kembali',
                'kembali' => $sekarang,
            ]);

            return;
        }

        DB::table('mutasi_berkas')->insert([
            'no_rawat' => $noRawat,
            'status' => 'Sudah Kembali',
            'dikirim' => $sekarang,
            'diterima' => $nol,
            'kembali' => $sekarang,
            'tidakada' => $nol,
            'ranap' => $nol,
        ]);
    }
}
