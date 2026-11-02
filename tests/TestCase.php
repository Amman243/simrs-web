<?php

namespace Tests;

use App\Auth\KhanzaUser;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Tes berjalan di database `sik_test` (salinan `sik`, lihat phpunit.xml).
 * Pakai trait DatabaseTransactions di tes yang menulis data.
 * JANGAN pakai RefreshDatabase / DatabaseMigrations — akan menghapus semua tabel Khanza.
 */
abstract class TestCase extends BaseTestCase
{
    protected function actingAsAdmin(): static
    {
        return $this->actingAs(new KhanzaUser(kode: 'Admin Utama', isAdmin: true, nama: 'Admin Utama'));
    }

    /**
     * Login sebagai user biasa dengan permission tertentu, contoh:
     *   $this->actingAsKhanza(['igd' => true]);
     *   $this->actingAsKhanza(['tindakan_ralan' => true], kdDokter: 'D0000005');
     *
     * @param  array<string, bool>  $permissions
     */
    protected function actingAsKhanza(
        array $permissions = [],
        ?string $kdDokter = null,
        ?string $nip = null,
        string $kode = 'TESTUSER',
        string $nama = 'User Tes',
    ): static {
        $all = array_fill_keys(config('khanza.permissions'), false);

        return $this->actingAs(new KhanzaUser(
            kode: $kdDokter ?? $nip ?? $kode,
            isAdmin: false,
            nama: $nama,
            kdDokter: $kdDokter,
            nip: $nip,
            permissions: array_merge($all, $permissions),
        ));
    }
}
