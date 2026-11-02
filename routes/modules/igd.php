<?php

use Illuminate\Support\Facades\Route;

// Modul Registrasi IGD. Sudah di dalam grup middleware 'auth' (routes/web.php).
Route::middleware('can:igd')->prefix('igd')->name('igd.')->group(function () {
    Route::livewire('/', 'pages::igd.index')->name('index');
    Route::livewire('/pasien-baru', 'pages::igd.pasien-baru')->middleware('can:pasien')->name('pasien-baru');
});
