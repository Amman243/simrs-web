# 05 — Skema Tabel (ringkasan dari `sik.sql`)

Nomor = baris di `simrs-khanza/sik.sql`. Index non-PK diringkas. `NN` = NOT NULL.

## Autentikasi & SDM

```sql
-- 25
CREATE TABLE admin (usere text, passworde text) ENGINE=MyISAM;               -- AES_ENCRYPT 'nur' / 'windi'
-- 42488
CREATE TABLE user (id_user varchar(700) NN PK, password text NN,              -- AES_ENCRYPT 'nur' / 'windi'
  <1222 kolom permission enum('true','false')>) ENGINE=MyISAM;               -- 151 NN, 1071 DEFAULT NULL
-- 19022
CREATE TABLE pegawai (id int AI PK, nik varchar(20) UNIQUE, nama varchar(50), jk enum('Pria','Wanita'),
  jbtn varchar(25), departemen, bidang, stts_aktif enum('AKTIF','CUTI','KELUAR','TENAGA LUAR','NON AKTIF'), photo, no_ktp, ...);
-- 28257
CREATE TABLE petugas (nip varchar(20) PK FK->pegawai.nik, nama varchar(50), kd_jbtn char(4) FK->jabatan, email, status enum('0','1'), ...);
-- 9016
CREATE TABLE dokter (kd_dokter varchar(20) PK FK->pegawai.nik, nm_dokter varchar(50), jk, tmp_lahir, tgl_lahir, gol_drh, agama,
  almt_tgl varchar(60), no_telp varchar(13), email varchar(70) NN, stts_nikah, kd_sps char(5) FK->spesialis, alumni,
  no_ijn_praktek varchar(120), status enum('0','1') NN);
-- 12582 jabatan (kd_jbtn, nm_jbtn) · 36843 spesialis (kd_sps, nm_sps)
-- 42317
CREATE TABLE tracker (nip varchar(20), tgl_login date, jam_login time, PK(nip,tgl_login,jam_login));
```

## Pasien & registrasi

```sql
-- 17676
CREATE TABLE pasien (
  no_rkm_medis varchar(15) PK, nm_pasien varchar(40), no_ktp varchar(20), jk enum('L','P'), tmp_lahir varchar(15), tgl_lahir date,
  nm_ibu varchar(40) NN, alamat varchar(200), gol_darah enum('A','B','O','AB','-'), pekerjaan varchar(60),
  stts_nikah enum('BELUM MENIKAH','MENIKAH','JANDA','DUDHA','JOMBLO'), agama varchar(12), tgl_daftar date, no_tlp varchar(40),
  umur varchar(30) NN, pnd enum('TS','TK','SD','SMP','SMA','SLTA/SEDERAJAT','D1','D2','D3','D4','S1','S2','S3','-') NN,
  keluarga enum('AYAH','IBU','ISTRI','SUAMI','SAUDARA','ANAK','DIRI SENDIRI','LAIN-LAIN'), namakeluarga varchar(50) NN,
  kd_pj char(3) NN, no_peserta varchar(25), kd_kel int NN, kd_kec int NN, kd_kab int NN, pekerjaanpj varchar(35) NN,
  alamatpj varchar(100) NN, kelurahanpj varchar(60) NN, kecamatanpj varchar(60) NN, kabupatenpj varchar(60) NN,
  perusahaan_pasien varchar(8) NN, suku_bangsa int NN, bahasa_pasien int NN, cacat_fisik int NN, email varchar(50) NN,
  nip varchar(30) NN, kd_prop int NN, propinsipj varchar(30) NN);
-- FK (ON UPDATE CASCADE): kd_pj->penjab, kd_kel->kelurahan, kd_kec->kecamatan, kd_kab->kabupaten, kd_prop->propinsi,
--   perusahaan_pasien->perusahaan_pasien.kode_perusahaan, suku_bangsa->suku_bangsa.id, bahasa_pasien->bahasa_pasien.id,
--   cacat_fisik->cacat_fisik.id

-- 29081
CREATE TABLE reg_periksa (
  no_reg varchar(8), no_rawat varchar(17) NN PK, tgl_registrasi date, jam_reg time, kd_dokter varchar(20),
  no_rkm_medis varchar(15), kd_poli char(5), p_jawab varchar(100), almt_pj varchar(200), hubunganpj varchar(20),
  biaya_reg double,
  stts enum('Belum','Sudah','Batal','Berkas Diterima','Dirujuk','Meninggal','Dirawat','Pulang Paksa'),
  stts_daftar enum('-','Lama','Baru') NN, status_lanjut enum('Ralan','Ranap') NN, kd_pj char(3) NN,
  umurdaftar int, sttsumur enum('Th','Bl','Hr'), status_bayar enum('Sudah Bayar','Belum Bayar') NN,
  status_poli enum('Lama','Baru') NN);
-- FK: kd_poli->poliklinik, kd_dokter->dokter (CASCADE), kd_pj->penjab, no_rkm_medis->pasien (ON UPDATE CASCADE)

-- 28515
CREATE TABLE poliklinik (kd_poli char(5) PK, nm_poli varchar(50), registrasi double NN, registrasilama double NN, status enum('0','1') NN);
-- seed: ('IGDK','IGD',10000,10000,'1')
-- 26566
CREATE TABLE penjab (kd_pj char(3) PK, png_jawab varchar(30), nama_perusahaan varchar(60), alamat_asuransi varchar(130),
  no_telp varchar(40), attn varchar(60), status enum('0','1'));   -- semua NN
```

## Setting

```sql
-- 33763 set_no_rkm_medis (no_rkm_medis varchar(15))                        -- 1 baris = nomor RM terakhir
-- 34285 set_urut_no_rkm_medis (urutan enum('Straight','Middle','Terminal') PK, tahun enum('Yes','No'),
--        bulan enum('Yes','No'), posisi_tahun_bulan enum('Depan','Belakang'))
-- 34333 set_validasi_registrasi (wajib_closing_kasir enum('Yes','No'))
-- 33587 set_kelengkapan_data_pasien · 33208 set_alamat_pasien
-- 33332 set_harga_kamar (kd_kamar, kd_pj, tarif)
-- 33510 set_jam_minimal (lamajam, hariawal, bayi, diagnosaakhir, ...)
-- 34122 set_tarif (poli_ralan, cara_bayar_ralan, ruang_ranap, cara_bayar_ranap, kelas_ranap, ...)
-- 30319 rujuk_masuk
```

## Rawat inap

```sql
-- 1899
CREATE TABLE bangsal (kd_bangsal char(5) PK, nm_bangsal varchar(30), status enum('0','1'));
-- 13681
CREATE TABLE kamar (kd_kamar varchar(15) PK, kd_bangsal char(5) FK->bangsal, trf_kamar double,
  status enum('ISI','KOSONG','DIBERSIHKAN','DIBOOKING','PERBAIKAN'),
  kelas enum('Kelas 1','Kelas 2','Kelas 3','Kelas Utama','Kelas VIP','Kelas VVIP'), statusdata enum('0','1'));
-- 13715
CREATE TABLE kamar_inap (no_rawat varchar(17) NN, kd_kamar varchar(15) NN, trf_kamar double,
  diagnosa_awal varchar(100), diagnosa_akhir varchar(100),
  tgl_masuk date NN DEFAULT '0000-00-00', jam_masuk time NN DEFAULT '00:00:00',
  tgl_keluar date, jam_keluar time, lama double, ttl_biaya double,
  stts_pulang enum('Sehat','Rujuk','APS','+','Meninggal','Sembuh','Membaik','Pulang Paksa','-','Pindah Kamar',
    'Status Belum Lengkap','Atas Persetujuan Dokter','Atas Permintaan Sendiri','Isoman','Lain-lain') NN,
  PRIMARY KEY (no_rawat, tgl_masuk, jam_masuk));                              -- FK kd_kamar->kamar, no_rawat->reg_periksa
-- 9068
CREATE TABLE dpjp_ranap (no_rawat varchar(17) NN, kd_dokter varchar(20) NN, PRIMARY KEY (no_rawat, kd_dokter));
-- 28666 ranap_gabung (rawat gabung ibu-bayi)
```

## Tindakan

```sql
-- 13899 kategori_perawatan (kd_kategori, nm_kategori)
-- 13118
CREATE TABLE jns_perawatan (kd_jenis_prw varchar(15) PK, nm_perawatan varchar(80), kd_kategori char(5),
  material double, bhp double, tarif_tindakandr double, tarif_tindakanpr double, kso double, menejemen double,
  total_byrdr double, total_byrpr double, total_byrdrpr double, kd_pj char(3) NN, kd_poli char(5) NN, status enum('0','1') NN);
-- 13172
CREATE TABLE jns_perawatan_inap (... kolom harga sama ..., kd_kategori char(5) NN, kd_pj char(3) NN, kd_bangsal char(5) NN,
  status enum('0','1') NN, kelas enum('-','Kelas 1','Kelas 2','Kelas 3','Kelas Utama','Kelas VIP','Kelas VVIP') NN);

-- 28694 rawat_inap_dr
CREATE TABLE rawat_inap_dr (no_rawat varchar(17), kd_jenis_prw varchar(15), kd_dokter varchar(20), tgl_perawatan date, jam_rawat time,
  material double NN, bhp double NN, tarif_tindakandr double NN, kso double, menejemen double, biaya_rawat double,
  PRIMARY KEY (no_rawat, kd_jenis_prw, kd_dokter, tgl_perawatan, jam_rawat));        -- FK jns_perawatan_inap
-- 28778 rawat_inap_pr   : nip menggantikan kd_dokter, tarif_tindakanpr; PK (no_rawat,kd_jenis_prw,nip,tgl_perawatan,jam_rawat)
-- 28736 rawat_inap_drpr : kd_dokter + nip, tarif_tindakandr + tarif_tindakanpr; PK (no_rawat,kd_jenis_prw,kd_dokter,nip,tgl_perawatan,jam_rawat)

-- 28818 rawat_jl_dr / 28903 rawat_jl_pr / 28859 rawat_jl_drpr
--   sama dengan rawat_inap_* + kolom terakhir stts_bayar enum('Sudah','Belum','Suspen'); FK jns_perawatan
```

## Pemeriksaan (SOAP)

```sql
-- 19876
CREATE TABLE pemeriksaan_ralan (no_rawat varchar(17) NN, tgl_perawatan date NN, jam_rawat time NN,
  suhu_tubuh varchar(5), tensi varchar(8) NN, nadi varchar(3), respirasi varchar(3), tinggi varchar(5), berat varchar(5),
  spo2 varchar(3) NN, gcs varchar(10),
  kesadaran enum('Compos Mentis','Somnolence','Sopor','Coma','Alert','Confusion','Voice','Pain','Unresponsive','Apatis','Delirium','Meninggal') NN,
  keluhan varchar(2000), pemeriksaan varchar(2000), alergi varchar(80), lingkar_perut varchar(5),
  rtl varchar(2000) NN, penilaian varchar(2000) NN, instruksi varchar(2000) NN, evaluasi varchar(2000) NN, nip varchar(20) NN,
  PRIMARY KEY (no_rawat, tgl_perawatan, jam_rawat));                          -- FK nip->pegawai.nik
-- 19923
-- pemeriksaan_ranap: sama, TANPA lingkar_perut, urutan ... alergi, penilaian, rtl, instruksi, evaluasi, nip
```

## Triase IGD

```sql
-- 7176
CREATE TABLE data_triase_igd (no_rawat PK FK->reg_periksa CASCADE, tgl_kunjungan datetime,
  cara_masuk enum('Jalan','Brankar','Kursi Roda','Digendong'), alat_transportasi enum('-','AGD','Sendiri','Swasta'),
  alasan_kedatangan enum('Datang Sendiri','Polisi','Rujukan','Bidan','Puskesmas','Rumah Sakit','Poliklinik','Faskes Lain','-'),
  keterangan_kedatangan varchar(100), kode_kasus varchar(3) FK->master_triase_macam_kasus,
  tekanan_darah varchar(8), nadi varchar(3), pernapasan varchar(3), suhu varchar(5), saturasi_o2 varchar(3), nyeri varchar(5),
  <11 kolom id_observation_* / id_composition varchar(40) NULL>);
-- 7362
CREATE TABLE data_triase_igdprimer (no_rawat PK FK->data_triase_igd, keluhan_utama varchar(400),
  kebutuhan_khusus enum('-','UPPA','Airborne','Dekontaminan'), catatan varchar(100),
  plan enum('Ruang Resusitasi','Ruang Kritis'), tanggaltriase datetime, nik FK->pegawai, id_* ...);
-- 7399
CREATE TABLE data_triase_igdsekunder (no_rawat PK FK, anamnesa_singkat varchar(400), catatan,
  plan enum('Zona Kuning','Zona Hijau'), tanggaltriase, nik, id_* ...);
-- 7225-7335 data_triase_igddetail_skala1..5 (PK no_rawat + kode_skalaN, id_observation_skalaN)
-- 16210 master_triase_macam_kasus (kode_kasus varchar(3), macam_kasus)
-- 16234 master_triase_pemeriksaan · 16258-16366 master_triase_skala1..5 (kode_pemeriksaan, kode_skalaN PK, pengkajian_skalaN)
```

## Lain-lain

- `billing` — dipakai `Sequel.cariRegistrasi()` untuk mengecek kunci tagihan.
- Wilayah: `kelurahan`, `kecamatan`, `kabupaten`, `propinsi`; `suku_bangsa`, `bahasa_pasien`, `cacat_fisik`
  (id auto-increment + nama unik, ada baris seed `'-'`); `perusahaan_pasien` (key varchar(8)).
