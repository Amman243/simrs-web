<?php

use Illuminate\Support\Facades\Route;

// Modul Registrasi: rawat jalan (pilih poli, hak `registrasi`) dan IGD (hak `igd`).
// Sudah di dalam grup middleware 'auth' (routes/web.php).
Route::middleware('can:akses_registrasi')->prefix('registrasi')->name('registrasi.')->group(function () {
    Route::livewire('/', 'pages::registrasi.index')->name('index');
    Route::livewire('/pasien-baru', 'pages::registrasi.pasien-baru')->middleware('can:pasien')->name('pasien-baru');
});
