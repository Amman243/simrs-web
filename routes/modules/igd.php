<?php

use Illuminate\Support\Facades\Route;

// URL lama Registrasi IGD → modul Registrasi (routes/modules/registrasi.php).
Route::redirect('/igd', '/registrasi?jenis=igd');
Route::redirect('/igd/pasien-baru', '/registrasi/pasien-baru');
