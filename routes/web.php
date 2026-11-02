<?php

use App\Http\Controllers\Auth\LogoutController;
use Illuminate\Support\Facades\Route;

// no_rawat Khanza berformat yyyy/MM/dd/NNNNNN, contoh: /rawat-jalan/2026/11/01/000001
Route::pattern('noRawat', '[0-9]{4}/[0-9]{2}/[0-9]{2}/[0-9]{6}');

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'pages::auth.login')->name('login');
});

Route::middleware('auth')->group(function () {
    Route::livewire('/', 'pages::dashboard')->name('dashboard');
    Route::post('/logout', LogoutController::class)->name('logout');

    // Route per modul ada di routes/modules/*.php (satu file per modul).
    foreach (glob(__DIR__.'/modules/*.php') as $module) {
        require $module;
    }
});
