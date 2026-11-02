<?php

use Illuminate\Support\Facades\Route;

// Modul Rawat Inap. Sudah di dalam grup middleware 'auth' (routes/web.php).
Route::middleware('can:kamar_inap')->prefix('rawat-inap')->name('rawat-inap.')->group(function () {
    Route::livewire('/', 'pages::rawat-inap.index')->name('index');
    // Masuk kamar untuk registrasi tertentu: /rawat-inap/masuk?no_rawat=2026/11/01/000001
    Route::livewire('/masuk', 'pages::rawat-inap.masuk')->name('masuk');
    Route::livewire('/{noRawat}', 'pages::rawat-inap.show')->name('show');
});
