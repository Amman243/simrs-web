# 04 — Rawat Jalan

## File

| File | Fungsi |
|---|---|
| `src/simrskhanza/DlgKasirRalan.java` | **Worklist** pasien rawat jalan + ubah status. Membuka `DlgRawatJalan` per `no_rawat` |
| `src/simrskhanza/DlgRawatJalan.java` (14.831 baris) | Tindakan, SOAP, diagnosa, catatan dokter |

> Daftar pasien ralan **bukan** di `DlgRawatJalan`, tetapi di `DlgKasirRalan`.

## 1. Worklist — `DlgKasirRalan.tampilkasir()` 16504-16590

```sql
select reg_periksa.no_reg, reg_periksa.no_rawat, reg_periksa.tgl_registrasi, reg_periksa.jam_reg,
  reg_periksa.kd_dokter, dokter.nm_dokter, reg_periksa.no_rkm_medis, pasien.nm_pasien, poliklinik.nm_poli,
  reg_periksa.p_jawab, reg_periksa.almt_pj, reg_periksa.hubunganpj, reg_periksa.biaya_reg, reg_periksa.stts,
  penjab.png_jawab, concat(reg_periksa.umurdaftar,' ',reg_periksa.sttsumur) as umur,
  reg_periksa.status_bayar, reg_periksa.status_poli, reg_periksa.kd_pj, reg_periksa.kd_poli, pasien.no_tlp
from reg_periksa
  inner join dokter on reg_periksa.kd_dokter=dokter.kd_dokter
  inner join pasien on reg_periksa.no_rkm_medis=pasien.no_rkm_medis
  inner join poliklinik on reg_periksa.kd_poli=poliklinik.kd_poli
  inner join penjab on reg_periksa.kd_pj=penjab.kd_pj
where reg_periksa.tgl_registrasi between ? and ? and reg_periksa.status_lanjut='Ralan'
  and reg_periksa.kd_pj like ? and poliklinik.nm_poli like ? and dokter.nm_dokter like ?
  and reg_periksa.stts like ? and reg_periksa.status_bayar like ? and (<pencarian LIKE>)
order by reg_periksa.no_rawat desc
```
- **Kunci dokter** (16994-17027): kalau user login adalah dokter (`Sequel.CariDokter(akses.getkode())`) dan
  tidak punya `akses_dokter_lain_rawat_jalan`, filter dokter dikunci ke dirinya.
- **Kunci poli** (17029+): dari setting `POLIAKTIFKASIRRALAN`.

## 2. Ubah status `reg_periksa.stts`

Semua ditolak kalau sudah ada baris `kamar_inap`:

| Status | Lokasi | SQL |
|---|---|---|
| Sudah | 7723-7739 | `update reg_periksa set stts='Sudah' where no_rawat=?` + upsert `mutasi_berkas` |
| Belum | 7741-7755 | `stts='Belum'` |
| Batal | 8247-8264 | `stts='Batal', biaya_reg='0'`; juga diblokir `cariRegistrasi` |
| Dirujuk | 8891 | `stts='Dirujuk'` |
| Dirawat | 8945 | `stts='Dirawat'` |

Enum: `Belum, Sudah, Batal, Berkas Diterima, Dirujuk, Meninggal, Dirawat, Pulang Paksa`.

## 3. `DlgRawatJalan.java`

- Dibuka dari `DlgKasirRalan.MnDataRalan` (7757-7800): `SetPoli(kd_poli)`, `SetPj(kd_pj)`,
  `setNoRm(no_rawat, tgl1, tgl2)` (11231). `isRawat()` (11200-11229) memuat pasien + dokter default
  (`reg_periksa.kd_dokter`).
- Tab (1842-3354), indeks untuk `simpan()`:
  0 Penanganan Dokter · 1 Petugas · 2 Dokter & Petugas · 3 Pemeriksaan (SOAP) · 4 Obstetri ·
  5 Ginekologi · 6 Diagnosa · 7 Catatan Dokter

### Katalog tindakan — `tampilTindakanDr()` 12528-12533

(Saat `set_tarif.poli_ralan='Yes'` dan `cara_bayar_ralan='Yes'`.)
```sql
select jns_perawatan.kd_jenis_prw, jns_perawatan.nm_perawatan, kategori_perawatan.nm_kategori,
  jns_perawatan.total_byrdr, jns_perawatan.total_byrpr, jns_perawatan.total_byrdrpr,
  jns_perawatan.bhp, jns_perawatan.material, jns_perawatan.tarif_tindakandr, jns_perawatan.tarif_tindakanpr,
  jns_perawatan.kso, jns_perawatan.menejemen
from jns_perawatan inner join kategori_perawatan on jns_perawatan.kd_kategori=kategori_perawatan.kd_kategori
where jns_perawatan.total_byrdr>0 and jns_perawatan.status='1'
  and (jns_perawatan.kd_pj=? or jns_perawatan.kd_pj='-')
  and (jns_perawatan.kd_poli=? or jns_perawatan.kd_poli='-')
  and (kd_jenis_prw like ? or nm_perawatan like ? or nm_kategori like ?)
order by jns_perawatan.nm_perawatan
```
Tab petugas pakai `total_byrpr>0` (12661); tab dokter & petugas `total_byrdrpr>0` (12790).

### Insert tindakan

Satu baris per tindakan yang dicentang, dalam transaksi (+ jurnal `tampjurnal`, bisa diabaikan di MVP):

| Tabel | Lokasi | Kolom |
|---|---|---|
| `rawat_jl_dr` | 12920-12924 | `no_rawat, kd_jenis_prw, kd_dokter, tgl, jam, material, bhp, tarif_tindakandr, kso, menejemen, biaya_rawat=total_byrdr, stts_bayar='Belum'` |
| `rawat_jl_pr` | 13018-13022 | `no_rawat, kd_jenis_prw, nip, tgl, jam, material, bhp, tarif_tindakanpr, kso, menejemen, total_byrpr, 'Belum'` |
| `rawat_jl_drpr` | 13116-13121 | `no_rawat, kd_jenis_prw, kd_dokter, nip, tgl, jam, material, bhp, tarif_tindakandr, tarif_tindakanpr, kso, menejemen, total_byrdrpr, 'Belum'` |

- Hapus (5229, 5360, 5491) berdasarkan PK penuh. Non-admin hanya boleh hapus dalam 48 jam
  (`cekTanggal48jam`, 5245).
- Daftar tindakan yang sudah diberikan `tampilDr()` (10998-11009):
  ```sql
  ... from pasien inner join reg_periksa on reg_periksa.no_rkm_medis=pasien.no_rkm_medis
  inner join rawat_jl_dr on rawat_jl_dr.no_rawat=reg_periksa.no_rawat
  inner join jns_perawatan on rawat_jl_dr.kd_jenis_prw=jns_perawatan.kd_jenis_prw
  inner join dokter on rawat_jl_dr.kd_dokter=dokter.kd_dokter
  where rawat_jl_dr.tgl_perawatan between ? and ? and reg_periksa.no_rkm_medis like ?
  order by rawat_jl_dr.no_rawat, rawat_jl_dr.tgl_perawatan, rawat_jl_dr.jam_rawat desc
  ```
  `tampilPr` (11063) dan `tampilDrPr` (11131) serupa, join `petugas`.

### SOAP — `pemeriksaan_ralan`

- Insert (14668-14672), 21 kolom:
  `no_rawat, tgl, jam, suhu, tensi, nadi, respirasi, tinggi, berat, spo2, gcs, kesadaran, keluhan,
  pemeriksaan, alergi, lingkar_perut, rtl, penilaian, instruksi, evaluasi, nip`
  — **urutan `rtl` lalu `penilaian`** (kebalikan `pemeriksaan_ranap`), dan ada `lingkar_perut`.
- Non-admin hanya bisa menyimpan atas nama dirinya: `akses.getkode()==KdPeg` (14687).
- Edit (6308-6320) berdasarkan `(no_rawat, tgl_perawatan, jam_rawat)`; hapus 5626.
- Daftar (12047-12059) join `pegawai on pemeriksaan_ralan.nip=pegawai.nik`.

### Menutup dialog (6009-6021)

Ditanya apakah pasien sudah diperiksa. Kalau ya: `update reg_periksa set stts='Sudah' where no_rawat=?`
+ `mutasi_berkas`.

## Rencana di Laravel (MVP)

1. **Worklist ralan** (query di atas) dengan filter tanggal, poli, dokter, status; kunci dokter untuk user
   dokter.
2. Halaman **detail kunjungan** per `no_rawat`:
   - Tindakan dokter/petugas/dokter+petugas dari katalog `jns_perawatan` → `rawat_jl_*`, `stts_bayar='Belum'`.
   - SOAP CRUD `pemeriksaan_ralan`.
3. **Ubah status** Sudah/Belum/Batal; tolak bila ada `kamar_inap` atau billing terkunci (`cariRegistrasi`).
4. Aksi "Rawat Inap" → ke alur masuk kamar ([03-rawat-inap.md](03-rawat-inap.md)).
