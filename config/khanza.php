<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Passphrase AES_ENCRYPT
    |--------------------------------------------------------------------------
    |
    | Khanza menyimpan ID user dan password memakai MySQL AES_ENCRYPT dengan
    | passphrase ini (default open-source: 'nur' dan 'windi').
    |
    */

    'aes' => [
        'user' => env('KHANZA_AES_USER_KEY', 'nur'),
        'password' => env('KHANZA_AES_PASSWORD_KEY', 'windi'),
    ],

    // Identitas super admin (baris di tabel `admin`), sama seperti akses.getkode() di Khanza.
    'admin_kode' => 'Admin Utama',

    // Kode poliklinik IGD yang di-hardcode di DlgIGD.java.
    'kd_poli_igd' => env('KHANZA_KD_POLI_IGD', 'IGDK'),

    // Mode nomor antrian (URUTNOREG di setting/database.xml): 'dokter', 'poli', 'dokter + poli'.
    'urut_no_reg' => env('KHANZA_URUT_NO_REG', 'dokter + poli'),

    /*
    |--------------------------------------------------------------------------
    | Kolom permission tabel `user` yang dimuat saat login
    |--------------------------------------------------------------------------
    |
    | Tabel `user` punya ±1222 kolom 'true'/'false'. Hanya kolom di sini yang
    | dibaca; nama kolom dipakai langsung sebagai ability Gate, mis.
    | `can('igd')`, `@can('kamar_inap')`, middleware `can:tindakan_ralan`.
    |
    */

    'permissions' => [
        'pasien',
        'registrasi',
        'edit_registrasi',
        'igd',
        'data_triase_igd',
        'tindakan_ralan',
        'kasir_ralan',
        'billing_ralan',
        'akses_dokter_lain_rawat_jalan',
        'kamar_inap',
        'kamar',
        'tindakan_ranap',
        'billing_ranap',
        'dpjp_ranap',
        'daftar_pasien_ranap',
    ],

];
