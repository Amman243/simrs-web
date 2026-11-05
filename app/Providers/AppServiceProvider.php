<?php

namespace App\Providers;

use App\Auth\KhanzaUser;
use App\Auth\KhanzaUserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('khanza', fn () => new KhanzaUserProvider);

        // Ability = nama kolom permission di tabel `user` (lihat config/khanza.php).
        // Admin Utama boleh semuanya, seperti di Khanza.
        Gate::before(function ($user, string $ability) {
            if (! $user instanceof KhanzaUser) {
                return null;
            }

            if ($user->isAdmin) {
                return true;
            }

            return $user->knowsPermission($ability) ? $user->hasPermission($ability) : null;
        });

        // Modul Registrasi: cukup salah satu hak — registrasi rawat jalan (DlgReg) atau IGD (DlgIGD).
        Gate::define('akses_registrasi', fn ($user) => $user->can('registrasi') || $user->can('igd'));
    }
}
