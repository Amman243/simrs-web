# Panduan Pengembangan simrs-web

Versi web SIMRS Khanza. Stack: Laravel 13, Livewire 4 (single-file component), Alpine.js (bawaan Livewire),
Tailwind CSS 4. Database: MySQL/MariaDB **`sik`** milik Khanza (dipakai bersama aplikasi desktop).
Catatan perilaku Khanza ada di [docs/khanza/](khanza/README.md) — **baca dulu sebelum mengubah data**.

## Aturan wajib

1. **Jangan** `php artisan migrate`, `db:seed`, `migrate:fresh` ke `sik`/`sik_test`. Tidak ada migration.
2. **Jangan** pakai `RefreshDatabase`/`DatabaseMigrations` di tes. Pakai `DatabaseTransactions`.
3. Tulis data dengan **nama kolom eksplisit** (Khanza memakai insert positional; urutan kolom tabel bisa beda).
4. Operasi multi-tabel (registrasi, masuk/pindah/pulang kamar, simpan tindakan) wajib di `DB::transaction()`.
5. Hak akses **dicek di server**: middleware `can:<kolom>` di route + `$this->authorize('<kolom>')` /
   `Gate::authorize()` di aksi Livewire yang mengubah data. Ability = nama kolom tabel `user` yang terdaftar di
   `config/khanza.php` → `permissions`.
6. Hormati kunci Khanza: `App\Services\Khanza\Registrasi::terkunci($noRawat)` → tolak perubahan.
7. Tanggal nol Khanza (`'0000-00-00'`, `'00:00:00'`) adalah nilai sah; koneksi sudah non-strict.
8. Teks UI dalam Bahasa Indonesia.

## Struktur & kepemilikan file

| Area | Lokasi | Pemilik |
|---|---|---|
| Fondasi: auth, layout, komponen UI, model bersama, service bersama, config, `routes/web.php` | `app/Auth`, `app/Models`, `app/Services/Khanza`, `resources/views/layouts`, `resources/views/components/ui`, `config/` | Lead |
| Registrasi IGD + pasien baru | `app/Services/Igd/`, `resources/views/pages/igd/`, `routes/modules/igd.php`, `tests/Feature/Igd/` | Modul IGD |
| Rawat jalan | `app/Services/RawatJalan/`, `resources/views/pages/rawat-jalan/`, `routes/modules/rawat-jalan.php`, `tests/Feature/RawatJalan/` | Modul Rawat Jalan |
| Rawat inap | `app/Services/RawatInap/`, `resources/views/pages/rawat-inap/`, `routes/modules/rawat-inap.php`, `tests/Feature/RawatInap/` | Modul Rawat Inap |

Butuh sesuatu di area fondasi? Buat versi lokal di folder modul sendiri, lalu laporkan ke lead.

## Route antar modul (kontrak — nama route jangan diubah)

| Nama | URL | Keterangan |
|---|---|---|
| `dashboard` | `/` | |
| `igd.index` | `/igd` | Daftar + form registrasi IGD |
| `igd.pasien-baru` | `/igd/pasien-baru` | Form pasien baru (`can:pasien`) |
| `rawat-jalan.index` | `/rawat-jalan` | Worklist rawat jalan (termasuk pasien IGD, karena IGD = Ralan) |
| `rawat-jalan.show` | `/rawat-jalan/{noRawat}` | Tindakan + SOAP satu kunjungan |
| `rawat-inap.index` | `/rawat-inap` | Daftar pasien ranap |
| `rawat-inap.masuk` | `/rawat-inap/masuk?no_rawat=...` | Masuk kamar untuk registrasi tertentu |
| `rawat-inap.show` | `/rawat-inap/{noRawat}` | Tindakan + SOAP ranap |

`{noRawat}` berformat `yyyy/MM/dd/NNNNNN` dan boleh mengandung `/` (pola di `routes/web.php`):
`route('rawat-jalan.show', ['noRawat' => $row->no_rawat])`.

## Yang sudah tersedia

**Auth** — `auth()->user()` adalah `App\Auth\KhanzaUser`: `kode`, `nama`, `jabatan`, `isAdmin`,
`kdDokter` (bila user dokter), `nip` (bila petugas), `hasPermission('igd')`, `can('igd')`.

**Model** (`app/Models`, PK string, tanpa timestamps): `Pasien`, `Dokter` (`aktif()`), `Petugas` (`aktif()`),
`Pegawai`, `Poliklinik` (`aktif()`), `Penjab` (`aktif()`), `RegPeriksa` (relasi pasien/dokter/poliklinik/penjab),
`Bangsal`, `Kamar` (`kosong()`). Tabel PK komposit → query builder di service modul.

**Service bersama** (`app/Services/Khanza`):
- `NomorRawat::noRawat(Carbon $tgl)`, `NomorRawat::noReg($kdDokter, $kdPoli, Carbon $tgl)`
- `Registrasi::terkunci($noRawat)`, `Registrasi::sedangDirawatInap($noRm)`, `Registrasi::punyaKamarInap($noRawat)`
- `Umur::untukRegistrasi($tglLahir)` → `['umurdaftar','sttsumur']`, `Umur::teks($tglLahir)` → `"34 Th 2 Bl 5 Hr"`

**Config** `config('khanza.kd_poli_igd')` = `'IGDK'`, `config('khanza.urut_no_reg')`.

**Komponen UI** (`resources/views/components/ui`):
```blade
<x-ui.page-header title="..." subtitle="..."><x-slot:actions>...</x-slot:actions></x-ui.page-header>
<x-ui.card title="..." subtitle="..." :padding="false"><x-slot:actions>...</x-slot:actions> ... </x-ui.card>
<x-ui.button variant="primary|secondary|accent|danger|ghost" size="sm|md" wire:click="simpan" href="...">Simpan</x-ui.button>
<x-ui.input label="Nama" wire:model="nama" required />            {{-- error validasi tampil otomatis --}}
<x-ui.select label="Dokter" wire:model="kd_dokter" :options="$dokter" placeholder="Pilih" />
<x-ui.textarea label="Keluhan" wire:model="keluhan" rows="3" />
<x-ui.table><x-slot:head><th>No</th></x-slot:head> <tr><td>..</td></tr> <x-ui.empty colspan="5" /></x-ui.table>
<x-ui.badge color="green|yellow|red|blue|orange|gray">..</x-ui.badge>
<x-ui.status :value="$row->stts" />                                {{-- warna otomatis untuk status Khanza --}}
<x-ui.modal wire:model="showPindah" title="Pindah Kamar" maxWidth="2xl">...<x-slot:footer>..</x-slot:footer></x-ui.modal>
```
Notifikasi: `$this->dispatch('toast', message: 'Tersimpan', type: 'success|error|warning|info');`

**Warna** (Tailwind): `brand-*` (hijau, `brand-500` = #5D9253, `brand-600` = #557945), `accent-*` (kuning,
`accent-400` = #FFCB3D). Aksi utama `brand-500`, sorotan/aktif `accent-400`, bahaya `red-*`.

## Pola komponen Livewire (single-file)

File `resources/views/pages/<modul>/<nama>.blade.php`, dirujuk sebagai `pages::<modul>.<nama>`.
Layout default `layouts::app` (sidebar + topbar). Lihat `resources/views/pages/dashboard.blade.php`.

```php
<?php
use Livewire\Attributes\{Computed, Title, Url};
use Livewire\Component;

new #[Title('Rawat Jalan')] class extends Component
{
    #[Url] public string $cari = '';

    #[Computed] public function rows() { return DB::table(...)->paginate(20); }  // pakai WithPagination bila perlu

    public function simpan(): void
    {
        $this->authorize('tindakan_ralan');
        $this->validate([...]);
        app(\App\Services\RawatJalan\TindakanService::class)->simpan(...);
        $this->dispatch('toast', message: 'Tindakan tersimpan');
    }
};
?>
<div> ... </div>
```
Logika bisnis (query SQL, aturan Khanza) taruh di `app/Services/<Modul>/`, komponen hanya UI + validasi.

## Tes

- Database tes: **`sik_test`** (salinan `sik`, diatur `phpunit.xml`). Data asli `sik` tidak tersentuh.
- Helper di `tests/TestCase.php`: `actingAsAdmin()`, `actingAsKhanza(['igd' => true], kdDokter: 'D0000005')`.
- Login admin default di data dev: `spv` / `server`.
- Jalankan tes modul sendiri saja: `php artisan test tests/Feature/<Modul>`.
- Livewire: `Livewire::test('pages::igd.index')->set(...)->call(...)->assertHasNoErrors();`
- Format kode: `vendor/bin/pint <folder modul>`.
- **Jangan** `npm run build` / `npm run dev` saat agen lain bekerja; lead yang build di akhir.

## Status modul (fase 1)

| Modul | Selesai | Belum (kandidat fase berikutnya) |
|---|---|---|
| Auth | Login admin/user Khanza, Gate per kolom permission, logout | Audit `tracker`, ganti password |
| Registrasi IGD | Daftar, registrasi (no_rawat/no_reg/biaya/umur/rujuk_masuk), ubah status, edit, pasien baru + No. RM | Triase IGD, cetak tracer, status "Berkas Diterima" |
| Rawat Jalan | Worklist + kunci dokter, status Sudah/Belum/Batal, tindakan dr/pr/drpr, SOAP | Diagnosa, obstetri/ginekologi, catatan dokter, jurnal, kunci poli `POLIAKTIFKASIRRALAN` |
| Rawat Inap | Daftar + lama/biaya berjalan, masuk kamar, pindah kamar (mode 3), pulang, batal pulang, DPJP, tindakan, SOAP | Pindah kamar mode 1/2/4, rawat gabung ibu-bayi, dialog lanjutan Meninggal/Rujuk |

Catatan teknis:
- `<x-ui.modal>` memakai `wire:show` (bukan `entangle`) agar perubahan properti dari server selalu tampil.
- Zona waktu aplikasi `Asia/Jakarta` (sama dengan Khanza/MySQL).

Data dev (sik): 21 pasien, 5 dokter aktif, 7 petugas, 7 kamar (`ANG.K1.002`, `DAH.K1.002`, `K1.01`, `K3.01`
kosong), 4 pasien sedang ranap, `set_jam_minimal.lamajam=12`, `hariawal='No'`, semua `set_tarif` = `'No'`.
