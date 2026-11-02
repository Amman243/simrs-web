<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\Access\Authorizable;

/**
 * User yang login, berasal dari tabel `admin` (Admin Utama) atau `user` Khanza.
 *
 * `kode` sama dengan akses.getkode() di Khanza: 'Admin Utama' untuk admin, atau
 * ID user plaintext (= dokter.kd_dokter / petugas.nip / pegawai.nik).
 */
class KhanzaUser implements Authenticatable, AuthorizableContract
{
    use Authorizable;

    /**
     * @param  array<string, bool>  $permissions  kolom permission => diizinkan
     */
    public function __construct(
        public readonly string $kode,
        public readonly bool $isAdmin,
        public readonly string $nama,
        public readonly ?string $jabatan = null,
        public readonly ?string $kdDokter = null,
        public readonly ?string $nip = null,
        public readonly array $permissions = [],
    ) {}

    public function isDokter(): bool
    {
        return $this->kdDokter !== null;
    }

    public function isPetugas(): bool
    {
        return $this->nip !== null;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->isAdmin || ($this->permissions[$permission] ?? false);
    }

    public function knowsPermission(string $permission): bool
    {
        return array_key_exists($permission, $this->permissions);
    }

    public function getAuthIdentifierName(): string
    {
        return 'kode';
    }

    public function getAuthIdentifier(): string
    {
        return $this->kode;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        //
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
