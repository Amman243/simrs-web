<?php

namespace App\Services\Igd;

/**
 * Pesan validasi Bahasa Indonesia untuk form IGD (aplikasi belum punya lang/id).
 */
class PesanValidasi
{
    /**
     * @return array<string, string>
     */
    public static function pesan(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'exists' => ':attribute tidak ditemukan.',
            'max' => ':attribute maksimal :max karakter.',
            'in' => ':attribute tidak valid.',
            'date' => ':attribute bukan tanggal yang valid.',
            'date_format' => 'Format :attribute tidak valid.',
            'before_or_equal' => ':attribute tidak boleh melebihi hari ini.',
            'after_or_equal' => ':attribute harus sama atau setelah tanggal awal.',
            'email' => ':attribute harus berupa alamat email yang valid.',
        ];
    }
}
