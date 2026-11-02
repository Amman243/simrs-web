<?php

namespace App\Services\RawatInap;

use App\Services\Khanza\Registrasi;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Masuk / pindah / pulang kamar inap dan daftar pasien ranap (port DlgKamarInap.java).
 *
 * Baris kamar_inap diidentifikasi dengan PK (no_rawat, tgl_masuk, jam_masuk).
 */
class KamarInapService
{
    /** Pilihan status pulang (enum kamar_inap.stts_pulang tanpa '-' dan 'Pindah Kamar'). */
    public const STATUS_PULANG = [
        'Sehat', 'Rujuk', 'APS', '+', 'Meninggal', 'Sembuh', 'Membaik', 'Pulang Paksa',
        'Status Belum Lengkap', 'Atas Persetujuan Dokter', 'Atas Permintaan Sendiri', 'Isoman', 'Lain-lain',
    ];

    public function __construct(private BiayaKamar $biaya) {}

    // ------------------------------------------------------------------ Daftar

    /**
     * Query daftar pasien ranap (DlgKamarInap.tampil()).
     *
     * @param  array{mode?: string, dari?: string, sampai?: string, kd_bangsal?: string, cari?: string, kd_dokter?: string|null}  $filter
     *                                                                                                                                     mode: 'dirawat' | 'masuk' | 'pulang'
     */
    public function daftar(array $filter): Builder
    {
        $query = DB::table('kamar_inap')
            ->join('reg_periksa', 'kamar_inap.no_rawat', '=', 'reg_periksa.no_rawat')
            ->join('pasien', 'reg_periksa.no_rkm_medis', '=', 'pasien.no_rkm_medis')
            ->join('kamar', 'kamar_inap.kd_kamar', '=', 'kamar.kd_kamar')
            ->join('bangsal', 'kamar.kd_bangsal', '=', 'bangsal.kd_bangsal')
            ->leftJoin('dokter', 'reg_periksa.kd_dokter', '=', 'dokter.kd_dokter')
            ->leftJoin('penjab', 'reg_periksa.kd_pj', '=', 'penjab.kd_pj')
            ->select([
                'kamar_inap.no_rawat', 'kamar_inap.kd_kamar', 'kamar_inap.trf_kamar', 'kamar_inap.diagnosa_awal',
                'kamar_inap.diagnosa_akhir', 'kamar_inap.tgl_masuk', 'kamar_inap.jam_masuk', 'kamar_inap.tgl_keluar',
                'kamar_inap.jam_keluar', 'kamar_inap.lama', 'kamar_inap.ttl_biaya', 'kamar_inap.stts_pulang',
                'reg_periksa.no_rkm_medis', 'reg_periksa.kd_pj', 'reg_periksa.umurdaftar', 'reg_periksa.sttsumur',
                'reg_periksa.status_bayar', 'pasien.nm_pasien', 'pasien.jk', 'kamar.kelas', 'kamar.status as status_kamar',
                'bangsal.kd_bangsal', 'bangsal.nm_bangsal', 'dokter.nm_dokter', 'penjab.png_jawab',
            ])
            ->selectSub(
                DB::table('dpjp_ranap')
                    ->join('dokter as d2', 'dpjp_ranap.kd_dokter', '=', 'd2.kd_dokter')
                    ->whereColumn('dpjp_ranap.no_rawat', 'kamar_inap.no_rawat')
                    ->selectRaw("group_concat(d2.nm_dokter separator ', ')"),
                'dpjp'
            );

        $mode = $filter['mode'] ?? 'dirawat';
        $dari = $filter['dari'] ?? null;
        $sampai = $filter['sampai'] ?? null;

        match ($mode) {
            'masuk' => $query->whereBetween('kamar_inap.tgl_masuk', [$dari ?: today()->toDateString(), $sampai ?: today()->toDateString()]),
            'pulang' => $query->whereBetween('kamar_inap.tgl_keluar', [$dari ?: today()->toDateString(), $sampai ?: today()->toDateString()]),
            default => $query->where('kamar_inap.stts_pulang', '-'),
        };

        if (! empty($filter['kd_bangsal'])) {
            $query->where('bangsal.kd_bangsal', $filter['kd_bangsal']);
        }

        if (! empty($filter['kd_dokter'])) {
            // KUNCIDOKTERRANAP: hanya pasien di mana dokter login menjadi DPJP.
            $query->whereExists(fn ($q) => $q->from('dpjp_ranap')
                ->whereColumn('dpjp_ranap.no_rawat', 'kamar_inap.no_rawat')
                ->where('dpjp_ranap.kd_dokter', $filter['kd_dokter']));
        }

        $cari = trim($filter['cari'] ?? '');
        if ($cari !== '') {
            $like = '%'.$cari.'%';
            $query->where(fn ($q) => $q
                ->where('kamar_inap.no_rawat', 'like', $like)
                ->orWhere('reg_periksa.no_rkm_medis', 'like', $like)
                ->orWhere('pasien.nm_pasien', 'like', $like)
                ->orWhere('kamar_inap.kd_kamar', 'like', $like)
                ->orWhere('bangsal.nm_bangsal', 'like', $like)
                ->orWhere('dokter.nm_dokter', 'like', $like)
                ->orWhere('penjab.png_jawab', 'like', $like)
                ->orWhere('kamar_inap.diagnosa_awal', 'like', $like));
        }

        return $query->orderBy('bangsal.nm_bangsal')->orderBy('kamar_inap.tgl_masuk')->orderBy('kamar_inap.jam_masuk');
    }

    /**
     * Lama & biaya berjalan untuk baris yang belum pulang (seperti updateHari(), tapi tanpa menulis ke DB).
     *
     * @return array{lama: int, ttl_biaya: float}
     */
    public function berjalan(object $row, ?CarbonInterface $sekarang = null): array
    {
        $masuk = BiayaKamar::waktu($row->tgl_masuk, $row->jam_masuk);
        $sekarang ??= now();
        $lama = max(0, $this->biaya->lama($masuk, $sekarang->greaterThan($masuk) ? $sekarang : $masuk));

        return ['lama' => $lama, 'ttl_biaya' => $this->biaya->total($lama, (float) $row->trf_kamar)];
    }

    /**
     * Ketersediaan kamar aktif per bangsal.
     */
    public function ketersediaan(): Collection
    {
        return DB::table('kamar')
            ->join('bangsal', 'kamar.kd_bangsal', '=', 'bangsal.kd_bangsal')
            ->where('kamar.statusdata', '1')
            ->orderBy('bangsal.nm_bangsal')->orderBy('kamar.kd_kamar')
            ->get(['kamar.kd_kamar', 'kamar.kelas', 'kamar.trf_kamar', 'kamar.status', 'bangsal.kd_bangsal', 'bangsal.nm_bangsal']);
    }

    /**
     * Kamar KOSONG + tarif efektif untuk penjab tertentu, dikelompokkan per nama bangsal.
     *
     * @return Collection<string, Collection<int, object>>
     */
    public function kamarKosong(?string $kdPj): Collection
    {
        $kamar = DB::table('kamar')
            ->join('bangsal', 'kamar.kd_bangsal', '=', 'bangsal.kd_bangsal')
            ->where('kamar.status', 'KOSONG')
            ->where('kamar.statusdata', '1')
            ->orderBy('bangsal.nm_bangsal')->orderBy('kamar.kd_kamar')
            ->get(['kamar.kd_kamar', 'kamar.kelas', 'kamar.trf_kamar', 'bangsal.kd_bangsal', 'bangsal.nm_bangsal']);

        $tarif = $this->biaya->tarifBanyak($kamar, $kdPj);

        return $kamar->map(function ($k) use ($tarif) {
            $k->tarif = $tarif[$k->kd_kamar];

            return $k;
        })->groupBy('nm_bangsal');
    }

    public function baris(string $noRawat, string $tglMasuk, string $jamMasuk): ?object
    {
        return DB::table('kamar_inap')
            ->join('kamar', 'kamar_inap.kd_kamar', '=', 'kamar.kd_kamar')
            ->join('bangsal', 'kamar.kd_bangsal', '=', 'bangsal.kd_bangsal')
            ->join('reg_periksa', 'kamar_inap.no_rawat', '=', 'reg_periksa.no_rawat')
            ->join('pasien', 'reg_periksa.no_rkm_medis', '=', 'pasien.no_rkm_medis')
            ->where('kamar_inap.no_rawat', $noRawat)
            ->where('kamar_inap.tgl_masuk', $tglMasuk)
            ->where('kamar_inap.jam_masuk', $jamMasuk)
            ->first([
                'kamar_inap.*', 'kamar.kelas', 'bangsal.kd_bangsal', 'bangsal.nm_bangsal',
                'reg_periksa.kd_pj', 'reg_periksa.no_rkm_medis', 'pasien.nm_pasien',
            ]);
    }

    /**
     * Baris kamar_inap yang masih terbuka (paling akhir masuk), lengkap dengan bangsal & kelas.
     */
    public function kamarAktif(string $noRawat): ?object
    {
        return DB::table('kamar_inap')
            ->join('kamar', 'kamar_inap.kd_kamar', '=', 'kamar.kd_kamar')
            ->join('bangsal', 'kamar.kd_bangsal', '=', 'bangsal.kd_bangsal')
            ->where('kamar_inap.no_rawat', $noRawat)
            ->where('kamar_inap.stts_pulang', '-')
            ->orderByDesc('kamar_inap.tgl_masuk')->orderByDesc('kamar_inap.jam_masuk')
            ->first(['kamar_inap.*', 'kamar.kelas', 'bangsal.kd_bangsal', 'bangsal.nm_bangsal']);
    }

    // ------------------------------------------------------------------ Masuk kamar

    /**
     * Data registrasi + pasien untuk form masuk kamar.
     */
    public function registrasi(string $noRawat): ?object
    {
        return DB::table('reg_periksa')
            ->join('pasien', 'reg_periksa.no_rkm_medis', '=', 'pasien.no_rkm_medis')
            ->leftJoin('dokter', 'reg_periksa.kd_dokter', '=', 'dokter.kd_dokter')
            ->leftJoin('poliklinik', 'reg_periksa.kd_poli', '=', 'poliklinik.kd_poli')
            ->leftJoin('penjab', 'reg_periksa.kd_pj', '=', 'penjab.kd_pj')
            ->where('reg_periksa.no_rawat', $noRawat)
            ->first([
                'reg_periksa.no_rawat', 'reg_periksa.no_rkm_medis', 'reg_periksa.tgl_registrasi', 'reg_periksa.jam_reg',
                'reg_periksa.kd_dokter', 'reg_periksa.kd_poli', 'reg_periksa.kd_pj', 'reg_periksa.stts',
                'reg_periksa.status_lanjut', 'reg_periksa.umurdaftar', 'reg_periksa.sttsumur', 'reg_periksa.p_jawab',
                'reg_periksa.hubunganpj', 'pasien.nm_pasien', 'pasien.jk', 'pasien.tgl_lahir', 'pasien.alamat',
                'dokter.nm_dokter', 'poliklinik.nm_poli', 'penjab.png_jawab',
            ]);
    }

    /**
     * Registrasi yang bisa dimasukkan kamar: bukan Batal, belum punya kamar_inap, pasien tidak sedang dirawat.
     */
    public function kandidatMasuk(string $cari = '', int $hari = 3, int $limit = 50): Collection
    {
        $query = DB::table('reg_periksa')
            ->join('pasien', 'reg_periksa.no_rkm_medis', '=', 'pasien.no_rkm_medis')
            ->leftJoin('poliklinik', 'reg_periksa.kd_poli', '=', 'poliklinik.kd_poli')
            ->leftJoin('dokter', 'reg_periksa.kd_dokter', '=', 'dokter.kd_dokter')
            ->leftJoin('penjab', 'reg_periksa.kd_pj', '=', 'penjab.kd_pj')
            ->where('reg_periksa.stts', '<>', 'Batal')
            ->whereNotExists(fn ($q) => $q->from('kamar_inap')->whereColumn('kamar_inap.no_rawat', 'reg_periksa.no_rawat'))
            ->whereNotExists(fn ($q) => $q->from('kamar_inap as ki')
                ->join('reg_periksa as r2', 'ki.no_rawat', '=', 'r2.no_rawat')
                ->whereColumn('r2.no_rkm_medis', 'reg_periksa.no_rkm_medis')
                ->where('ki.stts_pulang', '-'));

        $cari = trim($cari);
        if ($cari !== '') {
            $like = '%'.$cari.'%';
            $query->where(fn ($q) => $q
                ->where('reg_periksa.no_rawat', 'like', $like)
                ->orWhere('reg_periksa.no_rkm_medis', 'like', $like)
                ->orWhere('pasien.nm_pasien', 'like', $like));
        } else {
            $query->where('reg_periksa.tgl_registrasi', '>=', today()->subDays($hari)->toDateString());
        }

        return $query->orderByDesc('reg_periksa.tgl_registrasi')->orderByDesc('reg_periksa.jam_reg')
            ->limit($limit)
            ->get([
                'reg_periksa.no_rawat', 'reg_periksa.no_rkm_medis', 'reg_periksa.tgl_registrasi', 'reg_periksa.jam_reg',
                'reg_periksa.stts', 'reg_periksa.status_lanjut', 'pasien.nm_pasien', 'poliklinik.nm_poli',
                'dokter.nm_dokter', 'penjab.png_jawab',
            ]);
    }

    /**
     * Alasan registrasi tidak boleh masuk kamar, atau null bila boleh.
     */
    public function alasanTolakMasuk(?object $reg): ?string
    {
        return match (true) {
            $reg === null => 'Registrasi tidak ditemukan.',
            $reg->stts === 'Batal' => 'Registrasi berstatus Batal, tidak dapat masuk kamar.',
            Registrasi::terkunci($reg->no_rawat) => 'Data billing sudah terverifikasi. Silakan hubungi bagian kasir/keuangan.',
            Registrasi::punyaKamarInap($reg->no_rawat) => 'Registrasi ini sudah memiliki data kamar inap.',
            Registrasi::sedangDirawatInap($reg->no_rkm_medis) => 'Pasien masih dirawat inap pada registrasi lain. Pulangkan dulu sebelum masuk kamar baru.',
            default => null,
        };
    }

    /**
     * Check-in (BtnSimpanActionPerformed 5852-5883 + MnDIrawat DlgKasirRalan).
     */
    public function masuk(string $noRawat, string $kdKamar, string $diagnosaAwal, CarbonInterface $waktuMasuk, string $kdDokter): void
    {
        DB::transaction(function () use ($noRawat, $kdKamar, $diagnosaAwal, $waktuMasuk, $kdDokter) {
            // Kunci registrasi agar dua user tidak memasukkan registrasi yang sama bersamaan.
            DB::table('reg_periksa')->where('no_rawat', $noRawat)->lockForUpdate()->first();

            if ($alasan = $this->alasanTolakMasuk($this->registrasi($noRawat))) {
                throw new RawatInapException($alasan);
            }

            $reg = $this->registrasi($noRawat);

            if (! DB::table('dokter')->where('kd_dokter', $kdDokter)->exists()) {
                throw new RawatInapException('Dokter DPJP tidak ditemukan.');
            }

            $this->kunciKamarKosong($kdKamar);

            $tarif = $this->biaya->tarif($kdKamar, $reg->kd_pj);
            $lama = max(0, $this->biaya->lama($waktuMasuk, now()->greaterThan($waktuMasuk) ? now() : $waktuMasuk));

            DB::table('kamar_inap')->insert([
                'no_rawat' => $noRawat,
                'kd_kamar' => $kdKamar,
                'trf_kamar' => $tarif,
                'diagnosa_awal' => $diagnosaAwal,
                'diagnosa_akhir' => '',
                'tgl_masuk' => $waktuMasuk->toDateString(),
                'jam_masuk' => $waktuMasuk->format('H:i:s'),
                'tgl_keluar' => '0000-00-00',
                'jam_keluar' => '00:00:00',
                'lama' => $lama,
                'ttl_biaya' => $this->biaya->total($lama, $tarif),
                'stts_pulang' => '-',
            ]);

            DB::table('kamar')->where('kd_kamar', $kdKamar)->update(['status' => 'ISI']);
            DB::table('reg_periksa')->where('no_rawat', $noRawat)->update(['status_lanjut' => 'Ranap', 'stts' => 'Dirawat']);
            DB::table('dpjp_ranap')->insertOrIgnore(['no_rawat' => $noRawat, 'kd_dokter' => $kdDokter]);
        });
    }

    // ------------------------------------------------------------------ Pindah kamar

    /**
     * Pindah kamar mode 3 / standar (BtnSimpanpindahActionPerformed 7332-7385).
     */
    public function pindah(string $noRawat, string $tglMasuk, string $jamMasuk, string $kdKamarBaru, CarbonInterface $waktuPindah): void
    {
        DB::transaction(function () use ($noRawat, $tglMasuk, $jamMasuk, $kdKamarBaru, $waktuPindah) {
            $lama = $this->barisTerbuka($noRawat, $tglMasuk, $jamMasuk);

            if ($lama->kd_kamar === $kdKamarBaru) {
                throw new RawatInapException('Kamar tujuan sama dengan kamar saat ini.');
            }

            $masuk = BiayaKamar::waktu($lama->tgl_masuk, $lama->jam_masuk);
            if ($waktuPindah->lessThanOrEqualTo($masuk)) {
                throw new RawatInapException('Waktu pindah harus setelah waktu masuk kamar ('.$masuk->format('d-m-Y H:i:s').').');
            }

            $this->kunciKamarKosong($kdKamarBaru, 'Kamar tujuan');

            // Segmen lama ditutup dengan tarif efektif kamar lama (isKmr) sampai jam pindah.
            $tarifLama = $this->biaya->tarif($lama->kd_kamar, $lama->kd_pj);
            $hariLama = $this->biaya->lama($masuk, $waktuPindah);

            $this->wherePk(DB::table('kamar_inap'), $noRawat, $tglMasuk, $jamMasuk)->update([
                'trf_kamar' => $tarifLama,
                'tgl_keluar' => $waktuPindah->toDateString(),
                'jam_keluar' => $waktuPindah->format('H:i:s'),
                'lama' => $hariLama,
                'ttl_biaya' => $this->biaya->total($hariLama, $tarifLama),
                'stts_pulang' => 'Pindah Kamar',
            ]);
            DB::table('kamar')->where('kd_kamar', $lama->kd_kamar)->update(['status' => 'KOSONG']);

            $tarifBaru = $this->biaya->tarif($kdKamarBaru, $lama->kd_pj);
            $hariBaru = max(0, $this->biaya->lama($waktuPindah, now()->greaterThan($waktuPindah) ? now() : $waktuPindah));

            DB::table('kamar_inap')->insert([
                'no_rawat' => $noRawat,
                'kd_kamar' => $kdKamarBaru,
                'trf_kamar' => $tarifBaru,
                'diagnosa_awal' => $lama->diagnosa_awal ?? '',
                'diagnosa_akhir' => $lama->diagnosa_akhir ?? '',
                'tgl_masuk' => $waktuPindah->toDateString(),
                'jam_masuk' => $waktuPindah->format('H:i:s'),
                'tgl_keluar' => '0000-00-00',
                'jam_keluar' => '00:00:00',
                'lama' => $hariBaru,
                'ttl_biaya' => $this->biaya->total($hariBaru, $tarifBaru),
                'stts_pulang' => '-',
            ]);
            DB::table('kamar')->where('kd_kamar', $kdKamarBaru)->update(['status' => 'ISI']);
        });
    }

    // ------------------------------------------------------------------ Pulang

    /**
     * Check-out (BtnSimpanActionPerformed 5884-5933). reg_periksa tidak diubah.
     */
    public function pulang(string $noRawat, string $tglMasuk, string $jamMasuk, string $sttsPulang, string $diagnosaAkhir, CarbonInterface $waktuKeluar): void
    {
        if (! in_array($sttsPulang, self::STATUS_PULANG, true)) {
            throw new RawatInapException('Status pulang tidak valid.');
        }

        if ($this->biaya->diagnosaAkhirWajib() && trim($diagnosaAkhir) === '') {
            throw new RawatInapException('Diagnosa akhir wajib diisi.');
        }

        DB::transaction(function () use ($noRawat, $tglMasuk, $jamMasuk, $sttsPulang, $diagnosaAkhir, $waktuKeluar) {
            $row = $this->barisTerbuka($noRawat, $tglMasuk, $jamMasuk);

            $masuk = BiayaKamar::waktu($row->tgl_masuk, $row->jam_masuk);
            if ($waktuKeluar->lessThan($masuk)) {
                throw new RawatInapException('Waktu keluar tidak boleh sebelum waktu masuk kamar ('.$masuk->format('d-m-Y H:i:s').').');
            }

            $tarif = $this->biaya->tarif($row->kd_kamar, $row->kd_pj);
            $lama = $this->biaya->lama($masuk, $waktuKeluar);

            $this->wherePk(DB::table('kamar_inap'), $noRawat, $tglMasuk, $jamMasuk)->update([
                'tgl_keluar' => $waktuKeluar->toDateString(),
                'jam_keluar' => $waktuKeluar->format('H:i:s'),
                'trf_kamar' => $tarif,
                'lama' => $lama,
                'ttl_biaya' => $this->biaya->total($lama, $tarif),
                'stts_pulang' => $sttsPulang,
                'diagnosa_akhir' => $diagnosaAkhir,
            ]);
            DB::table('kamar')->where('kd_kamar', $row->kd_kamar)->update(['status' => 'KOSONG']);
        });
    }

    /**
     * "Belum Pulang" (DlgKamarInap 8530): kembalikan baris ke '-' dan tanggal keluar nol.
     * Hanya bila kamarnya masih KOSONG (lalu diisi lagi) dan pasien tidak punya rawat inap terbuka lain.
     */
    public function batalPulang(string $noRawat, string $tglMasuk, string $jamMasuk): void
    {
        DB::transaction(function () use ($noRawat, $tglMasuk, $jamMasuk) {
            $row = $this->wherePk(DB::table('kamar_inap'), $noRawat, $tglMasuk, $jamMasuk)->lockForUpdate()->first();

            if (! $row) {
                throw new RawatInapException('Data kamar inap tidak ditemukan.');
            }
            if (Registrasi::terkunci($noRawat)) {
                throw new RawatInapException('Data billing sudah terverifikasi. Silakan hubungi bagian kasir/keuangan.');
            }
            if (in_array($row->stts_pulang, ['-', 'Pindah Kamar'], true)) {
                throw new RawatInapException($row->stts_pulang === '-'
                    ? 'Pasien belum pulang.'
                    : 'Segmen pindah kamar tidak dapat dibatalkan dari sini.');
            }

            $noRm = DB::table('reg_periksa')->where('no_rawat', $noRawat)->value('no_rkm_medis');
            if ($noRm && Registrasi::sedangDirawatInap($noRm)) {
                throw new RawatInapException('Pasien masih memiliki rawat inap yang terbuka.');
            }

            $kamar = DB::table('kamar')->where('kd_kamar', $row->kd_kamar)->lockForUpdate()->first();
            if (! $kamar || $kamar->status !== 'KOSONG') {
                throw new RawatInapException("Kamar {$row->kd_kamar} sudah tidak kosong, pulang tidak dapat dibatalkan.");
            }

            $this->wherePk(DB::table('kamar_inap'), $noRawat, $tglMasuk, $jamMasuk)->update([
                'stts_pulang' => '-',
                'tgl_keluar' => '0000-00-00',
                'jam_keluar' => '00:00:00',
            ]);
            DB::table('kamar')->where('kd_kamar', $row->kd_kamar)->update(['status' => 'ISI']);
        });
    }

    // ------------------------------------------------------------------ Helper

    private function barisTerbuka(string $noRawat, string $tglMasuk, string $jamMasuk): object
    {
        $row = $this->wherePk(DB::table('kamar_inap'), $noRawat, $tglMasuk, $jamMasuk)->lockForUpdate()->first();

        if (! $row) {
            throw new RawatInapException('Data kamar inap tidak ditemukan.');
        }
        if ($row->stts_pulang !== '-') {
            throw new RawatInapException('Pasien sudah keluar dari kamar ini (status: '.$row->stts_pulang.').');
        }
        if (Registrasi::terkunci($noRawat)) {
            throw new RawatInapException('Data billing sudah terverifikasi. Silakan hubungi bagian kasir/keuangan.');
        }

        $row->kd_pj = DB::table('reg_periksa')->where('no_rawat', $noRawat)->value('kd_pj');

        return $row;
    }

    private function kunciKamarKosong(string $kdKamar, string $label = 'Kamar'): object
    {
        $kamar = DB::table('kamar')->where('kd_kamar', $kdKamar)->lockForUpdate()->first();

        if (! $kamar || $kamar->statusdata !== '1') {
            throw new RawatInapException("{$label} {$kdKamar} tidak ditemukan.");
        }
        if ($kamar->status !== 'KOSONG') {
            throw new RawatInapException("{$label} {$kdKamar} berstatus {$kamar->status}. Silakan pilih kamar yang kosong.");
        }

        return $kamar;
    }

    private function wherePk(Builder $query, string $noRawat, string $tglMasuk, string $jamMasuk): Builder
    {
        return $query->where('no_rawat', $noRawat)->where('tgl_masuk', $tglMasuk)->where('jam_masuk', $jamMasuk);
    }

    public static function parseWaktu(string $tanggal, string $jam): Carbon
    {
        return Carbon::parse($tanggal.' '.(strlen($jam) === 5 ? $jam.':00' : $jam));
    }
}
