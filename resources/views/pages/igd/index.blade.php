<?php

use App\Models\Dokter;
use App\Models\Penjab;
use App\Services\Igd\IgdException;
use App\Services\Igd\PasienBaruService;
use App\Services\Igd\PesanValidasi;
use App\Services\Igd\RegistrasiIgdService;
use App\Services\Khanza\Registrasi;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Registrasi IGD')] class extends Component
{
    use WithPagination;

    // Filter daftar
    #[Url(as: 'dari', except: '')]
    public string $tglAwal = '';

    #[Url(as: 'sampai', except: '')]
    public string $tglAkhir = '';

    #[Url(except: '')]
    public string $cari = '';

    // ?pasien=<no_rkm_medis> → buka form dengan pasien terpilih (dari halaman pasien baru)
    #[Url(as: 'pasien', except: '')]
    public string $pasienAwal = '';

    // Form registrasi / edit
    public bool $showForm = false;

    public ?string $editNoRawat = null;

    public string $cariPasien = '';

    public ?array $pasienInfo = null;

    public string $no_rkm_medis = '';

    public string $tgl_registrasi = '';

    public string $jam_reg = '';

    public string $kd_dokter = '';

    public string $kd_pj = '';

    public string $p_jawab = '';

    public string $hubunganpj = '';

    public string $almt_pj = '';

    public string $asal_rujukan = '';

    public string $alamat_perujuk = '';

    // Panel aksi baris
    public bool $showAksi = false;

    public ?string $aksiNoRawat = null;

    public function mount(): void
    {
        $this->tglAwal = $this->validTanggal($this->tglAwal) ?? today()->toDateString();
        $this->tglAkhir = $this->validTanggal($this->tglAkhir) ?? $this->tglAwal;

        if ($this->pasienAwal !== '') {
            $this->bukaForm();
            $this->pilihPasien($this->pasienAwal);
        }
    }

    protected function service(): RegistrasiIgdService
    {
        return app(RegistrasiIgdService::class);
    }

    protected function validTanggal(string $nilai): ?string
    {
        try {
            return $nilai !== '' ? Carbon::createFromFormat('Y-m-d', $nilai)->toDateString() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Daftar
    // ------------------------------------------------------------------

    public function updated(string $properti): void
    {
        if (in_array($properti, ['tglAwal', 'tglAkhir', 'cari'], true)) {
            $this->resetPage();
        }
    }

    public function hariIni(): void
    {
        $this->tglAwal = $this->tglAkhir = today()->toDateString();
        $this->resetPage();
    }

    #[Computed]
    public function rows()
    {
        $awal = $this->validTanggal($this->tglAwal) ?? today()->toDateString();
        $akhir = $this->validTanggal($this->tglAkhir) ?? $awal;
        if ($akhir < $awal) {
            [$awal, $akhir] = [$akhir, $awal];
        }

        return $this->service()->query($awal, $akhir, $this->cari)->paginate(15);
    }

    #[Computed]
    public function dokterOptions()
    {
        return Dokter::aktif()->orderBy('nm_dokter')->pluck('nm_dokter', 'kd_dokter');
    }

    #[Computed]
    public function penjabOptions()
    {
        return Penjab::aktif()->orderBy('png_jawab')->pluck('png_jawab', 'kd_pj');
    }

    // ------------------------------------------------------------------
    // Form registrasi baru
    // ------------------------------------------------------------------

    public function bukaForm(): void
    {
        $this->authorize('igd');

        $this->resetForm();
        $this->tgl_registrasi = today()->toDateString();
        $this->jam_reg = now()->format('H:i:s');
        $this->showForm = true;
    }

    public function updatedShowForm(bool $nilai): void
    {
        if (! $nilai) {
            $this->pasienAwal = '';
        }
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editNoRawat', 'cariPasien', 'pasienInfo', 'no_rkm_medis', 'tgl_registrasi', 'jam_reg', 'kd_dokter', 'kd_pj',
            'p_jawab', 'hubunganpj', 'almt_pj', 'asal_rujukan', 'alamat_perujuk',
        ]);
        $this->resetValidation();
    }

    #[Computed]
    public function hasilCariPasien()
    {
        if ($this->pasienInfo !== null) {
            return collect();
        }

        return $this->service()->cariPasien($this->cariPasien);
    }

    public function pilihPasien(string $noRm): void
    {
        $tgl = $this->validTanggal($this->tgl_registrasi) ?? today()->toDateString();
        $info = $this->service()->dataPasien($noRm, Carbon::parse($tgl));

        if (! $info) {
            $this->dispatch('toast', message: "Pasien {$noRm} tidak ditemukan.", type: 'error');

            return;
        }

        $this->pasienInfo = $info;
        $this->no_rkm_medis = $info['no_rkm_medis'];
        $this->p_jawab = $info['p_jawab'];
        $this->hubunganpj = $info['hubunganpj'];
        $this->almt_pj = mb_substr($info['almt_pj'], 0, 200);
        $this->kd_pj = $info['kd_pj'];
        $this->cariPasien = '';
        $this->resetValidation('no_rkm_medis');
    }

    public function gantiPasien(): void
    {
        $this->reset(['pasienInfo', 'no_rkm_medis', 'p_jawab', 'hubunganpj', 'almt_pj', 'kd_pj']);
    }

    public function updatedTglRegistrasi(): void
    {
        // stts_daftar (Baru/Lama) bergantung pada tanggal registrasi.
        if ($this->pasienInfo && $this->editNoRawat === null && ($tgl = $this->validTanggal($this->tgl_registrasi))) {
            $info = $this->service()->dataPasien($this->no_rkm_medis, Carbon::parse($tgl));
            if ($info) {
                $this->pasienInfo['stts_daftar'] = $info['stts_daftar'];
            }
        }
    }

    protected function rulesForm(): array
    {
        return [
            'kd_dokter' => 'required|exists:dokter,kd_dokter',
            'kd_pj' => 'required|exists:penjab,kd_pj',
            'p_jawab' => 'nullable|string|max:100',
            'hubunganpj' => 'nullable|string|max:20',
            'almt_pj' => 'nullable|string|max:200',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'no_rkm_medis' => 'Pasien', 'kd_dokter' => 'Dokter', 'kd_pj' => 'Cara bayar', 'tgl_registrasi' => 'Tanggal',
            'jam_reg' => 'Jam', 'p_jawab' => 'Penanggung jawab', 'hubunganpj' => 'Hubungan', 'almt_pj' => 'Alamat P.J.',
            'asal_rujukan' => 'Asal rujukan', 'alamat_perujuk' => 'Alamat perujuk',
        ];
    }

    protected function messages(): array
    {
        return PesanValidasi::pesan();
    }

    public function simpan(): void
    {
        $this->authorize('igd');

        $data = $this->validate($this->rulesForm() + [
            'no_rkm_medis' => 'required|exists:pasien,no_rkm_medis',
            'tgl_registrasi' => 'required|date_format:Y-m-d',
            'jam_reg' => 'required|date_format:H:i:s,H:i',
            'asal_rujukan' => 'nullable|string|max:60',
            'alamat_perujuk' => 'nullable|string|max:70',
        ]);
        $data['jam_reg'] = Carbon::parse($data['jam_reg'])->format('H:i:s');

        try {
            $hasil = $this->service()->daftar($data);
        } catch (IgdException $e) {
            $this->addError('no_rkm_medis', $e->getMessage());
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->showForm = false;
        $this->pasienAwal = '';
        $this->resetForm();
        $this->tglAwal = $this->tglAkhir = $data['tgl_registrasi'];
        $this->cari = '';
        $this->resetPage();
        unset($this->rows);

        $this->dispatch('toast', message: "Registrasi tersimpan. No. Rawat {$hasil['no_rawat']} (No. Reg {$hasil['no_reg']}).", type: 'success');
    }

    // ------------------------------------------------------------------
    // Edit registrasi
    // ------------------------------------------------------------------

    public function edit(string $noRawat): void
    {
        $this->authorize('edit_registrasi');

        try {
            $reg = $this->service()->registrasiIgd($noRawat);
        } catch (IgdException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        if (Registrasi::terkunci($noRawat)) {
            $this->dispatch('toast', message: RegistrasiIgdService::PESAN_TERKUNCI, type: 'error');

            return;
        }

        $this->resetForm();
        $this->editNoRawat = $noRawat;
        $this->pasienInfo = $this->service()->dataPasien($reg->no_rkm_medis, Carbon::parse($reg->tgl_registrasi));
        $this->no_rkm_medis = $reg->no_rkm_medis;
        $this->tgl_registrasi = $reg->tgl_registrasi;
        $this->jam_reg = (string) $reg->jam_reg;
        $this->kd_dokter = (string) $reg->kd_dokter;
        $this->kd_pj = (string) $reg->kd_pj;
        $this->p_jawab = (string) $reg->p_jawab;
        $this->hubunganpj = (string) $reg->hubunganpj;
        $this->almt_pj = (string) $reg->almt_pj;
        if ($this->pasienInfo) {
            $this->pasienInfo['stts_daftar'] = $reg->stts_daftar;
        }

        $this->showAksi = false;
        $this->showForm = true;
    }

    public function simpanEdit(): void
    {
        $this->authorize('edit_registrasi');

        if (! $this->editNoRawat) {
            return;
        }

        $data = $this->validate($this->rulesForm());

        try {
            $this->service()->ubah($this->editNoRawat, $data);
        } catch (IgdException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $noRawat = $this->editNoRawat;
        $this->showForm = false;
        $this->resetForm();
        unset($this->rows);

        $this->dispatch('toast', message: "Registrasi {$noRawat} diperbarui.", type: 'success');
    }

    // ------------------------------------------------------------------
    // Status
    // ------------------------------------------------------------------

    public function bukaAksi(string $noRawat): void
    {
        $this->aksiNoRawat = $noRawat;
        unset($this->aksi);
        $this->showAksi = true;
    }

    #[Computed]
    public function aksi(): ?object
    {
        if (! $this->aksiNoRawat) {
            return null;
        }

        $row = $this->service()->query('0000-00-00', '9999-12-31')->where('reg_periksa.no_rawat', $this->aksiNoRawat)->first();
        if ($row) {
            $row->terkunci = Registrasi::terkunci($row->no_rawat);
            $row->kamar_inap = $row->jml_kamar_inap > 0;
        }

        return $row;
    }

    public function ubahStatus(string $noRawat, string $stts): void
    {
        $this->authorize('igd');

        try {
            $this->service()->ubahStatus($noRawat, $stts);
        } catch (IgdException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->showAksi = false;
        unset($this->rows, $this->aksi);
        $this->dispatch('toast', message: "Status {$noRawat} diubah menjadi {$stts}.", type: 'success');
    }
};
?>

<div>
    <x-ui.page-header title="Registrasi IGD" subtitle="Pendaftaran pasien Instalasi Gawat Darurat.">
        <x-slot:actions>
            @can('pasien')
                <x-ui.button variant="secondary" href="{{ route('igd.pasien-baru') }}" wire:navigate>
                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM19 8v6M22 11h-6" /></svg>
                    Pasien Baru
                </x-ui.button>
            @endcan
            <x-ui.button wire:click="bukaForm">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                Registrasi Baru
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        {{-- Filter --}}
        <div class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-end">
            <div class="grid flex-1 grid-cols-2 gap-3 sm:max-w-md">
                <x-ui.input type="date" label="Dari tanggal" wire:model.live="tglAwal" />
                <x-ui.input type="date" label="Sampai tanggal" wire:model.live="tglAkhir" />
            </div>
            <div class="flex flex-1 items-end gap-2">
                <div class="relative flex-1">
                    <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="M20 20l-3.5-3.5" /></svg>
                    <input type="search" wire:model.live.debounce.400ms="cari" placeholder="Cari no. RM, nama pasien, atau no. rawat…" aria-label="Cari registrasi"
                        class="block w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30" />
                </div>
                <x-ui.button variant="ghost" wire:click="hariIni">Hari ini</x-ui.button>
            </div>
        </div>

        <div class="flex items-center justify-between px-4 py-2 text-xs text-slate-500">
            <span>{{ number_format($this->rows->total()) }} registrasi</span>
            <span wire:loading.delay class="text-brand-600">Memuat…</span>
        </div>

        <x-ui.table>
            <x-slot:head>
                <th>No. Reg</th>
                <th>No. Rawat</th>
                <th>Jam</th>
                <th>Pasien</th>
                <th>Dokter</th>
                <th>Cara Bayar</th>
                <th>Status</th>
                <th>Pembayaran</th>
                <th class="text-right">Aksi</th>
            </x-slot:head>

            @forelse ($this->rows as $row)
                <tr wire:key="reg-{{ $row->no_rawat }}">
                    <td class="font-semibold text-slate-700">{{ $row->no_reg }}</td>
                    <td class="whitespace-nowrap font-mono text-xs text-slate-600">{{ $row->no_rawat }}</td>
                    <td class="whitespace-nowrap">
                        <div>{{ substr((string) $row->jam_reg, 0, 5) }}</div>
                        @if ($tglAwal !== $tglAkhir)
                            <div class="text-xs text-slate-400">{{ Carbon::parse($row->tgl_registrasi)->format('d/m/Y') }}</div>
                        @endif
                    </td>
                    <td class="min-w-48">
                        <div class="font-medium text-slate-800">{{ $row->nm_pasien }}</div>
                        <div class="text-xs text-slate-500">
                            RM {{ $row->no_rkm_medis }} · {{ $row->jk }} · {{ $row->umurdaftar }} {{ $row->sttsumur }}
                            @if ($row->stts_daftar === 'Baru')<x-ui.badge color="blue" class="ml-1">Baru</x-ui.badge>@endif
                        </div>
                    </td>
                    <td class="whitespace-nowrap">{{ $row->nm_dokter ?? $row->kd_dokter }}</td>
                    <td class="whitespace-nowrap">{{ $row->png_jawab ?? $row->kd_pj }}</td>
                    <td><x-ui.status :value="$row->stts" /></td>
                    <td><x-ui.status :value="$row->status_bayar" /></td>
                    <td class="whitespace-nowrap text-right">
                        <div class="flex justify-end gap-1">
                            @can('tindakan_ralan')
                                @if ($row->stts !== 'Batal')
                                    <x-ui.button size="sm" variant="secondary" href="{{ route('rawat-jalan.show', ['noRawat' => $row->no_rawat]) }}" wire:navigate title="Pemeriksaan">
                                        Periksa
                                    </x-ui.button>
                                @endif
                            @endcan
                            <x-ui.button size="sm" variant="ghost" wire:click="bukaAksi('{{ $row->no_rawat }}')" wire:target="bukaAksi('{{ $row->no_rawat }}')" aria-label="Aksi registrasi {{ $row->no_rawat }}">
                                <svg class="size-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="5" cy="12" r="2" /><circle cx="12" cy="12" r="2" /><circle cx="19" cy="12" r="2" /></svg>
                            </x-ui.button>
                        </div>
                    </td>
                </tr>
            @empty
                <x-ui.empty colspan="9" message="Belum ada registrasi IGD pada periode ini." />
            @endforelse
        </x-ui.table>

        @if ($this->rows->hasPages())
            <div class="border-t border-slate-100 px-4 py-3">{{ $this->rows->links() }}</div>
        @endif
    </x-ui.card>

    {{-- ============================================================ --}}
    {{-- Form registrasi baru / edit                                  --}}
    {{-- ============================================================ --}}
    <x-ui.modal wire:model="showForm" :title="$editNoRawat ? 'Edit Registrasi '.$editNoRawat : 'Registrasi IGD Baru'" maxWidth="4xl">
        <form id="form-registrasi-igd" wire:submit="{{ $editNoRawat ? 'simpanEdit' : 'simpan' }}" class="space-y-5">
            {{-- Pasien --}}
            <section>
                <h4 class="mb-2 text-sm font-semibold text-slate-700">Pasien</h4>

                @if ($pasienInfo)
                    <div class="flex flex-col gap-3 rounded-lg border border-brand-200 bg-brand-50/60 p-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-base font-semibold text-slate-800">{{ $pasienInfo['nm_pasien'] }}</span>
                                <x-ui.badge :color="$pasienInfo['stts_daftar'] === 'Baru' ? 'blue' : 'gray'">{{ $pasienInfo['stts_daftar'] }}</x-ui.badge>
                            </div>
                            <div class="text-sm text-slate-600">
                                RM <span class="font-mono">{{ $pasienInfo['no_rkm_medis'] }}</span>
                                · {{ $pasienInfo['jk'] === 'P' ? 'Perempuan' : 'Laki-laki' }}
                                · {{ $pasienInfo['umur'] }}
                                @if ($pasienInfo['tgl_lahir']) · lahir {{ Carbon::parse($pasienInfo['tgl_lahir'])->format('d/m/Y') }} @endif
                            </div>
                            @if ($pasienInfo['no_ktp'] || $pasienInfo['no_peserta'])
                                <div class="text-xs text-slate-500">
                                    @if ($pasienInfo['no_ktp']) NIK {{ $pasienInfo['no_ktp'] }} @endif
                                    @if ($pasienInfo['no_peserta']) · No. peserta {{ $pasienInfo['no_peserta'] }} @endif
                                </div>
                            @endif
                            @if (! $editNoRawat && $pasienInfo['sedang_ranap'])
                                <p class="text-sm font-medium text-red-600">{{ RegistrasiIgdService::PESAN_RANAP }}</p>
                            @endif
                            @if (! $editNoRawat && $pasienInfo['tagihan_belum_lunas'] > 0)
                                <p class="text-sm font-medium text-orange-600">Pasien memiliki {{ $pasienInfo['tagihan_belum_lunas'] }} kunjungan yang belum dibayar.</p>
                            @endif
                        </div>
                        @unless ($editNoRawat)
                            <x-ui.button size="sm" variant="secondary" wire:click="gantiPasien">Ganti pasien</x-ui.button>
                        @endunless
                    </div>
                @else
                    <div class="space-y-2">
                        <x-ui.input wire:model.live.debounce.300ms="cariPasien" placeholder="Ketik no. RM, nama, NIK, atau no. peserta (min. 2 karakter)" autocomplete="off" aria-label="Cari pasien" />
                        @error('no_rkm_medis')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

                        @if (mb_strlen(trim($cariPasien)) >= 2)
                            <div class="overflow-hidden rounded-lg border border-slate-200">
                                <ul class="max-h-72 divide-y divide-slate-100 overflow-y-auto">
                                    @forelse ($this->hasilCariPasien as $p)
                                        <li wire:key="cari-{{ $p->no_rkm_medis }}">
                                            <button type="button" wire:click="pilihPasien('{{ $p->no_rkm_medis }}')" class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left hover:bg-brand-50">
                                                <div class="min-w-0">
                                                    <div class="truncate font-medium text-slate-800">{{ $p->nm_pasien }}</div>
                                                    <div class="truncate text-xs text-slate-500">
                                                        {{ $p->jk }}
                                                        @if ($p->tgl_lahir && $p->tgl_lahir !== '0000-00-00') · {{ Carbon::parse($p->tgl_lahir)->format('d/m/Y') }} @endif
                                                        · {{ $p->alamat }}
                                                    </div>
                                                </div>
                                                <span class="shrink-0 font-mono text-xs text-slate-600">{{ $p->no_rkm_medis }}</span>
                                            </button>
                                        </li>
                                    @empty
                                        <li class="px-4 py-3 text-sm text-slate-500">
                                            Pasien tidak ditemukan.
                                            @can('pasien')
                                                <a href="{{ route('igd.pasien-baru') }}" wire:navigate class="font-medium text-brand-600 hover:underline">Daftarkan pasien baru</a>
                                            @endcan
                                        </li>
                                    @endforelse
                                </ul>
                            </div>
                        @endif
                    </div>
                @endif
            </section>

            {{-- Registrasi --}}
            <section class="grid gap-4 sm:grid-cols-2">
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.input type="date" label="Tanggal" wire:model.live="tgl_registrasi" :disabled="(bool) $editNoRawat" required />
                    <x-ui.input type="time" step="1" label="Jam" wire:model="jam_reg" :disabled="(bool) $editNoRawat" required />
                </div>
                <x-ui.select label="Dokter" wire:model="kd_dokter" :options="$this->dokterOptions" placeholder="Pilih dokter" required />
                <x-ui.select label="Cara bayar" wire:model="kd_pj" :options="$this->penjabOptions" placeholder="Pilih cara bayar" required />
                <x-ui.input label="Penanggung jawab" wire:model="p_jawab" maxlength="100" />
                <div>
                    <x-ui.input label="Hubungan dengan pasien" wire:model="hubunganpj" maxlength="20" list="igd-hubungan" />
                    <datalist id="igd-hubungan">
                        @foreach (PasienBaruService::KELUARGA as $h)<option value="{{ $h }}"></option>@endforeach
                    </datalist>
                </div>
                <x-ui.textarea label="Alamat penanggung jawab" wire:model="almt_pj" rows="2" maxlength="200" />
            </section>

            @unless ($editNoRawat)
                <section class="grid gap-4 rounded-lg bg-slate-50 p-4 sm:grid-cols-2">
                    <x-ui.input label="Asal rujukan (opsional)" wire:model="asal_rujukan" maxlength="60" placeholder="Puskesmas / klinik / RS perujuk" />
                    <x-ui.input label="Alamat perujuk" wire:model="alamat_perujuk" maxlength="70" placeholder="-" />
                </section>
            @endunless
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Batal</x-ui.button>
            <x-ui.button type="submit" form="form-registrasi-igd" wire:target="simpan,simpanEdit">
                {{ $editNoRawat ? 'Simpan Perubahan' : 'Simpan Registrasi' }}
            </x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- ============================================================ --}}
    {{-- Panel aksi registrasi                                        --}}
    {{-- ============================================================ --}}
    <x-ui.modal wire:model="showAksi" title="Kelola Registrasi" maxWidth="xl">
        @if ($r = $this->aksi)
            <div class="space-y-5">
                <div class="rounded-lg border border-slate-200 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="font-semibold text-slate-800">{{ $r->nm_pasien }}</div>
                        <x-ui.status :value="$r->stts" />
                    </div>
                    <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <dt class="text-slate-500">No. Rawat</dt><dd class="font-mono text-xs leading-5">{{ $r->no_rawat }}</dd>
                        <dt class="text-slate-500">No. RM</dt><dd>{{ $r->no_rkm_medis }}</dd>
                        <dt class="text-slate-500">Tanggal</dt><dd>{{ Carbon::parse($r->tgl_registrasi)->format('d/m/Y') }} {{ substr((string) $r->jam_reg, 0, 5) }}</dd>
                        <dt class="text-slate-500">Dokter</dt><dd>{{ $r->nm_dokter ?? $r->kd_dokter }}</dd>
                        <dt class="text-slate-500">Cara bayar</dt><dd>{{ $r->png_jawab ?? $r->kd_pj }}</dd>
                        <dt class="text-slate-500">Biaya registrasi</dt><dd>Rp {{ number_format((float) $r->biaya_reg, 0, ',', '.') }}</dd>
                    </dl>
                </div>

                @if ($r->kamar_inap)
                    <p class="rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-800">{{ RegistrasiIgdService::PESAN_KAMAR_INAP }}</p>
                @elseif ($r->terkunci)
                    <p class="rounded-lg bg-accent-50 px-4 py-3 text-sm text-accent-700">
                        {{ $r->stts === 'Batal' ? 'Registrasi sudah dibatalkan.' : 'Data billing sudah terverifikasi.' }} Status dan data registrasi tidak dapat diubah.
                    </p>
                @endif

                <div>
                    <h4 class="mb-2 text-sm font-semibold text-slate-700">Ubah status</h4>
                    @php $bisaUbah = ! $r->kamar_inap && ! $r->terkunci; @endphp
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        @foreach (RegistrasiIgdService::STATUS as $s)
                            <button
                                type="button"
                                wire:click="ubahStatus('{{ $r->no_rawat }}', '{{ $s }}')"
                                @if ($s === 'Batal') wire:confirm="Batalkan registrasi {{ $r->no_rawat }}? Biaya registrasi akan dinolkan dan registrasi terkunci." @endif
                                @disabled(! $bisaUbah || $r->stts === $s)
                                @class([
                                    'rounded-lg border px-3 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50',
                                    'border-accent-400 bg-accent-400 text-brand-900' => $r->stts === $s,
                                    'border-red-200 text-red-700 hover:bg-red-50' => $r->stts !== $s && in_array($s, ['Batal', 'Meninggal', 'Pulang Paksa'], true),
                                    'border-slate-200 text-slate-700 hover:bg-slate-50' => $r->stts !== $s && ! in_array($s, ['Batal', 'Meninggal', 'Pulang Paksa'], true),
                                ])
                            >{{ $s }}</button>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                    @can('edit_registrasi')
                        <x-ui.button variant="secondary" wire:click="edit('{{ $r->no_rawat }}')" :disabled="$r->terkunci">Edit Registrasi</x-ui.button>
                    @endcan
                    @if ($r->stts !== 'Batal')
                        @can('tindakan_ralan')
                            <x-ui.button variant="primary" href="{{ route('rawat-jalan.show', ['noRawat' => $r->no_rawat]) }}" wire:navigate>Pemeriksaan</x-ui.button>
                        @endcan
                        @can('kamar_inap')
                            <x-ui.button variant="accent" href="{{ route('rawat-inap.masuk', ['no_rawat' => $r->no_rawat]) }}" wire:navigate>Rawat Inap</x-ui.button>
                        @endcan
                    @endif
                </div>
            </div>
        @else
            <x-ui.empty message="Registrasi tidak ditemukan." />
        @endif
    </x-ui.modal>
</div>
