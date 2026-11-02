<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\DB;

/**
 * Login memakai tabel `admin` dan `user` Khanza (lihat docs/khanza/01-autentikasi.md).
 * Kredensial dibandingkan di SQL dengan AES_ENCRYPT, sama seperti src/fungsi/akses.java.
 *
 * Credentials yang dipakai: ['id_user' => ..., 'password' => ...].
 */
class KhanzaUserProvider implements UserProvider
{
    public function retrieveById($identifier): ?KhanzaUser
    {
        if ($identifier === config('khanza.admin_kode')) {
            return DB::table('admin')->exists() ? $this->makeAdmin() : null;
        }

        return $this->findUser((string) $identifier);
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void
    {
        //
    }

    public function retrieveByCredentials(array $credentials): ?KhanzaUser
    {
        $id = (string) ($credentials['id_user'] ?? '');

        if ($id === '') {
            return null;
        }

        // Sama seperti Khanza: tabel admin dicek lebih dulu (ID + password).
        if (isset($credentials['password']) && $this->adminMatches($id, (string) $credentials['password'])) {
            return $this->makeAdmin();
        }

        return $this->findUser($id);
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $id = (string) ($credentials['id_user'] ?? '');
        $password = (string) ($credentials['password'] ?? '');

        if ($user instanceof KhanzaUser && $user->isAdmin) {
            return $this->adminMatches($id, $password);
        }

        return DB::table('user')
            ->whereRaw('id_user = AES_ENCRYPT(?, ?)', [$id, config('khanza.aes.user')])
            ->whereRaw('password = AES_ENCRYPT(?, ?)', [$password, config('khanza.aes.password')])
            ->exists();
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
        //
    }

    private function adminMatches(string $id, string $password): bool
    {
        return DB::table('admin')
            ->whereRaw('usere = AES_ENCRYPT(?, ?)', [$id, config('khanza.aes.user')])
            ->whereRaw('passworde = AES_ENCRYPT(?, ?)', [$password, config('khanza.aes.password')])
            ->exists();
    }

    private function makeAdmin(): KhanzaUser
    {
        $kode = config('khanza.admin_kode');

        return new KhanzaUser(kode: $kode, isAdmin: true, nama: $kode, jabatan: 'Administrator');
    }

    private function findUser(string $id): ?KhanzaUser
    {
        $columns = config('khanza.permissions');

        $row = DB::table('user')
            ->whereRaw('id_user = AES_ENCRYPT(?, ?)', [$id, config('khanza.aes.user')])
            ->first($columns);

        if (! $row) {
            return null;
        }

        $permissions = [];
        foreach ($columns as $column) {
            $permissions[$column] = $row->{$column} === 'true';
        }

        $dokter = DB::table('dokter')
            ->leftJoin('spesialis', 'spesialis.kd_sps', '=', 'dokter.kd_sps')
            ->where('dokter.kd_dokter', $id)
            ->first(['dokter.kd_dokter', 'dokter.nm_dokter', 'spesialis.nm_sps']);

        $petugas = DB::table('petugas')
            ->leftJoin('jabatan', 'jabatan.kd_jbtn', '=', 'petugas.kd_jbtn')
            ->where('petugas.nip', $id)
            ->first(['petugas.nip', 'petugas.nama', 'jabatan.nm_jbtn']);

        $nama = $dokter->nm_dokter
            ?? $petugas->nama
            ?? DB::table('pegawai')->where('nik', $id)->value('nama')
            ?? $id;

        return new KhanzaUser(
            kode: $id,
            isAdmin: false,
            nama: $nama,
            jabatan: $dokter->nm_sps ?? $petugas->nm_jbtn ?? null,
            kdDokter: $dokter->kd_dokter ?? null,
            nip: $petugas->nip ?? null,
            permissions: $permissions,
        );
    }
}
