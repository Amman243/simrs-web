<?php

namespace App\Services\RawatJalan;

use App\Auth\KhanzaUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Worklist pasien rawat jalan, port DlgKasirRalan.tampilkasir() (16504-16590).
 *
 * Filter (semua opsional kecuali tanggal):
 *   tgl1, tgl2, kd_poli, kd_dokter, stts, kd_pj, status_bayar, cari
 */
class WorklistQuery
{
    /** Pilihan status reg_periksa.stts. */
    public const STATUS = ['Belum', 'Sudah', 'Batal', 'Berkas Diterima', 'Dirujuk', 'Meninggal', 'Dirawat', 'Pulang Paksa'];

    public const STATUS_BAYAR = ['Belum Bayar', 'Sudah Bayar'];

    /**
     * Kunci dokter (DlgKasirRalan 16994-17027): user dokter tanpa akses_dokter_lain_rawat_jalan
     * hanya melihat pasiennya sendiri. Admin tidak pernah dikunci.
     */
    public static function dokterTerkunci(?KhanzaUser $user): ?string
    {
        if (! $user || $user->isAdmin || ! $user->isDokter()) {
            return null;
        }

        return $user->can('akses_dokter_lain_rawat_jalan') ? null : $user->kdDokter;
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    public static function query(array $filter, bool $denganStatus = true): Builder
    {
        $tgl1 = $filter['tgl1'] ?? today()->toDateString();
        $tgl2 = $filter['tgl2'] ?? $tgl1;

        $query = DB::table('reg_periksa')
            ->join('dokter', 'reg_periksa.kd_dokter', '=', 'dokter.kd_dokter')
            ->join('pasien', 'reg_periksa.no_rkm_medis', '=', 'pasien.no_rkm_medis')
            ->join('poliklinik', 'reg_periksa.kd_poli', '=', 'poliklinik.kd_poli')
            ->join('penjab', 'reg_periksa.kd_pj', '=', 'penjab.kd_pj')
            ->whereBetween('reg_periksa.tgl_registrasi', [$tgl1, $tgl2])
            ->where('reg_periksa.status_lanjut', 'Ralan');

        foreach (['kd_poli', 'kd_dokter', 'kd_pj', 'status_bayar'] as $kolom) {
            if (filled($filter[$kolom] ?? null)) {
                $query->where('reg_periksa.'.$kolom, $filter[$kolom]);
            }
        }

        if ($denganStatus && filled($filter['stts'] ?? null)) {
            $query->where('reg_periksa.stts', $filter['stts']);
        }

        $cari = trim((string) ($filter['cari'] ?? ''));
        if ($cari !== '') {
            $like = '%'.$cari.'%';
            $query->where(function (Builder $q) use ($like) {
                foreach ([
                    'reg_periksa.no_reg', 'reg_periksa.no_rawat', 'reg_periksa.no_rkm_medis', 'pasien.nm_pasien',
                    'dokter.nm_dokter', 'poliklinik.nm_poli', 'reg_periksa.p_jawab', 'penjab.png_jawab',
                    'reg_periksa.almt_pj', 'reg_periksa.hubunganpj', 'pasien.no_tlp',
                ] as $kolom) {
                    $q->orWhere($kolom, 'like', $like);
                }
            });
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    public static function paginate(array $filter, int $perPage = 20): LengthAwarePaginator
    {
        return self::query($filter)
            ->select([
                'reg_periksa.no_reg', 'reg_periksa.no_rawat', 'reg_periksa.tgl_registrasi', 'reg_periksa.jam_reg',
                'reg_periksa.kd_dokter', 'dokter.nm_dokter', 'reg_periksa.no_rkm_medis', 'pasien.nm_pasien', 'pasien.jk',
                'poliklinik.nm_poli', 'reg_periksa.kd_poli', 'reg_periksa.p_jawab', 'reg_periksa.biaya_reg', 'reg_periksa.stts',
                'penjab.png_jawab', 'reg_periksa.kd_pj', 'reg_periksa.status_bayar', 'reg_periksa.status_poli',
                'pasien.no_tlp', DB::raw("concat(reg_periksa.umurdaftar,' ',reg_periksa.sttsumur) as umur"),
            ])
            ->orderByDesc('reg_periksa.no_rawat')
            ->paginate($perPage);
    }

    /**
     * Ringkasan jumlah per status untuk filter aktif (tanpa filter status itu sendiri).
     *
     * @param  array<string, mixed>  $filter
     * @return array{total: int, Belum: int, Sudah: int, Batal: int}
     */
    public static function ringkasan(array $filter): array
    {
        $per = self::query($filter, denganStatus: false)
            ->selectRaw('reg_periksa.stts, count(*) as jumlah')
            ->groupBy('reg_periksa.stts')
            ->pluck('jumlah', 'stts');

        return [
            'total' => (int) $per->sum(),
            'Belum' => (int) ($per['Belum'] ?? 0),
            'Sudah' => (int) ($per['Sudah'] ?? 0),
            'Batal' => (int) ($per['Batal'] ?? 0),
        ];
    }
}
