# 01 — Autentikasi & Hak Akses

## Ringkasan

- Login dicek ke **dua tabel**: `admin` (super admin) lalu `user` (user biasa).
- ID dan password disimpan dengan **MySQL `AES_ENCRYPT`** (bukan hash), passphrase hard-code:
  - ID user: `'nur'`
  - Password: `'windi'`
- Admin → identitas `"Admin Utama"`, semua permission `true`.
- User → identitas = ID yang diketik (plaintext), permission diambil dari ±1222 kolom `'true'/'false'`
  di tabel `user`.

> Passphrase `'nur'`/`'windi'` dan kunci AES default `database.xml` adalah default open-source Khanza yang
> sudah publik. Kalau instalasi RS memakai nilai berbeda, sesuaikan lewat `.env`, jangan di-hardcode.

## Query login (Java)

`src/fungsi/akses.java`, `setData(user, pass)`, baris 271-768:

```java
// 273
ps  = koneksi.prepareStatement("select * from admin where admin.usere=AES_ENCRYPT(?,'nur') and admin.passworde=AES_ENCRYPT(?,'windi')");
// 274
ps2 = koneksi.prepareStatement("select * from user where user.id_user=AES_ENCRYPT(?,'nur') and user.password=AES_ENCRYPT(?,'windi')");
```

Alur:
1. Kedua query selalu dijalankan; jumlah baris disimpan di `jml1` (admin) dan `jml2` (user) (286-287).
2. `jml1 >= 1` (288): `akses.kode = "Admin Utama"` (289), semua flag `true` (290-1515), termasuk
   `admin`, `user`, `vakum`, `aplikasi`.
3. Selain itu, `jml2 >= 1` (1516): `akses.kode = <ID plaintext>` (1519); tiap flag dibaca dari kolom,
   mis. `akses.registrasi = rs2.getBoolean("registrasi")`. `'true'` → true; `'false'`/NULL → false.
   User biasa selalu `admin/user/vakum/aplikasi = false` (1646-1650).
4. Tidak cocok di keduanya → `setLogOut()` (2746-2747), semua flag direset (2770-3998).

Form login: `src/simrskhanza/frmUtama.java`, `BtnLoginActionPerformed` (8320-8449):
- Tolak field kosong, lalu `akses.setData(edAdmin.getText(), edPwd.getText())` (8331).
- Admin (8332): semua tombol aktif, label `"Admin Utama"`, menu ganti password nonaktif.
- User (8362): label = ID; tombol toolbar mengikuti permission, mis. `BtnToolReg` ← `getregistrasi()`,
  `btnToolIGD` ← `getigd()`, tombol Kamar Inap ← `kamar_inap || billing_ranap || tindakan_ranap`.
- Gagal (8414): "Maaf, Gagal login. ID User atau password ada yang salah".
- Audit login: kalau setting `AKTIFKANTRACKSQL=yes`, insert ke `tracker` (`'Admin Utama'` atau ID user,
  `current_date()`, `current_time()`) (8360, 8411).

## Pengelolaan user

| Aksi | Lokasi | SQL |
|---|---|---|
| Ganti password | `src/setting/WindowInputPassword.java:153` | `update user set password=AES_ENCRYPT('baru','windi') where id_user=AES_ENCRYPT('id','nur')` |
| Buat user | `src/setting/DlgUser.java:4168` | insert `AES_ENCRYPT(id,'nur'), AES_ENCRYPT(pass,'windi')` + `'false'` untuk semua permission |
| Daftar user | `DlgUser.java:7116-7353` | `select AES_DECRYPT(user.id_user,'nur'), AES_DECRYPT(user.password,'windi'), ... order by AES_DECRYPT(user.id_user,'nur')` |
| Hapus user | `DlgUser.java:4251` | |
| Muat 1 user | `src/setting/DlgUpdateUser.java:1189` | |

## Struktur tabel

`admin` (`sik.sql` 25-28) — MyISAM, tanpa PK, satu baris seed terenkripsi (37):
```sql
CREATE TABLE `admin` (`usere` text DEFAULT NULL, `passworde` text DEFAULT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1;
```

`user` (`sik.sql` 42488-43714) — MyISAM, latin1, `PRIMARY KEY (id_user)`:
```sql
`id_user`  varchar(700) NOT NULL,  -- AES_ENCRYPT(id,'nur'), binary di varchar
`password` text NOT NULL,          -- AES_ENCRYPT(pass,'windi')
`penyakit` enum('true','false') NOT NULL, ...
```
- Total **1224 kolom**: 2 kredensial + **1222 permission**.
- **151 permission NOT NULL** (42490-42631) → wajib diisi saat insert.
- 1071 permission `DEFAULT NULL` (mulai 42632); NULL = false.

### Kolom permission yang relevan

| Area | Kolom |
|---|---|
| Registrasi | `registrasi` (42497), `edit_registrasi` (42663), `booking_registrasi` (42845), `registrasi_poli_per_tanggal`, `rekap_biaya_registrasi` |
| IGD | `igd` (42606), `data_triase_igd` (43013), `penilaian_awal_medis_igd` (43242), `penilaian_awal_keperawatan_igd` (43288), `master_masalah_keperawatan_igd`, `master_rencana_keperawatan_igd`, `catatan_observasi_igd`, `laporan_tahunan_igd` |
| Rawat jalan | `tindakan_ralan` (42498), `kasir_ralan` (42511), `billing_ralan` (42603), `tarif_ralan`, `pembayaran_ralan`, `rekap_pembayaran_ralan`, `penyakit_ralan`, `kunjungan_ralan`, `piutang_ralan`, `cek_entry_ralan`, `catatan_keperawatan_ralan`, `penilaian_awal_keperawatan_ralan`, `penilaian_awal_medis_ralan` (+ varian spesialis `penilaian_awal_medis_ralan_*`), `soap_ralan_polri`, `soap_ralan_tni`, `akses_dokter_lain_rawat_jalan` |
| Rawat inap | `kamar_inap` (42499), `tindakan_ranap` (42500), `billing_ranap` (42604), `kamar` (42578), `dpjp_ranap` (42630), `permintaan_ranap` (43189), `tarif_ranap`, `pembayaran_ranap`, `informasi_kamar`, `set_harga_kamar`, `riwayat_kamar_pasien`, `daftar_pasien_ranap`, `penilaian_awal_medis_ranap`, `penilaian_awal_keperawatan_ranap`, `catatan_observasi_ranap`, `catatan_keperawatan_ranap`, `soap_ranap_polri`, `soap_ranap_tni` |
| Pasien | `pasien` (42496), `pasien_meninggal`, `diagnosa_pasien` (42614), `catatan_pasien`, `resume_pasien`, `deposit_pasien`, `perusahaan_pasien`, `kedatangan_pasien` |
| Sering dipakai bersama | `beri_obat` (42504), `periksa_lab` (42509), `periksa_radiologi` (42510), `resep_dokter` (42727), `rujukan_keluar`, `rujukan_masuk`, `operasi` |

## Mapping user → orang

Tidak ada FK atau kolom tipe. ID user (setelah didekripsi) **sama dengan** `dokter.kd_dokter` atau
`petugas.nip`, dan keduanya sama dengan `pegawai.nik`.

- Tampilan daftar user (`DlgUser.java:7359-7364`): coba dokter dulu (`Sequel.CariDokter(id)`, jabatan dari
  `spesialis.nm_sps`), kalau kosong pakai petugas (`Sequel.CariPetugas(id)`, jabatan dari `jabatan.nm_jbtn`).
- Helper nama di `src/fungsi/sekuel.java`: `CariDokter` (2117), `CariPetugas` (2149), `CariPegawai` (2181),
  `CariJabatanPegawai` (2213). Membaca cache JSON lokal (`./cache/*.iyem`).
- Query langsung juga ada: `select petugas.nama from petugas where petugas.nip=?` (`DlgReg.java:10479`,
  `DlgIGD.java:8136`).
- Form mengisi petugas otomatis dari user login bila `getjml2() >= 1`; kalau bukan petugas muncul
  "User login bukan petugas" (`DlgRawatJalan.java:12015-12029`).
- `DlgKamarInap.java:20661-20664`: kalau setting `KUNCIDOKTERRANAP` aktif, dokter hanya melihat pasiennya
  sendiri (`CariDokter(akses.getkode())`).

Usulan untuk Laravel — nama tampilan:
```sql
COALESCE(dokter.nm_dokter, petugas.nama, pegawai.nama)  -- key: ID terdekripsi; admin = 'Admin Utama'
```

## Pola pengecekan permission

- `src/fungsi/akses.java` = kelas static global, ±1220 field `private static boolean` (23-269), diisi sekali
  saat login.
- Getter per flag: `getregistrasi()` (4013), `gettindakan_ralan()` (4014), `getkamar_inap()` (4015),
  `getpasien()` (4012), `getigd()` (4135), `getkode()` (4123), `getjml1()/getjml2()` (4000-4001).
- Tiap form punya `isCek()` yang meng-enable tombol sesuai permission:
  - `DlgReg.java:17618` — simpan/hapus/edit/print ← `getregistrasi()`; menu kamar inap ← `getkamar_inap()`
  - `DlgIGD.java:13312` ← `getigd()`
  - `DlgRawatJalan.java:11294` ← `gettindakan_ralan()`
  - `DlgPasien.java:9980` ← `getpasien()`
  - `DlgKamarInap.java:20424`
- **Satu permission mencakup tambah/ubah/hapus/cetak** (tidak ada pemisahan read/write).
- Pengecekan **hanya di UI**; database tidak membatasi apa pun.

## Login di webapps PHP

- `webapps/conf/conf.php` (6-11): koneksi DB (`$db_name = "sik"`) + konstanta `USERHYBRIDWEB`/`PASHYBRIDWEB`
  (nilai tidak dicatat di sini).
- `webapps/index.php` (207-216), input dibersihkan `validTeks4(..., 30)`:
  ```php
  getOne("select count(admin.passworde) from admin where admin.usere=aes_encrypt('$usere','nur') and admin.passworde=aes_encrypt('$passworde','windi')")>0
  getOne("select count(user.password) from user where user.id_user=aes_encrypt('$usere','nur') and user.password=aes_encrypt('$passworde','windi')")>0
  ```
  Cocok → `$_SESSION["ses_admin_login"] = USERHYBRIDWEB.PASHYBRIDWEB`. Permission tidak dibaca.
- `encrypt_decrypt` (`conf.php:1040-1058`): AES-256-CBC, dipakai untuk link antar sub-app (`?iyem=`).
- Login VEDIKA (asuransi): `webapps/berkasrawat/loginvedika.php:9` ke `password_asuransi` dengan kunci sama.
- `webapps/presensi/login.php` tidak terpakai (query kolom yang tidak ada di `user`).

## Config koneksi Khanza

- `setting/database.xml` (Java Properties XML): `HOST`, `DATABASE`, `PORT`, `USER`, `PAS` dan banyak key API
  disimpan sebagai base64 ciphertext; key lain plaintext (`HYBRIDWEB`, `TANGGALMUNDUR`, `URLAPIBPJS`, ...).
- Didekripsi di `src/fungsi/koneksiDB.java:78-87` dengan `KhanzaSecurity16bit/src/AESsecurity/EnkripsiAES.java:19-20`
  (`AES/CBC/PKCS5PADDING`, key/IV default 16 byte). Di PHP setara `openssl_decrypt($b64, 'AES-128-CBC', $key, 0, $iv)`.
- Passphrase `AES_ENCRYPT` (`'nur'`, `'windi'`) hard-code di Java & PHP, tidak ada di file config.

## Tabel terkait

- `pegawai` (19022): `id` AI PK, `nik` varchar(20) UNIQUE, `nama`, `jk` enum('Pria','Wanita'), `jbtn`,
  `departemen`, `bidang`, `stts_aktif` enum('AKTIF','CUTI','KELUAR','TENAGA LUAR','NON AKTIF'), `photo`, `no_ktp`, ...
- `petugas` (28257): `nip` PK (FK `pegawai.nik`), `nama`, `kd_jbtn` (FK `jabatan`), `email`, `status` enum('0','1').
- `dokter` (9016): `kd_dokter` PK (FK `pegawai.nik`), `nm_dokter`, `kd_sps` (FK `spesialis`), `status` enum('0','1').
- `tracker` (42317): `nip`, `tgl_login`, `jam_login` (PK ketiganya).
- `trackersql` (42342): log SQL (`tanggal`, `sqle`, `usere`).

## Rencana di Laravel

1. **Custom auth** (bukan tabel `users`): `UserProvider` kustom yang memverifikasi dengan SQL:
   ```php
   DB::selectOne("select count(*) c from admin where usere = AES_ENCRYPT(?, ?) and passworde = AES_ENCRYPT(?, ?)", [$id, $kNur, $pw, $kWindi]);
   DB::selectOne("select AES_DECRYPT(id_user, ?) id from user where id_user = AES_ENCRYPT(?, ?) and password = AES_ENCRYPT(?, ?)", [...]);
   ```
   Bandingkan di SQL (mode default MySQL `aes-128-ecb`). Passphrase disimpan di `.env`.
2. Objek user di session: `kode` (`'Admin Utama'` / ID), `is_admin`, nama tampilan, dan permission yang
   dibutuhkan (dibaca saat login, disimpan di session).
3. Permission → Laravel `Gate::before` (admin = semua) + `Gate::define('registrasi', ...)` dst. Wajib dicek di
   server (komponen Livewire/route middleware), bukan hanya menyembunyikan tombol.
4. Opsional: catat login ke `tracker`.
