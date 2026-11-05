<?php

use App\Models\Penjab;
use App\Services\Igd\IgdException;
use App\Services\Igd\PasienBaruService;
use App\Services\Igd\PesanValidasi;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Pasien Baru')] class extends Component
{
    // ?jenis=ralan|igd → setelah simpan, kembali ke form registrasi jenis tersebut.
    #[Url(except: '')]
    public string $jenis = '';

    // Identitas
    public string $nm_pasien = '';

    public string $no_ktp = '';

    public string $jk = 'L';

    public string $tmp_lahir = '';

    public string $tgl_lahir = '';

    public string $nm_ibu = '';

    public string $gol_darah = '-';

    public string $agama = 'ISLAM';

    public string $stts_nikah = 'BELUM MENIKAH';

    public string $pnd = '-';

    public string $pekerjaan = '';

    public string $no_tlp = '';

    public string $email = '';

    // Alamat
    public string $alamat = '';

    public string $kelurahan = '';

    public string $kecamatan = '';

    public string $kabupaten = '';

    public string $propinsi = '';

    // Cara bayar
    public string $kd_pj = '';

    public string $no_peserta = '';

    // Penanggung jawab
    public string $keluarga = 'DIRI SENDIRI';

    public string $namakeluarga = '';

    public string $pekerjaanpj = '';

    public bool $alamatPjSama = true;

    public string $alamatpj = '';

    public string $kelurahanpj = '';

    public string $kecamatanpj = '';

    public string $kabupatenpj = '';

    public string $propinsipj = '';

    // Data tambahan (default baris '-')
    public string $suku_bangsa = '';

    public string $bahasa_pasien = '';

    public string $cacat_fisik = '';

    public string $perusahaan_pasien = '';

    public function mount(): void
    {
        $default = $this->service()->defaultMaster();
        $this->suku_bangsa = (string) $default['suku_bangsa'];
        $this->bahasa_pasien = (string) $default['bahasa_pasien'];
        $this->cacat_fisik = (string) $default['cacat_fisik'];
        $this->perusahaan_pasien = (string) $default['perusahaan_pasien'];
    }

    protected function service(): PasienBaruService
    {
        return app(PasienBaruService::class);
    }

    #[Computed]
    public function nomorBerikutnya(): string
    {
        return $this->service()->nomorBerikutnya();
    }

    #[Computed]
    public function penjabOptions()
    {
        return Penjab::aktif()->orderBy('png_jawab')->pluck('png_jawab', 'kd_pj');
    }

    #[Computed]
    public function master(): array
    {
        $s = $this->service();

        return [
            'suku' => $s->pilihanSuku(),
            'bahasa' => $s->pilihanBahasa(),
            'cacat' => $s->pilihanCacat(),
            'perusahaan' => $s->pilihanPerusahaan(),
        ];
    }

    protected function rules(): array
    {
        $opsi = fn (array $nilai) => Rule::in($nilai);

        return [
            'nm_pasien' => 'required|string|max:40',
            'no_ktp' => 'nullable|string|max:20',
            'jk' => ['required', $opsi(['L', 'P'])],
            'tmp_lahir' => 'nullable|string|max:15',
            'tgl_lahir' => 'required|date_format:Y-m-d|before_or_equal:today',
            'nm_ibu' => 'nullable|string|max:40',
            'gol_darah' => ['required', $opsi(PasienBaruService::GOL_DARAH)],
            'agama' => ['required', $opsi(PasienBaruService::AGAMA)],
            'stts_nikah' => ['required', $opsi(PasienBaruService::STTS_NIKAH)],
            'pnd' => ['required', $opsi(PasienBaruService::PENDIDIKAN)],
            'pekerjaan' => 'nullable|string|max:60',
            'no_tlp' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:50',
            'alamat' => 'nullable|string|max:200',
            'kelurahan' => 'nullable|string|max:60',
            'kecamatan' => 'nullable|string|max:60',
            'kabupaten' => 'nullable|string|max:60',
            'propinsi' => 'nullable|string|max:30',
            'kd_pj' => 'required|exists:penjab,kd_pj',
            'no_peserta' => 'nullable|string|max:25',
            'keluarga' => ['required', $opsi(PasienBaruService::KELUARGA)],
            'namakeluarga' => 'nullable|string|max:50',
            'pekerjaanpj' => 'nullable|string|max:35',
            'alamatpj' => 'nullable|string|max:100',
            'kelurahanpj' => 'nullable|string|max:60',
            'kecamatanpj' => 'nullable|string|max:60',
            'kabupatenpj' => 'nullable|string|max:60',
            'propinsipj' => 'nullable|string|max:30',
            'suku_bangsa' => 'required|exists:suku_bangsa,id',
            'bahasa_pasien' => 'required|exists:bahasa_pasien,id',
            'cacat_fisik' => 'required|exists:cacat_fisik,id',
            'perusahaan_pasien' => 'required|exists:perusahaan_pasien,kode_perusahaan',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'nm_pasien' => 'Nama pasien', 'no_ktp' => 'No. KTP', 'jk' => 'Jenis kelamin', 'tmp_lahir' => 'Tempat lahir',
            'tgl_lahir' => 'Tanggal lahir', 'nm_ibu' => 'Nama ibu', 'gol_darah' => 'Golongan darah', 'agama' => 'Agama',
            'stts_nikah' => 'Status nikah', 'pnd' => 'Pendidikan', 'pekerjaan' => 'Pekerjaan', 'no_tlp' => 'No. telepon',
            'email' => 'Email', 'alamat' => 'Alamat', 'kelurahan' => 'Kelurahan', 'kecamatan' => 'Kecamatan',
            'kabupaten' => 'Kabupaten', 'propinsi' => 'Propinsi', 'kd_pj' => 'Cara bayar', 'no_peserta' => 'No. peserta',
            'keluarga' => 'Hubungan', 'namakeluarga' => 'Nama penanggung jawab', 'pekerjaanpj' => 'Pekerjaan P.J.',
            'alamatpj' => 'Alamat P.J.', 'kelurahanpj' => 'Kelurahan P.J.', 'kecamatanpj' => 'Kecamatan P.J.',
            'kabupatenpj' => 'Kabupaten P.J.', 'propinsipj' => 'Propinsi P.J.', 'suku_bangsa' => 'Suku/bangsa',
            'bahasa_pasien' => 'Bahasa', 'cacat_fisik' => 'Cacat fisik', 'perusahaan_pasien' => 'Perusahaan/instansi',
        ];
    }

    protected function messages(): array
    {
        return PesanValidasi::pesan();
    }

    public function simpan(): void
    {
        $this->authorize('pasien');

        if ($this->alamatPjSama) {
            $this->alamatpj = mb_substr($this->alamat, 0, 100);
            $this->kelurahanpj = $this->kelurahan;
            $this->kecamatanpj = $this->kecamatan;
            $this->kabupatenpj = $this->kabupaten;
            $this->propinsipj = $this->propinsi;
        }
        if (trim($this->namakeluarga) === '' && $this->keluarga === 'DIRI SENDIRI') {
            $this->namakeluarga = mb_substr($this->nm_pasien, 0, 50);
        }

        $data = $this->validate();

        try {
            $noRm = $this->service()->simpan($data);
        } catch (IgdException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        session()->flash('toast', ['message' => "Pasien baru tersimpan dengan No. RM {$noRm}. Lanjutkan registrasi.", 'type' => 'success']);

        $jenis = in_array($this->jenis, ['ralan', 'igd'], true) ? $this->jenis : null;
        $this->redirect(route('registrasi.index', array_filter(['pasien' => $noRm, 'baru' => $jenis])), navigate: true);
    }
};
?>

<div>
    <x-ui.page-header title="Pasien Baru" subtitle="Daftarkan pasien yang belum memiliki nomor rekam medis.">
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('registrasi.index') }}" wire:navigate>
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6" /></svg>
                Kembali ke Registrasi
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form wire:submit="simpan" class="space-y-6">
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-accent-300 bg-accent-50 px-5 py-3 text-sm text-accent-700">
            <span>No. RM otomatis berikutnya:</span>
            <span class="font-mono text-base font-semibold text-brand-800">{{ $this->nomorBerikutnya }}</span>
            <span class="text-xs text-slate-500">(nomor final ditetapkan saat disimpan)</span>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <x-ui.card title="Identitas Pasien">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.input label="Nama pasien" wire:model="nm_pasien" maxlength="40" required autofocus class="sm:col-span-2" />
                        <x-ui.input label="No. KTP / NIK" wire:model="no_ktp" maxlength="20" inputmode="numeric" />
                        <x-ui.select label="Jenis kelamin" wire:model="jk" :options="PasienBaruService::JK" required />
                        <x-ui.input label="Tempat lahir" wire:model="tmp_lahir" maxlength="15" />
                        <x-ui.input type="date" label="Tanggal lahir" wire:model="tgl_lahir" max="{{ today()->toDateString() }}" required />
                        <x-ui.input label="Nama ibu kandung" wire:model="nm_ibu" maxlength="40" />
                        <x-ui.select label="Golongan darah" wire:model="gol_darah" :options="array_combine(PasienBaruService::GOL_DARAH, PasienBaruService::GOL_DARAH)" />
                        <x-ui.select label="Agama" wire:model="agama" :options="array_combine(PasienBaruService::AGAMA, PasienBaruService::AGAMA)" />
                        <x-ui.select label="Status nikah" wire:model="stts_nikah" :options="array_combine(PasienBaruService::STTS_NIKAH, PasienBaruService::STTS_NIKAH)" />
                        <x-ui.select label="Pendidikan" wire:model="pnd" :options="array_combine(PasienBaruService::PENDIDIKAN, PasienBaruService::PENDIDIKAN)" />
                        <x-ui.input label="Pekerjaan" wire:model="pekerjaan" maxlength="60" />
                        <x-ui.input type="tel" label="No. telepon" wire:model="no_tlp" maxlength="40" />
                        <x-ui.input type="email" label="Email" wire:model="email" maxlength="50" />
                    </div>
                </x-ui.card>

                <x-ui.card title="Alamat">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.textarea label="Alamat" wire:model="alamat" rows="2" maxlength="200" class="sm:col-span-2" placeholder="Jalan, RT/RW, dusun" />
                        <x-ui.input label="Kelurahan / desa" wire:model="kelurahan" maxlength="60" />
                        <x-ui.input label="Kecamatan" wire:model="kecamatan" maxlength="60" />
                        <x-ui.input label="Kabupaten / kota" wire:model="kabupaten" maxlength="60" />
                        <x-ui.input label="Propinsi" wire:model="propinsi" maxlength="30" />
                    </div>
                    <p class="mt-3 text-xs text-slate-500">Nama wilayah yang belum ada di master akan ditambahkan otomatis. Kosongkan bila tidak diketahui.</p>
                </x-ui.card>

                <x-ui.card title="Penanggung Jawab">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.select label="Hubungan dengan pasien" wire:model="keluarga" :options="array_combine(PasienBaruService::KELUARGA, PasienBaruService::KELUARGA)" required />
                        <x-ui.input label="Nama penanggung jawab" wire:model="namakeluarga" maxlength="50" hint="Kosong + Diri Sendiri = nama pasien." />
                        <x-ui.input label="Pekerjaan penanggung jawab" wire:model="pekerjaanpj" maxlength="35" />
                        <label class="flex items-center gap-2 self-end pb-2 text-sm text-slate-700">
                            <input type="checkbox" wire:model.live="alamatPjSama" class="size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500" />
                            Alamat sama dengan pasien
                        </label>
                        @unless ($alamatPjSama)
                            <x-ui.textarea label="Alamat P.J." wire:model="alamatpj" rows="2" maxlength="100" class="sm:col-span-2" />
                            <x-ui.input label="Kelurahan P.J." wire:model="kelurahanpj" maxlength="60" />
                            <x-ui.input label="Kecamatan P.J." wire:model="kecamatanpj" maxlength="60" />
                            <x-ui.input label="Kabupaten P.J." wire:model="kabupatenpj" maxlength="60" />
                            <x-ui.input label="Propinsi P.J." wire:model="propinsipj" maxlength="30" />
                        @endunless
                    </div>
                </x-ui.card>
            </div>

            <div class="space-y-6">
                <x-ui.card title="Cara Bayar">
                    <div class="space-y-4">
                        <x-ui.select label="Cara bayar" wire:model="kd_pj" :options="$this->penjabOptions" placeholder="Pilih cara bayar" required />
                        <x-ui.input label="No. peserta / kartu" wire:model="no_peserta" maxlength="25" />
                    </div>
                </x-ui.card>

                <x-ui.card title="Data Tambahan">
                    <div class="space-y-4">
                        <x-ui.select label="Suku / bangsa" wire:model="suku_bangsa" :options="$this->master['suku']" required />
                        <x-ui.select label="Bahasa" wire:model="bahasa_pasien" :options="$this->master['bahasa']" required />
                        <x-ui.select label="Cacat fisik" wire:model="cacat_fisik" :options="$this->master['cacat']" required />
                        <x-ui.select label="Perusahaan / instansi" wire:model="perusahaan_pasien" :options="$this->master['perusahaan']" required />
                    </div>
                </x-ui.card>

                <div class="flex flex-col gap-2 sm:flex-row xl:flex-col">
                    <x-ui.button type="submit" wire:target="simpan" class="w-full">Simpan &amp; Lanjut Registrasi</x-ui.button>
                    <x-ui.button variant="secondary" href="{{ route('registrasi.index') }}" wire:navigate class="w-full">Batal</x-ui.button>
                </div>
            </div>
        </div>
    </form>
</div>
