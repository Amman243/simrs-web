# 03 — Rawat Inap

## File

| File | Fungsi |
|---|---|
| `src/simrskhanza/DlgKamarInap.java` (21.744 baris) | Masuk kamar, pindah kamar, pulang, daftar pasien ranap |
| `src/simrskhanza/DlgRawatInap.java` (12.596 baris) | Tindakan & SOAP ranap (cermin DlgRawatJalan) |
| `src/keuangan/DlgCariPerawatanRanap.java` | Katalog + insert tindakan ranap |
| `src/fungsi/pengaturankamarinap.java` | Membaca `set_jam_minimal` |
| `src/simrskhanza/DlgDpjp.java` | DPJP (`insert into dpjp_ranap values(?,?)` 656, hapus 703) |

## 1. Masuk kamar (check-in) — `BtnSimpanActionPerformed` 5852-5883

### Sebelum simpan
- Pasien dipilih dari `DlgCariReg` (5420-5479). Ditolak kalau masih punya rawat inap terbuka (5440-5442):
  ```sql
  select count(pasien.no_rkm_medis) from pasien inner join reg_periksa inner join kamar_inap
  on reg_periksa.no_rkm_medis=pasien.no_rkm_medis and reg_periksa.no_rawat=kamar_inap.no_rawat
  where kamar_inap.stts_pulang='-' and pasien.no_rkm_medis=?
  ```
- Data kamar `isKmr()` (20222-20322):
  ```sql
  select kamar.status,bangsal.kd_bangsal,bangsal.nm_bangsal,kamar.trf_kamar
    from kamar inner join bangsal on kamar.kd_bangsal=bangsal.kd_bangsal where kamar.kd_kamar=?
  select set_harga_kamar.tarif from set_harga_kamar where set_harga_kamar.kd_kamar=? and set_harga_kamar.kd_pj=?
  ```
  Tarif per penjab dari `set_harga_kamar`; kalau tidak ada pakai `kamar.trf_kamar`.
- Diagnosa awal wajib (5859). Kamar harus `'KOSONG'`; `'ISI'` ditolak (5862-5867).

### Simpan (5868-5876)
```sql
INSERT INTO kamar_inap VALUES(no_rawat, kd_kamar, trf_kamar, diagnosa_awal, diagnosa_akhir,
  tgl_masuk, jam_masuk, '0000-00-00', '00:00:00', lama, ttl_biaya, '-');
UPDATE reg_periksa SET status_lanjut='Ranap' WHERE no_rawat=?;
UPDATE kamar SET status='ISI' WHERE kd_kamar=?;
```

### Masuk dari rawat jalan
- `DlgKasirRalan.MnKamarInapActionPerformed` (7995-8025): tolak registrasi `'Batal'` dan yang gagal
  `cariRegistrasi`, lalu `kamarinap.setNoRm(no_rawat, rm, nama)`.
- `setNoRm` (20339-20418): kalau sudah ada baris `kamar_inap` → mode Pulang/Check Out; kalau belum → Check In.
- `MnDIrawat` (DlgKasirRalan 8938-8954) mengubah `reg_periksa.stts='Dirawat'` lebih dulu.

## 2. Lama inap & biaya

Setting dari `set_jam_minimal` (`pengaturankamarinap.java:17-29`):
- `lamajam` — minimal jam untuk dihitung 1 hari.
- `hariawal` — `'Yes'`/`'No'`, apakah hari pertama dihitung.

Rumus (5614-5618, 5995-5999):
```sql
-- hariawal = 'No'
IF(TO_DAYS(keluar)-TO_DAYS(masuk)=0,
   IF(TIME_TO_SEC(keluar)-TIME_TO_SEC(masuk) > 3600*lamajam, 1, 0),
   TO_DAYS(keluar)-TO_DAYS(masuk))
-- hariawal = 'Yes' → ekspresi yang sama + 1
```
`masuk` = `tgl_masuk + ' ' + jam_masuk`; `keluar` = waktu pulang atau `NOW()`.

`ttl_biaya = lama * trf_kamar`, dibulatkan (`DecimalFormat("####")`, `isjml()` 20324-20337).

Refresh harian untuk pasien yang belum pulang, `updateHari()` (20669-20690):
```sql
UPDATE kamar_inap SET lama=<rumus dengan NOW()>, ttl_biaya=<rumus>*trf_kamar
WHERE no_rawat=? AND kd_kamar=? AND tgl_masuk=? AND jam_masuk=?
```

## 3. Pulang (check-out) — `BtnOut` 5577-5625, `BtnSimpan` 5884-5933

- `stts_pulang` tidak boleh `'-'` (5885); diagnosa akhir wajib (5887).
- Simpan (5890-5893, 5929):
  ```sql
  UPDATE kamar_inap SET tgl_keluar=?,trf_kamar=?,jam_keluar=?,ttl_biaya=?,stts_pulang=?,diagnosa_akhir=?,lama=?
   WHERE no_rawat=? AND kd_kamar=? AND tgl_masuk=? AND jam_masuk=?;
  UPDATE kamar SET status='KOSONG' WHERE kd_kamar=?;
  ```
- `'Meninggal'` → buka `DlgPasienMati`; `'Rujuk'` → buka `DlgRujuk` (5904-5926).
- **Pulang tidak mengubah `reg_periksa.status_lanjut` maupun `stts`.**

Nilai `stts_pulang` (`sik.sql:13727`, combo 1148):
`Sehat, Rujuk, APS, +, Meninggal, Sembuh, Membaik, Pulang Paksa, -, Pindah Kamar, Status Belum Lengkap,
Atas Persetujuan Dokter, Atas Permintaan Sendiri, Isoman, Lain-lain`
- `'-'` = masih di kamar.
- `'Pindah Kamar'` = segmen kamar yang ditutup karena pindah.

Aksi status lain:
- Ubah status setelah pulang (7673-7863): `UPDATE kamar_inap SET stts_pulang='Sehat'` dst. + kamar `KOSONG`.
- Batal pulang / "Belum Pulang" (8530): `stts_pulang='-', tgl_keluar='0000-00-00', jam_keluar='00:00:00'`.
- Hapus data salah (7478-7483): hapus baris `kamar_inap`, kamar `KOSONG`; kalau tidak ada baris tersisa,
  `reg_periksa.status_lanjut='Ralan'` dan `stts='Sudah'`.

## 4. Pindah kamar — `BtnSimpanpindahActionPerformed` 7297-7457

Kamar tujuan harus `'KOSONG'`. Ada 4 mode (label 4610-4633):
1. **Mode 1** (7309-7322): insert baris baru (`'-'`, lama default 1), kamar baru `ISI`, **hapus** baris lama,
   kamar lama `KOSONG`.
2. **Mode 2** (7323-7331): `UPDATE kamar_inap SET kd_kamar=baru, trf_kamar=baru, lama=?, ttl_biaya=?` pada
   baris lama, tukar status kedua kamar.
3. **Mode 3 — standar** (7332-7385): hitung `lama` baris lama sampai jam pindah, lalu:
   ```sql
   UPDATE kamar_inap SET trf_kamar=?,tgl_keluar=?,jam_keluar=?,lama=?,ttl_biaya=?,stts_pulang='Pindah Kamar' WHERE <PK lama>;
   UPDATE kamar SET status='KOSONG' WHERE kd_kamar=<lama>;
   INSERT INTO kamar_inap VALUES(no_rawat,kamar_baru,tarif_baru,diag_awal,diag_akhir,tgl_pindah,jam_pindah,'0000-00-00','00:00:00',lama,ttl,'-');
   UPDATE kamar SET status='ISI' WHERE kd_kamar=<baru>;
   ```
4. **Mode 4** (7386-7450): seperti mode 3, tapi segmen lama ditagih dengan tarif tertinggi (lama vs baru).

## 5. Daftar pasien ranap — `tampil()` 20050-20167

```sql
select kamar_inap.no_rawat, reg_periksa.no_rkm_medis, pasien.nm_pasien,
  concat(pasien.alamat,', ',kelurahan.nm_kel,', ',kecamatan.nm_kec,', ',kabupaten.nm_kab) as alamat,
  reg_periksa.p_jawab, reg_periksa.hubunganpj, penjab.png_jawab,
  concat(kamar_inap.kd_kamar,' ',bangsal.nm_bangsal) as kamar, kamar_inap.trf_kamar,
  kamar_inap.diagnosa_awal, kamar_inap.diagnosa_akhir, kamar_inap.tgl_masuk, kamar_inap.jam_masuk,
  if(kamar_inap.tgl_keluar='0000-00-00','',kamar_inap.tgl_keluar) as tgl_keluar,
  if(kamar_inap.jam_keluar='00:00:00','',kamar_inap.jam_keluar) as jam_keluar,
  kamar_inap.ttl_biaya, kamar_inap.stts_pulang, kamar_inap.lama, dokter.nm_dokter, kamar_inap.kd_kamar,
  reg_periksa.kd_pj, concat(reg_periksa.umurdaftar,' ',reg_periksa.sttsumur) as umur, reg_periksa.status_bayar, pasien.agama
from kamar_inap
  inner join reg_periksa on kamar_inap.no_rawat=reg_periksa.no_rawat
  inner join pasien on reg_periksa.no_rkm_medis=pasien.no_rkm_medis
  inner join kamar on kamar_inap.kd_kamar=kamar.kd_kamar
  inner join bangsal on kamar.kd_bangsal=bangsal.kd_bangsal
  inner join kelurahan ... inner join kecamatan ... inner join kabupaten ...
  inner join dokter on reg_periksa.kd_dokter=dokter.kd_dokter
  inner join penjab on reg_periksa.kd_pj=penjab.kd_pj
  [inner join dpjp_ranap on dpjp_ranap.no_rawat=reg_periksa.no_rawat where dpjp_ranap.kd_dokter=? and]
where <filter>
order by bangsal.nm_bangsal, kamar_inap.tgl_masuk, kamar_inap.jam_masuk
```
Filter:
- R1 (masih dirawat): `kamar_inap.stts_pulang='-'`
- R2: `tgl_masuk between ? and ?`
- R3: `tgl_keluar between ? and ?`
- Opsional: `bangsal.nm_bangsal=?`, `reg_periksa.status_bayar like ?`
- Kunci dokter (`KUNCIDOKTERRANAP`): join `dpjp_ranap` untuk dokter yang login.

Rawat gabung ibu-bayi memakai `ranap_gabung` (6036-6042, 20107-20111); tarif bayi
`trf_kamar * set_jam_minimal.bayi / 100`.

## 6. Perawatan ranap — `DlgRawatInap.java`

- Tab (1506-2906): Penanganan Dokter, Petugas, Dokter & Petugas, Pemeriksaan (SOAP), Obstetri, Ginekologi.
- Dokter default `isRawat()` (9728-9760): DPJP dulu (`select kd_dokter from dpjp_ranap where no_rawat=?`),
  fallback `reg_periksa.kd_dokter`.
- Insert tindakan (11639, 11728, 11818) — **tanpa kolom `stts_bayar`** (beda dengan ralan):
  ```
  rawat_inap_dr   (no_rawat,kd_jenis_prw,kd_dokter,tgl,jam,material,bhp,tarif_tindakandr,kso,menejemen,biaya_rawat)
  rawat_inap_pr   (no_rawat,kd_jenis_prw,nip,tgl,jam,material,bhp,tarif_tindakanpr,kso,menejemen,biaya_rawat)
  rawat_inap_drpr (no_rawat,kd_jenis_prw,kd_dokter,nip,tgl,jam,material,bhp,tarif_tindakandr,tarif_tindakanpr,kso,menejemen,biaya_rawat)
  ```
- Katalog tindakan (`DlgCariPerawatanRanap.java`):
  - Cari bangsal & kelas dari rawat inap terbuka (1626-1633):
    `... where kamar_inap.no_rawat=? and kamar_inap.stts_pulang='-' order by STR_TO_DATE(concat(tgl_masuk,' ',jam_masuk),...) desc limit 1`
  - Daftar tindakan (1341-1348): `jns_perawatan_inap.status='1' and (kd_pj=? or kd_pj='-') and (kd_bangsal=? or kd_bangsal='-') and (kelas=? or kelas='-')`.
    Filter mana yang aktif diatur `set_tarif.ruang_ranap / cara_bayar_ranap / kelas_ranap`.
  - Kolom tarif: `total_byrdr` (dr), `total_byrpr` (pr), `total_byrdrpr` (drpr) (1383-1414).
- SOAP ranap (11913-11935), 20 kolom:
  `no_rawat,tgl,jam,suhu,tensi,nadi,respirasi,tinggi,berat,spo2,gcs,kesadaran,keluhan,pemeriksaan,alergi,penilaian,rtl,instruksi,evaluasi,nip`
  — **tanpa `lingkar_perut`, urutan `penilaian` lalu `rtl`** (kebalikan ralan).

## Rencana di Laravel (MVP)

1. **Daftar pasien ranap** (filter: masih dirawat / tgl masuk / tgl keluar / bangsal), refresh `lama` &
   `ttl_biaya` saat ditampilkan.
2. **Masuk kamar** dari registrasi (ralan/IGD): pilih kamar `KOSONG`, tarif dari `set_harga_kamar` → fallback
   `kamar.trf_kamar`; dalam `DB::transaction`: insert `kamar_inap` (`'-'`), `kamar.status='ISI'`,
   `reg_periksa.status_lanjut='Ranap'`, DPJP.
3. **Pindah kamar** mode 3 (standar).
4. **Pulang**: isi `stts_pulang`, diagnosa akhir, hitung `lama`/`ttl_biaya`, kamar `KOSONG`.
5. **Tindakan** `rawat_inap_*` dari `jns_perawatan_inap` sesuai bangsal/kelas/penjab.
6. **SOAP** CRUD `pemeriksaan_ranap`.
