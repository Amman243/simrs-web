# Catatan Eksplorasi SIMRS Khanza

Catatan ini merangkum cara kerja SIMRS Khanza (aplikasi desktop Java Swing) untuk 4 fitur yang akan
dibangun ulang di `simrs-web` (Laravel + Livewire + Alpine + Tailwind):

1. [Autentikasi & hak akses](01-autentikasi.md)
2. [Registrasi UGD/IGD](02-registrasi-igd.md)
3. [Rawat inap](03-rawat-inap.md)
4. [Rawat jalan](04-rawat-jalan.md)
5. [Skema tabel](05-skema-tabel.md)

`simrs-web` memakai **database MySQL yang sama dengan Khanza: `sik`**. Skema lengkap ada di
`simrs-khanza/sik.sql`. Semua path di dokumen ini relatif terhadap folder `simrs-khanza/`, dan nomor baris
mengacu ke versi source saat eksplorasi.

## Peta file sumber Khanza

| Fitur | File Java | Keterangan |
|---|---|---|
| Login & hak akses | `src/fungsi/akses.java` | Query login + ±1220 flag permission static |
| Form login | `src/simrskhanza/frmUtama.java` | `BtnLoginActionPerformed` (8320-8449) |
| Manajemen user | `src/setting/DlgUser.java`, `src/setting/WindowInputPassword.java` | Buat user, ganti password |
| Registrasi umum | `src/simrskhanza/DlgReg.java` | Registrasi rawat jalan per poli (tanpa IGD) |
| Registrasi IGD | `src/simrskhanza/DlgIGD.java` | `kd_poli='IGDK'` di-hardcode |
| Master pasien | `src/simrskhanza/DlgPasien.java` | Pasien baru + No. RM otomatis |
| Triase IGD | `src/rekammedis/RMTriaseIGD.java` | Langkah terpisah setelah registrasi |
| Kamar inap | `src/simrskhanza/DlgKamarInap.java` | Masuk, pindah, pulang, daftar pasien ranap |
| Perawatan ranap | `src/simrskhanza/DlgRawatInap.java` | Tindakan + SOAP ranap |
| Katalog tindakan ranap | `src/keuangan/DlgCariPerawatanRanap.java` | Filter tarif per bangsal/kelas/penjab |
| Worklist rawat jalan | `src/simrskhanza/DlgKasirRalan.java` | Daftar pasien ralan + ubah status |
| Perawatan ralan | `src/simrskhanza/DlgRawatJalan.java` | Tindakan + SOAP ralan |
| Helper SQL | `src/fungsi/sekuel.java`, `src/fungsi/validasi.java` | Insert/update positional, auto nomor |
| Setting ranap | `src/fungsi/pengaturankamarinap.java` | Membaca `set_jam_minimal` |
| Config koneksi | `setting/database.xml`, `src/fungsi/koneksiDB.java` | Nilai terenkripsi AES |

## Tabel inti untuk MVP

`admin`, `user`, `pegawai`, `dokter`, `petugas`, `jabatan`, `spesialis`, `pasien`, `reg_periksa`,
`poliklinik`, `penjab`, `bangsal`, `kamar`, `kamar_inap`, `dpjp_ranap`, `set_harga_kamar`,
`set_jam_minimal`, `set_tarif`, `set_no_rkm_medis`, `set_urut_no_rkm_medis`, `kategori_perawatan`,
`jns_perawatan`, `jns_perawatan_inap`, `rawat_jl_dr/pr/drpr`, `rawat_inap_dr/pr/drpr`,
`pemeriksaan_ralan`, `pemeriksaan_ranap`, `billing` (hanya untuk cek kunci), `data_triase_igd*`.

## Hal penting untuk implementasi Laravel

- **Jangan jalankan `php artisan migrate` ke DB `sik`.** Migration bawaan Laravel akan membuat tabel
  `users`, `cache`, `jobs`, `sessions`, dll. di DB Khanza. Karena itu `.env` memakai `SESSION_DRIVER=file`,
  `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`. Kalau butuh tabel tambahan, pakai DB/koneksi terpisah.
- **Insert di Khanza positional** (`insert into t values(?,?,...)` tanpa daftar kolom, lewat
  `Sequel.menyimpan*`). Di Laravel selalu sebutkan nama kolom. Urutan `rtl`/`penilaian` di
  `pemeriksaan_ralan` dan `pemeriksaan_ranap` berbeda.
- **Tanggal nol**: `kamar_inap.tgl_keluar='0000-00-00'` dan `jam_keluar='00:00:00'` berarti belum pulang.
  Mode strict MySQL di Laravel (`NO_ZERO_DATE`) perlu dilonggarkan untuk koneksi ini, atau perlakukan nilai
  ini sebagai sentinel. Status "masih dirawat" ditentukan oleh `stts_pulang='-'`.
- **Primary key komposit** di banyak tabel (`kamar_inap`, `rawat_jl_*`, `pemeriksaan_*`). Eloquent tidak
  mendukung PK komposit secara bawaan; pakai query builder untuk update/delete.
- **Tidak ada transaksi/locking** di Khanza untuk nomor rawat dan kamar; Khanza hanya retry 5x. Di Laravel
  bungkus dengan `DB::transaction` + lock atau retry.
- **Kunci billing**: `Sequel.cariRegistrasi(no_rawat)` (`src/fungsi/sekuel.java:1443-1448`) menghitung
  baris `billing` + `reg_periksa` dengan `stts='Batal'`. Kalau > 0 hampir semua perubahan diblokir
  ("Data billing sudah terverifikasi").
- **"Admin Utama"** tidak punya baris di `user`/`pegawai`, dan melewati banyak pengecekan (batas 48 jam,
  tanggal mundur, kunci dokter). String ini dicek 863 kali di 300 file Java.
- Hak akses di Khanza hanya dicek di UI (tombol enable/disable). Di web wajib dicek juga di server
  (Gate/Policy/middleware).
- Penjurnalan akuntansi (`tampjurnal`, jurnal) dapat diabaikan untuk MVP.
