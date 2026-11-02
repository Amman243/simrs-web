<?php

namespace App\Services\Igd;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Khanza tidak memakai lock untuk nomor otomatis (no_rawat, no_reg, no_rkm_medis):
 * kalau insert gagal karena PK duplikat, nomor dibuat ulang lalu dicoba lagi, maks 5 kali
 * (DlgIGD 5082-5118, DlgPasien 3816-3900). Trait ini menjalankan pola yang sama, tiap
 * percobaan di dalam DB::transaction() sendiri.
 */
trait CobaUlangDuplikat
{
    protected int $maksPercobaan = 5;

    /**
     * @template T
     *
     * @param  Closure(int): T  $aksi  menerima nomor percobaan (0-based)
     * @return T
     */
    protected function cobaUlangSaatDuplikat(Closure $aksi, string $pesanGagal): mixed
    {
        for ($percobaan = 0; $percobaan < $this->maksPercobaan; $percobaan++) {
            try {
                return DB::transaction(fn () => $aksi($percobaan));
            } catch (QueryException $e) {
                if (! self::duplikat($e)) {
                    throw $e;
                }
            }
        }

        throw new IgdException($pesanGagal);
    }

    /**
     * SQLSTATE 23000 + MySQL error 1062 (Duplicate entry).
     */
    public static function duplikat(QueryException $e): bool
    {
        $info = $e->errorInfo ?? [];

        if (isset($info[1])) {
            return (int) $info[1] === 1062;
        }

        return (string) $e->getCode() === '23000' && str_contains($e->getMessage(), 'Duplicate');
    }
}
