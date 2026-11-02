<?php

use Illuminate\Support\Facades\Route;

// Modul Rawat Jalan. Sudah di dalam grup middleware 'auth' (routes/web.php).
Route::middleware('can:tindakan_ralan')->prefix('rawat-jalan')->name('rawat-jalan.')->group(function () {
    Route::livewire('/', 'pages::rawat-jalan.index')->name('index');
    Route::livewire('/{noRawat}', 'pages::rawat-jalan.show')->name('show');
});
