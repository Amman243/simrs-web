# 02 — Registrasi UGD/IGD

## File

| File | Baris | Fungsi |
|---|---|---|
| `src/simrskhanza/DlgReg.java` | 19.790 | Registrasi rawat jalan umum; poli dipilih user. List mengecualikan IGD (`poliklinik.kd_poli<>'IGDK'`, 6902, 6909, 17223, 17230) |
| `src/simrskhanza/DlgIGD.java` | 14.937 | Registrasi IGD; poli **hard-code `'IGDK'`**. List hanya IGD (`kd_poli='IGDK'`, 13038) |
| `src/simrskhanza/DlgPasien.java` | 10.149 | Master pasien / pasien baru |
| `src/rekammedis/RMTriaseIGD.java` | — | Triase IGD (setelah registrasi) |

## Form DlgIGD (4646-4928; batas panjang 415-423)

| Field | Komponen | Keterangan |
|---|---|---|
| No. Reg | `TNoReg` (max 8) | Nomor antrian, otomatis |
| No. Rawat | `TNoRw` (max 17) | Otomatis |
| Tgl. Reg + Jam | `DTPReg`, `CmbJam/CmbMenit/CmbDetik`, `ChkJln` | `ChkJln` = jam berjalan |
| Dokter dituju | `KdDokter` (max 20), `TDokter` | |
| No. RM | `TNoRM` (max 10), `TPasien` | Tombol buka `DlgPasien` |
| Penanggung jawab | `TPngJwb` | Default `pasien.namakeluarga` |
| Hubungan | `THbngn` | Default `pasien.keluarga` |
| Alamat PJ | `TAlmt` | Default alamat lengkap pasien |
| Status | `TStatus` (read-only) | Baru/Lama |
| Jenis bayar | `kdpnj`/`nmpnj` | Default `pasien.kd_pj` |
| Asal rujukan | `AsalRujukan` | Opsional → `rujuk_masuk` |
| Cetak tracer | `ChkTracker` | |

DlgReg punya field yang sama (6284-6622) + Unit/Poli (`kdpoli`, `TPoli`), Biaya (`TBiaya`), No. Kartu BPJS.

## Alur DlgIGD

1. Saat dibuka, ambil tarif IGD (7010-7016):
   `select poliklinik.registrasi,poliklinik.registrasilama,poliklinik.kd_poli from poliklinik where kd_poli='IGDK'`
   Kalau baris tidak ada, dibuat: `insert into poliklinik values('IGDK','Unit IGD','0','0','1')` (5069-5071).
2. Pilih pasien → `isPas()` (13200): cek tagihan belum lunas (opsional), tampilkan catatan pasien,
   lalu `isCekPasien()` (13234-13287) mengisi PJ, alamat, penjab, status Baru/Lama, umur.
3. Pilih dokter → `isNumber()` (6256-6264) membuat `no_reg` dan `no_rawat`.
4. Simpan → `BtnSimpanActionPerformed` (5040-5149).

## INSERT `reg_periksa` (19 kolom, positional)

DlgIGD.java:5077-5080:
```java
Sequel.menyimpantf2("reg_periksa","?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?","No.Rawat",19,
  new String[]{TNoReg, TNoRw, tgl, jam, KdDokter, TNoRM, "IGDK", TPngJwb, TAlmt, THbngn, biaya, "Belum",
  TStatus, "Ralan", kdpnj, umur, sttsumur, "Belum Bayar", status_poli})
```
DlgReg (18078-18081) sama, kecuali `kdpoli` dan `TBiaya`.

| # | Kolom | Nilai |
|---|---|---|
| 1 | `no_reg` | MAX+1, 3 digit zero-pad (`Valid.autoNomer3`, `src/fungsi/validasi.java:153-184`); cakupan sesuai `URUTNOREG` |
| 2 | `no_rawat` | `yyyy/MM/dd/` + 6 digit |
| 3 | `tgl_registrasi` | `Y-m-d` |
| 4 | `jam_reg` | `H:i:s` |
| 5 | `kd_dokter` | dokter dipilih |
| 6 | `no_rkm_medis` | pasien dipilih |
| 7 | `kd_poli` | `'IGDK'` (IGD) / poli dipilih (DlgReg) |
| 8 | `p_jawab` | default `pasien.namakeluarga` |
| 9 | `almt_pj` | default `concat(pasien.alamat,', ',kelurahan.nm_kel,', ',kecamatan.nm_kec,', ',kabupaten.nm_kab)` |
| 10 | `hubunganpj` | default `pasien.keluarga` |
| 11 | `biaya_reg` | `poliklinik.registrasi` kalau Baru, `registrasilama` kalau Lama (DlgIGD 5058-5068) |
| 12 | `stts` | `'Belum'` |
| 13 | `stts_daftar` | `if(pasien.tgl_daftar=<tgl_registrasi>,'Baru','Lama')` — baru di **RS**, bukan di poli (13237) |
| 14 | `status_lanjut` | `'Ralan'` (IGD juga Ralan) |
| 15 | `kd_pj` | default `pasien.kd_pj` |
| 16 | `umurdaftar` | tahun > 0 → tahun; else bulan > 0 → bulan; else hari. Dihitung terhadap `CURDATE()` (13238-13271) |
| 17 | `sttsumur` | `'Th'` / `'Bl'` / `'Hr'` |
| 18 | `status_bayar` | `'Belum Bayar'` |
| 19 | `status_poli` | `'Lama'` kalau pernah ada `reg_periksa` di poli tsb, else `'Baru'` |

### Nomor antrian `no_reg` (DlgIGD 13523-13537)

Mode dari `setting/database.xml` key `URUTNOREG` (saat ini `dokter + poli`):
```sql
-- "poli"
select ifnull(MAX(CONVERT(no_reg,signed)),0) from reg_periksa where kd_poli='IGDK' and tgl_registrasi=?
-- "dokter" (dan default)
select ifnull(MAX(CONVERT(no_reg,signed)),0) from reg_periksa where kd_dokter=? and tgl_registrasi=?
-- "dokter + poli"
select ifnull(MAX(CONVERT(no_reg,signed)),0) from reg_periksa where kd_dokter=? and kd_poli='IGDK' and tgl_registrasi=?
```
- Catatan: fallback DlgIGD saat setting gagal dibaca adalah `"dokter+poli"` (tanpa spasi, baris 520) yang
  **tidak cocok** dengan case `"dokter + poli"` → jatuh ke mode per dokter.
- DlgReg punya mode tambahan `BASENOREG=booking` (17901-17935): ambil max dari `booking_registrasi` dan
  `reg_periksa`. IGD tidak memakai booking.

### Nomor rawat `no_rawat` (DlgIGD 13539, DlgReg 17954)

```sql
select ifnull(MAX(CONVERT(RIGHT(no_rawat,6),signed)),0) from reg_periksa where tgl_registrasi=?
```
Hasil: `yyyy/MM/dd/` + `lpad(max+1, 6, '0')`, contoh `2026/11/01/000001`. Counter **bersama semua poli**
per tanggal.

### Konkurensi

Tidak ada lock. Kalau insert gagal (PK duplikat), nomor dibuat ulang dan dicoba lagi, maks 5 kali
(DlgIGD 5082-5118, DlgReg 18083-18114). Di Laravel: `DB::transaction` + `lockForUpdate`/retry.

### Setelah insert berhasil (DlgIGD 5123-5146)

- `UpdateUmur()` (13652): `update pasien set umur=CONCAT(<Y>,' Th ',<M>,' Bl ',<D>,' Hr') where no_rkm_medis=?`
- Kalau Asal Rujukan diisi → insert `rujuk_masuk` dengan `no_balasan = 'BR/yyyy/MM/dd/' + 4 digit`
  (MAX(RIGHT(rujuk_masuk.no_rawat,4))+1 per tanggal). Nilai: `(no_rawat, perujuk, alamat, no_rujuk '-',
  jm_perujuk 0, dokter_perujuk=perujuk, '-','-','-', no_balasan)`.
- Cetak tracer bila dicentang.

### Edit & status

- Edit (5261-5319): update kolom yang sama. Diblokir kalau `cariRegistrasi(no_rawat)>0` (ada billing /
  Batal) dan butuh permission `edit_registrasi`.
- Perubahan `stts` di DlgIGD (7781-8463): Sudah / Batal (juga `biaya_reg='0'`) / Dirujuk / Dirawat /
  Meninggal / Pulang Paksa / Berkas Diterima.
- Hapus IGD tanpa batas waktu; DlgReg membatasi non-admin 48 jam (`cekTanggal48jam`, 6860).

## Validasi

1. Wajib: No.Reg, No.Rawat, dokter, No.RM + nama pasien, kode + nama penjab (DlgIGD 5041-5050).
2. **Pasien sedang dirawat inap → ditolak** (DlgIGD 5051-5055, DlgReg 6808-6812),
   pesan "Pasien sedang dalam masa perawatan di kamar inap..!!":
   ```sql
   select count(pasien.no_rkm_medis) from pasien inner join reg_periksa on reg_periksa.no_rkm_medis=pasien.no_rkm_medis
   inner join kamar_inap on reg_periksa.no_rawat=kamar_inap.no_rawat where kamar_inap.stts_pulang='-' and pasien.no_rkm_medis=?
   ```
3. Tagihan belum lunas — hanya kalau `set_validasi_registrasi.wajib_closing_kasir='Yes'`
   (`src/fungsi/closingkasir.java`), dicek saat pasien dipilih:
   ```sql
   select count(no_rkm_medis) from reg_periksa where no_rkm_medis=? and status_bayar='Belum Bayar' and stts<>'Batal'
   ```
4. Catatan pasien (`catatan_pasien`) ditampilkan bila `set_validasi_catatan.tampilkan_catatan='Yes'` (info saja).
5. **Tidak ada cek "sudah terdaftar hari ini"** di DlgReg/DlgIGD/DlgPasien. Pasien bisa didaftarkan beberapa
   kali sehari. Kalau aturan ini diinginkan, tambahkan sendiri di Laravel.

## IGD vs registrasi rawat jalan biasa

- Dialog terpisah, `kd_poli='IGDK'` hard-code (tidak ada setting). Literal yang sama dipakai di bridging BPJS
  (`src/bridging/BPJSCekKartu.java:7364-7368`), SatuSehat, dan laporan.
- Tidak ada pilihan poli dan biaya tidak bisa diubah (selalu dari baris poliklinik `IGDK`).
- `status_lanjut='Ralan'`, `stts`, `status_bayar`, generator `no_rawat` sama dengan ralan.
- Tanpa cek jadwal/kuota dokter (DlgReg cek bila `JADWALDOKTERDIREGISTRASI=aktif` dan bukan Admin Utama,
  6817-6823; saat ini `nonaktif`).
- Tanpa nomor antrian booking dan tanpa kunci tanggal mundur (`TANGGALMUNDUR=no` hanya di DlgReg).

## Pasien baru (`DlgPasien.java`)

Validasi simpan (3705-3773): `no_rkm_medis`, `nm_pasien`, `kd_pj` wajib; Suku, Cacat, Bahasa, Perusahaan
wajib; kalau TNI/Polri dicentang → golongan/satuan/pangkat/jabatan wajib. Panjang minimum field lain dari
`set_kelengkapan_data_pasien` (8594-8636).

Wilayah (3775-3813): `insert ignore into kelurahan/kecamatan/kabupaten/propinsi values('0', nama)` lalu cari
kodenya berdasarkan nama. Mode input bebas/picker diatur `set_alamat_pasien`.

Insert (3816-3824): 36 kolom positional. `jk` = huruf pertama LAKI-LAKI/PEREMPUAN; placeholder `"ALAMAT"`
dihapus; `umur` = `"<Th> Th <Bl> Bl <Hr> Hr"`. Opsional `pasien_tni` / `pasien_polri`.

### No. RM otomatis (`autoNomor()`, 10030-10082)

Setting `set_urut_no_rkm_medis` (seed `('Straight','No','No','Depan')`) dan `set_no_rkm_medis` (1 baris =
nomor terakhir).
- Straight: `select ifnull(MAX(CONVERT(RIGHT(no_rkm_medis,6),signed)),0) from set_no_rkm_medis` + 1, pad 6.
- Terminal/Middle: 6 digit diacak per pasangan (`autoNomer4`/`autoNomer5`, `validasi.java:186-250`).
- Prefix/suffix tahun-bulan: `yy`+`MM` di depan, atau `-MMyy` di belakang.
- Setelah insert sukses: `delete from set_no_rkm_medis; insert into set_no_rkm_medis values(<no baru>)` (3841-3842).
- Gagal → buat ulang, maks 5 kali.

Nilai combo: gol_darah `- A B AB O`; agama `ISLAM, KRISTEN, KATOLIK, HINDU, BUDHA, KONG HU CHU, -`;
stts_nikah `MENIKAH, BELUM MENIKAH, JANDA, DUDHA`; pnd `-, TS … S3`; keluarga default `DIRI SENDIRI`.

## Triase IGD (`RMTriaseIGD.java` 2028-2170)

Insert `data_triase_igd`, lalu salah satu:
- **Primer**: `data_triase_igdprimer` + detail skala 1/2; plan `Ruang Resusitasi` / `Ruang Kritis`.
- **Sekunder**: `data_triase_igdsekunder` + detail skala 3/4/5; plan `Zona Kuning` / `Zona Hijau`.

Struktur tabel triase ada di [05-skema-tabel.md](05-skema-tabel.md).

## Rencana di Laravel (MVP)

1. Komponen Livewire `pages::igd.registrasi`: cari pasien (no RM / nama / NIK), pilih dokter, penjab, PJ.
2. Service `RegistrasiService::daftar()` dalam transaksi: cek pasien sedang ranap → hitung `no_reg`,
   `no_rawat`, `biaya_reg`, `stts_daftar`, `status_poli`, umur → insert `reg_periksa` → update `pasien.umur`
   → opsional `rujuk_masuk`. Retry saat PK duplikat.
3. Daftar pasien IGD hari ini (`kd_poli='IGDK'`) + ubah status.
4. Form pasien baru (tahap berikutnya), triase (tahap berikutnya).
