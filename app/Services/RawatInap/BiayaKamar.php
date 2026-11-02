<?php

namespace App\Services\RawatInap;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Lama inap & biaya kamar gaya Khanza (docs/khanza/03-rawat-inap.md bagian 2).
 *
 * Rumus DlgKamarInap (5614-5618, 5995-5999):
 *   IF(TO_DAYS(keluar)-TO_DAYS(masuk)=0,
 *      IF(TIME_TO_SEC(keluar)-TIME_TO_SEC(masuk) > 3600*lamajam, 1, 0),
 *      TO_DAYS(keluar)-TO_DAYS(masuk))  [+1 bila hariawal='Yes']
 * ttl_biaya = lama * trf_kamar, dibulatkan seperti DecimalFormat("####") (HALF_EVEN).
 */
class BiayaKamar
{
    /** @var array{lamajam: int, hariawal: string, diagnosaakhir: string}|null */
    private ?array $pengaturan = null;

    /**
     * Isi set_jam_minimal (pengaturankamarinap.java).
     *
     * @return array{lamajam: int, hariawal: string, diagnosaakhir: string}
     */
    public function pengaturan(): array
    {
        if ($this->pengaturan === null) {
            $row = DB::table('set_jam_minimal')->first();

            $this->pengaturan = [
                'lamajam' => (int) ($row->lamajam ?? 0),
                'hariawal' => (string) ($row->hariawal ?? 'No'),
                'diagnosaakhir' => (string) ($row->diagnosaakhir ?? 'No'),
            ];
        }

        return $this->pengaturan;
    }

    public function diagnosaAkhirWajib(): bool
    {
        return $this->pengaturan()['diagnosaakhir'] === 'Yes';
    }

    /**
     * Lama inap (hari) antara waktu masuk dan keluar.
     * $lamaJam / $hariAwal null = ambil dari set_jam_minimal.
     */
    public function lama(CarbonInterface $masuk, CarbonInterface $keluar, ?int $lamaJam = null, ?bool $hariAwal = null): int
    {
        $lamaJam ??= $this->pengaturan()['lamajam'];
        $hariAwal ??= $this->pengaturan()['hariawal'] === 'Yes';

        // TO_DAYS(keluar) - TO_DAYS(masuk): selisih tanggal kalender, jam diabaikan.
        $hari = (int) round(Carbon::parse($masuk->toDateString(), 'UTC')
            ->diffInDays(Carbon::parse($keluar->toDateString(), 'UTC'), false));

        if ($hari === 0) {
            // TIME_TO_SEC hanya membaca bagian jam.
            $detik = $this->detikHari($keluar) - $this->detikHari($masuk);
            $lama = $detik > 3600 * $lamaJam ? 1 : 0;
        } else {
            $lama = $hari;
        }

        return $hariAwal ? $lama + 1 : $lama;
    }

    /**
     * ttl_biaya = lama * tarif, dibulatkan ke bilangan bulat (DecimalFormat "####" = HALF_EVEN).
     */
    public function total(float|int $lama, float|int $tarif): float
    {
        return round($lama * $tarif, 0, PHP_ROUND_HALF_EVEN);
    }

    /**
     * Tarif kamar efektif (isKmr): set_harga_kamar untuk (kd_kamar, kd_pj), kalau tidak ada kamar.trf_kamar.
     */
    public function tarif(string $kdKamar, ?string $kdPj): float
    {
        if ($kdPj !== null && $kdPj !== '') {
            $khusus = DB::table('set_harga_kamar')
                ->where('kd_kamar', $kdKamar)
                ->where('kd_pj', $kdPj)
                ->value('tarif');

            if ($khusus !== null) {
                return (float) $khusus;
            }
        }

        return (float) DB::table('kamar')->where('kd_kamar', $kdKamar)->value('trf_kamar');
    }

    /**
     * Tarif efektif untuk banyak kamar sekaligus (dipakai daftar pilihan kamar).
     *
     * @param  iterable<object{kd_kamar: string, trf_kamar: mixed}>  $kamar
     * @return array<string, float> kd_kamar => tarif
     */
    public function tarifBanyak(iterable $kamar, ?string $kdPj): array
    {
        $khusus = $kdPj
            ? DB::table('set_harga_kamar')->where('kd_pj', $kdPj)->pluck('tarif', 'kd_kamar')
            : collect();

        $hasil = [];
        foreach ($kamar as $k) {
            $hasil[$k->kd_kamar] = (float) ($khusus[$k->kd_kamar] ?? $k->trf_kamar);
        }

        return $hasil;
    }

    /**
     * Gabungkan kolom tanggal + jam Khanza menjadi Carbon.
     */
    public static function waktu(string $tanggal, string $jam): Carbon
    {
        return Carbon::parse($tanggal.' '.$jam);
    }

    private function detikHari(CarbonInterface $waktu): int
    {
        return $waktu->hour * 3600 + $waktu->minute * 60 + $waktu->second;
    }
}
