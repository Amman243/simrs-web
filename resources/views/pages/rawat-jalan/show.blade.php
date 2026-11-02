<?php

use App\Models\Dokter;
use App\Models\Petugas;
use App\Services\Khanza\Registrasi;
use App\Services\RawatJalan\AturanException;
use App\Services\RawatJalan\KunjunganService;
use App\Services\RawatJalan\PemeriksaanService;
use App\Services\RawatJalan\StatusService;
use App\Services\RawatJalan\TindakanService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Pemeriksaan Rawat Jalan')] class extends Component
{
    public string $noRawat = '';

    #[Url]
    public string $tab = 'tindakan';

    #[Url]
    public string $jenis = 'dr';

    // Form tindakan
    public string $cariTindakan = '';

    /** @var list<string> kd_jenis_prw terpilih */
    public array $pilih = [];

    public string $kdDokter = '';

    public string $nip = '';

    public string $tglTindakan = '';

    public string $jamTindakan = '';

    // Form SOAP
    /** @var array<string, string> */
    public array $soap = [];

    public string $soapTgl = '';

    public string $soapJam = '';

    public string $soapNip = '';

    /** PK baris yang sedang diedit: "tgl|jam", null = tambah baru. */
    public ?string $editKunci = null;

    public function mount(string $noRawat): void
    {
        $this->authorize('tindakan_ralan');

        $this->noRawat = $noRawat;
        abort_unless($this->kunjungan, 404);

        if (! array_key_exists($this->jenis, TindakanService::JENIS)) {
            $this->jenis = 'dr';
        }
        if (! in_array($this->tab, ['tindakan', 'soap'], true)) {
            $this->tab = 'tindakan';
        }

        $user = auth()->user();
        $this->kdDokter = (string) $this->kunjungan->kd_dokter;
        $this->nip = $user->isPetugas() ? (string) $user->nip : '';
        $this->soapNip = DB::table('pegawai')->where('nik', $this->kunjungan->kd_dokter)->exists() ? (string) $this->kunjungan->kd_dokter : '';
        $this->resetSoap();
        $this->waktuSekarang();
    }

    #[Computed]
    public function kunjungan(): ?object
    {
        return app(KunjunganService::class)->detail($this->noRawat);
    }

    #[Computed]
    public function terkunci(): bool
    {
        return Registrasi::terkunci($this->noRawat);
    }

    #[Computed(persist: true)]
    public function opsi(): array
    {
        return [
            'dokter' => Dokter::aktif()->where('kd_dokter', '<>', '-')->orderBy('nm_dokter')->pluck('nm_dokter', 'kd_dokter')->all(),
            'petugas' => Petugas::aktif()->where('nip', '<>', '-')->orderBy('nama')->pluck('nama', 'nip')->all(),
            'pegawai' => DB::table('pegawai')->where('nik', '<>', '-')->where('stts_aktif', '<>', 'KELUAR')->orderBy('nama')->pluck('nama', 'nik')->all(),
        ];
    }

    // ---------------------------------------------------------------- Tindakan

    #[Computed]
    public function katalog()
    {
        return app(TindakanService::class)->katalog($this->jenis, $this->kunjungan->kd_pj, $this->kunjungan->kd_poli, $this->cariTindakan);
    }

    #[Computed]
    public function terpilih()
    {
        if ($this->pilih === []) {
            return collect();
        }

        $total = TindakanService::jenis($this->jenis)['total'];

        return app(TindakanService::class)
            ->katalogQuery($this->jenis, $this->kunjungan->kd_pj, $this->kunjungan->kd_poli)
            ->whereIn('jns_perawatan.kd_jenis_prw', $this->pilih)
            ->orderBy('jns_perawatan.nm_perawatan')
            ->get(['jns_perawatan.kd_jenis_prw', 'jns_perawatan.nm_perawatan', DB::raw('jns_perawatan.'.$total.' as total')]);
    }

    /**
     * @return array<string, \Illuminate\Support\Collection>
     */
    #[Computed]
    public function tindakan(): array
    {
        $service = app(TindakanService::class);

        return collect(TindakanService::JENIS)->keys()->mapWithKeys(fn ($j) => [$j => $service->daftar($j, $this->noRawat)])->all();
    }

    public function pilihJenis(string $jenis): void
    {
        if (array_key_exists($jenis, TindakanService::JENIS)) {
            $this->jenis = $jenis;
            $this->pilih = [];
            $this->resetValidation();
        }
    }

    public function hapusPilihan(string $kode): void
    {
        $this->pilih = array_values(array_diff($this->pilih, [$kode]));
    }

    public function simpanTindakan(): void
    {
        $this->authorize('tindakan_ralan');
        $def = TindakanService::jenis($this->jenis);

        $this->validate([
            'pilih' => 'required|array|min:1',
            'pilih.*' => 'string|max:15',
            'kdDokter' => $def['dokter'] ? 'required|string' : 'nullable',
            'nip' => $def['petugas'] ? 'required|string' : 'nullable',
            'tglTindakan' => 'required|date_format:Y-m-d',
            'jamTindakan' => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
        ], [], [
            'pilih' => 'tindakan', 'kdDokter' => 'dokter', 'nip' => 'petugas',
            'tglTindakan' => 'tanggal', 'jamTindakan' => 'jam',
        ]);

        $this->jalankan(function () use ($def) {
            $jumlah = app(TindakanService::class)->simpan(
                $this->jenis, $this->noRawat, $this->pilih,
                $def['dokter'] ? $this->kdDokter : null,
                $def['petugas'] ? $this->nip : null,
                $this->tglTindakan, $this->jamTindakan, auth()->user(),
            );

            $this->pilih = [];
            $this->waktuSekarang();
            unset($this->tindakan, $this->terpilih);
            $this->dispatch('toast', message: "{$jumlah} tindakan {$def['label']} tersimpan.", type: 'success');
        });
    }

    /**
     * @param  array<string, string>  $pk
     */
    public function hapusTindakan(string $jenis, array $pk): void
    {
        $this->authorize('tindakan_ralan');
        $pk['no_rawat'] = $this->noRawat;

        $this->jalankan(function () use ($jenis, $pk) {
            app(TindakanService::class)->hapus($jenis, $pk, auth()->user());
            unset($this->tindakan);
            $this->dispatch('toast', message: 'Tindakan dihapus.', type: 'success');
        });
    }

    // ---------------------------------------------------------------- SOAP

    #[Computed]
    public function pemeriksaan()
    {
        return app(PemeriksaanService::class)->daftar($this->noRawat);
    }

    /**
     * Nama penyimpan SOAP untuk user non-admin, atau null bila bukan pegawai.
     */
    #[Computed]
    public function pegawaiLogin(): ?string
    {
        $user = auth()->user();

        return $user->isAdmin ? null : DB::table('pegawai')->where('nik', $user->kode)->value('nama');
    }

    public function simpanSoap(): void
    {
        $this->authorize('tindakan_ralan');

        $rules = [
            'soapTgl' => 'required|date_format:Y-m-d',
            'soapJam' => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'soap.kesadaran' => 'required|in:'.implode(',', PemeriksaanService::KESADARAN),
            'soapNip' => auth()->user()->isAdmin ? 'required|string' : 'nullable',
        ];
        foreach (PemeriksaanService::KOLOM as $kolom => $maks) {
            if ($kolom !== 'kesadaran') {
                $rules['soap.'.$kolom] = 'nullable|string|max:'.$maks;
            }
        }

        $this->validate($rules, [], [
            'soapTgl' => 'tanggal', 'soapJam' => 'jam', 'soapNip' => 'pemeriksa',
            'soap.suhu_tubuh' => 'suhu', 'soap.respirasi' => 'respirasi', 'soap.spo2' => 'SpO2',
            'soap.rtl' => 'plan', 'soap.penilaian' => 'asesmen', 'soap.lingkar_perut' => 'lingkar perut',
        ]);

        $data = array_merge($this->soap, ['nip' => $this->soapNip]);

        $this->jalankan(function () use ($data) {
            $service = app(PemeriksaanService::class);

            if ($this->editKunci) {
                [$tgl, $jam] = explode('|', $this->editKunci);
                $service->ubah($this->noRawat, $tgl, $jam, $this->soapTgl, $this->soapJam, $data, auth()->user());
                $pesan = 'Pemeriksaan diperbarui.';
            } else {
                $service->simpan($this->noRawat, $this->soapTgl, $this->soapJam, $data, auth()->user());
                $pesan = 'Pemeriksaan tersimpan.';
            }

            $this->resetSoap();
            $this->waktuSekarang();
            unset($this->pemeriksaan);
            $this->dispatch('toast', message: $pesan, type: 'success');
        });
    }

    public function editSoap(string $tgl, string $jam): void
    {
        $this->authorize('tindakan_ralan');
        $row = app(PemeriksaanService::class)->cari($this->noRawat, $tgl, $jam);

        if (! $row || ! app(PemeriksaanService::class)->bolehUbah($row, auth()->user(), $this->terkunci)) {
            $this->dispatch('toast', message: 'Data ini tidak bisa diubah (bukan milik Anda, lewat 48 jam, atau registrasi terkunci).', type: 'warning');

            return;
        }

        $this->resetValidation();
        foreach (array_keys(PemeriksaanService::KOLOM) as $kolom) {
            $this->soap[$kolom] = (string) ($row->{$kolom} ?? '');
        }
        $this->soapTgl = $row->tgl_perawatan;
        $this->soapJam = $row->jam_rawat;
        $this->soapNip = $row->nip;
        $this->editKunci = $row->tgl_perawatan.'|'.$row->jam_rawat;
    }

    public function batalEditSoap(): void
    {
        $this->resetSoap();
        $this->waktuSekarang();
        $this->resetValidation();
    }

    public function hapusSoap(string $tgl, string $jam): void
    {
        $this->authorize('tindakan_ralan');

        $this->jalankan(function () use ($tgl, $jam) {
            app(PemeriksaanService::class)->hapus($this->noRawat, $tgl, $jam, auth()->user());

            if ($this->editKunci === $tgl.'|'.$jam) {
                $this->resetSoap();
            }
            unset($this->pemeriksaan);
            $this->dispatch('toast', message: 'Pemeriksaan dihapus.', type: 'success');
        });
    }

    // ---------------------------------------------------------------- Status

    public function selesai(): void
    {
        $this->authorize('tindakan_ralan');

        $this->jalankan(function () {
            app(StatusService::class)->ubah($this->noRawat, 'Sudah');
            unset($this->kunjungan);
            $this->dispatch('toast', message: 'Pasien ditandai sudah diperiksa.', type: 'success');
        });
    }

    // ---------------------------------------------------------------- Helper

    private function jalankan(callable $aksi): void
    {
        if ($this->terkunci) {
            $this->dispatch('toast', message: 'Registrasi terkunci (billing sudah terverifikasi / batal). Data tidak bisa diubah.', type: 'error');

            return;
        }

        try {
            $aksi();
        } catch (AturanException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
    }

    private function resetSoap(): void
    {
        $this->soap = array_fill_keys(array_keys(PemeriksaanService::KOLOM), '');
        $this->soap['kesadaran'] = 'Compos Mentis';
        $this->editKunci = null;

        if (auth()->user()->isAdmin && $this->soapNip === '') {
            $this->soapNip = DB::table('pegawai')->where('nik', $this->kunjungan->kd_dokter)->exists() ? (string) $this->kunjungan->kd_dokter : '';
        }
    }

    private function waktuSekarang(): void
    {
        $this->tglTindakan = $this->soapTgl = now()->toDateString();
        $this->jamTindakan = $this->soapJam = now()->format('H:i:s');
    }
};
?>

@php
    $k = $this->kunjungan;
    $user = auth()->user();
    $terkunci = $this->terkunci;
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $tindakanService = app(\App\Services\RawatJalan\TindakanService::class);
    $pemeriksaanService = app(\App\Services\RawatJalan\PemeriksaanService::class);
    $jenisDef = \App\Services\RawatJalan\TindakanService::JENIS;
    $def = $jenisDef[$jenis];
    $subtotal = collect($this->tindakan)->map(fn ($rows) => $rows->sum('biaya_rawat'));
@endphp

<div>
    <x-ui.page-header title="Pemeriksaan Rawat Jalan" subtitle="No. rawat {{ $k->no_rawat }} · {{ $k->nm_poli }}">
        <x-slot:actions>
            <x-ui.button variant="ghost" :href="route('rawat-jalan.index')" wire:navigate>&larr; Worklist</x-ui.button>
            @can('kamar_inap')
                @if (Route::has('rawat-inap.masuk'))
                    <x-ui.button variant="secondary" :href="route('rawat-inap.masuk', ['no_rawat' => $k->no_rawat])" wire:navigate>Rawat Inap</x-ui.button>
                @endif
            @endcan
            <x-ui.button
                wire:click="selesai"
                wire:confirm="Tandai pasien ini sudah diperiksa?"
                :disabled="$terkunci || $k->stts === 'Sudah'"
            >
                {{ $k->stts === 'Sudah' ? 'Sudah diperiksa' : 'Selesai diperiksa' }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($terkunci)
        <div class="mb-4 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <svg class="mt-0.5 size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
            <div>
                <div class="font-semibold">Registrasi terkunci — hanya baca</div>
                <div>{{ $k->stts === 'Batal' ? 'Registrasi ini sudah dibatalkan.' : 'Data billing sudah terverifikasi.' }} Tindakan dan pemeriksaan tidak bisa ditambah, diubah, atau dihapus. Silakan hubungi bagian kasir/keuangan.</div>
            </div>
        </div>
    @endif

    {{-- Header pasien --}}
    <x-ui.card class="mb-4">
        <div class="flex flex-wrap items-start gap-4">
            <div @class([
                'flex size-14 shrink-0 items-center justify-center rounded-2xl text-xl font-bold',
                'bg-sky-100 text-sky-700' => $k->jk === 'L',
                'bg-pink-100 text-pink-700' => $k->jk !== 'L',
            ])>{{ mb_strtoupper(mb_substr($k->nm_pasien, 0, 1)) }}</div>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-slate-800">{{ $k->nm_pasien }}</h2>
                    <x-ui.status :value="$k->stts" />
                    <x-ui.status :value="$k->status_bayar" />
                    @if ($k->kd_poli === config('khanza.kd_poli_igd'))
                        <x-ui.badge color="red">IGD</x-ui.badge>
                    @endif
                </div>
                <div class="mt-1 text-sm text-slate-500">
                    RM <span class="font-medium text-slate-700">{{ $k->no_rkm_medis }}</span>
                    · {{ $k->jk === 'L' ? 'Laki-laki' : 'Perempuan' }}
                    · {{ $k->tgl_lahir && $k->tgl_lahir !== '0000-00-00' ? \App\Services\Khanza\Umur::teks($k->tgl_lahir) : $k->umurdaftar.' '.$k->sttsumur }}
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3 lg:grid-cols-6">
                    <div><dt class="text-xs text-slate-400">No. Rawat</dt><dd class="font-mono text-slate-700">{{ $k->no_rawat }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Registrasi</dt><dd class="text-slate-700">{{ \Carbon\Carbon::parse($k->tgl_registrasi)->format('d/m/Y') }} {{ substr($k->jam_reg, 0, 5) }} · #{{ $k->no_reg }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Poliklinik</dt><dd class="text-slate-700">{{ $k->nm_poli }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Dokter</dt><dd class="text-slate-700">{{ $k->nm_dokter }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Cara bayar</dt><dd class="text-slate-700">{{ $k->png_jawab }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Penanggung jawab</dt><dd class="truncate text-slate-700">{{ $k->p_jawab }}</dd></div>
                </dl>
            </div>
        </div>
    </x-ui.card>

    {{-- Tab --}}
    <div class="mb-4 flex gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm sm:inline-flex">
        @foreach (['tindakan' => 'Tindakan', 'soap' => 'Pemeriksaan (SOAP)'] as $key => $label)
            <button
                type="button"
                wire:click="$set('tab', '{{ $key }}')"
                @class([
                    'flex-1 rounded-lg px-4 py-2 text-sm font-medium transition sm:flex-none',
                    'bg-brand-500 text-white shadow-sm' => $tab === $key,
                    'text-slate-600 hover:bg-slate-100' => $tab !== $key,
                ])
            >
                {{ $label }}
                <span @class(['ml-1 rounded-full px-1.5 text-xs', 'bg-white/20' => $tab === $key, 'bg-slate-100 text-slate-500' => $tab !== $key])>
                    {{ $key === 'tindakan' ? collect($this->tindakan)->sum(fn ($r) => $r->count()) : $this->pemeriksaan->count() }}
                </span>
            </button>
        @endforeach
    </div>

    @if ($tab === 'tindakan')
        {{-- ======================= TINDAKAN ======================= --}}
        <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ($jenisDef as $key => $item)
                <button
                    type="button"
                    wire:click="pilihJenis('{{ $key }}')"
                    @class([
                        'rounded-xl border px-4 py-3 text-left shadow-sm transition',
                        'border-brand-500 bg-brand-50 ring-2 ring-brand-500/30' => $jenis === $key,
                        'border-slate-200 bg-white hover:border-brand-300' => $jenis !== $key,
                    ])
                >
                    <div class="text-xs font-medium text-slate-500">Tindakan {{ $item['label'] }}</div>
                    <div class="mt-1 flex items-baseline justify-between gap-2">
                        <span class="text-base font-semibold text-slate-800">{{ $rp($subtotal[$key]) }}</span>
                        <span class="text-xs text-slate-400">{{ $this->tindakan[$key]->count() }} item</span>
                    </div>
                </button>
            @endforeach
            <div class="rounded-xl border border-accent-300 bg-accent-50 px-4 py-3 shadow-sm">
                <div class="text-xs font-medium text-accent-700">Total tindakan</div>
                <div class="mt-1 text-base font-semibold text-slate-800">{{ $rp($subtotal->sum()) }}</div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-5">
            {{-- Form input --}}
            <x-ui.card title="Input Tindakan {{ $def['label'] }}" class="lg:col-span-2">
                @if ($terkunci)
                    <x-ui.empty message="Registrasi terkunci, tidak bisa menambah tindakan." />
                @else
                    <form wire:submit="simpanTindakan" class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            @if ($def['dokter'])
                                <x-ui.select label="Dokter" wire:model="kdDokter" :options="$this->opsi['dokter']" placeholder="Pilih dokter" required :class="$def['petugas'] ? '' : 'sm:col-span-2'" />
                            @endif
                            @if ($def['petugas'])
                                <x-ui.select label="Petugas" wire:model="nip" :options="$this->opsi['petugas']" placeholder="Pilih petugas" required :class="$def['dokter'] ? '' : 'sm:col-span-2'" />
                            @endif
                            <x-ui.input label="Tanggal" type="date" wire:model="tglTindakan" required />
                            <x-ui.input label="Jam" type="time" step="1" wire:model="jamTindakan" required />
                        </div>

                        <div>
                            <x-ui.input label="Cari tindakan" type="search" wire:model.live.debounce.300ms="cariTindakan" placeholder="Kode, nama, atau kategori…" />
                            <div class="mt-2 max-h-72 overflow-y-auto rounded-lg border border-slate-200">
                                <div wire:loading.flex wire:target="cariTindakan,pilihJenis" class="hidden justify-center py-2 text-xs text-slate-400">Memuat…</div>
                                @forelse ($this->katalog as $item)
                                    <label wire:key="kat-{{ $jenis }}-{{ $item->kd_jenis_prw }}" class="flex cursor-pointer items-start gap-3 border-b border-slate-100 px-3 py-2 text-sm last:border-0 hover:bg-brand-50/60">
                                        <input type="checkbox" wire:model.live="pilih" value="{{ $item->kd_jenis_prw }}" class="mt-0.5 size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500" />
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-medium text-slate-700">{{ $item->nm_perawatan }}</span>
                                            <span class="block text-xs text-slate-400">{{ $item->kd_jenis_prw }} · {{ $item->nm_kategori }}</span>
                                        </span>
                                        <span class="whitespace-nowrap text-xs font-semibold text-slate-600">{{ $rp($item->total) }}</span>
                                    </label>
                                @empty
                                    <x-ui.empty message="Tindakan tidak ditemukan." />
                                @endforelse
                            </div>
                            <p class="mt-1 text-xs text-slate-400">Menampilkan maks. 50 hasil. Persempit dengan pencarian.</p>
                            @error('pilih')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>

                        @if ($this->terpilih->isNotEmpty())
                            <div class="rounded-lg border border-accent-200 bg-accent-50 p-3">
                                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-accent-700">Dipilih ({{ $this->terpilih->count() }})</div>
                                <ul class="space-y-1 text-sm">
                                    @foreach ($this->terpilih as $item)
                                        <li wire:key="pil-{{ $item->kd_jenis_prw }}" class="flex items-center justify-between gap-2">
                                            <span class="min-w-0 truncate text-slate-700">{{ $item->nm_perawatan }}</span>
                                            <span class="flex items-center gap-2">
                                                <span class="whitespace-nowrap text-xs text-slate-600">{{ $rp($item->total) }}</span>
                                                <button type="button" wire:click="hapusPilihan('{{ $item->kd_jenis_prw }}')" class="text-slate-400 hover:text-red-600" aria-label="Hapus pilihan">&times;</button>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="mt-2 flex justify-between border-t border-accent-200 pt-2 text-sm font-semibold text-slate-800">
                                    <span>Jumlah</span><span>{{ $rp($this->terpilih->sum('total')) }}</span>
                                </div>
                            </div>
                        @endif

                        <x-ui.button type="submit" wire:target="simpanTindakan" class="w-full" :disabled="$pilih === []">Simpan tindakan</x-ui.button>
                    </form>
                @endif
            </x-ui.card>

            {{-- Daftar tindakan yang sudah diberikan --}}
            <x-ui.card title="Tindakan {{ $def['label'] }} yang diberikan" :padding="false" class="lg:col-span-3">
                <x-slot:actions>
                    <span class="text-sm font-semibold text-slate-700">Subtotal {{ $rp($subtotal[$jenis]) }}</span>
                </x-slot:actions>

                <x-ui.table>
                    <x-slot:head>
                        <th>Tgl / Jam</th>
                        <th>Tindakan</th>
                        @if ($def['dokter'])<th>Dokter</th>@endif
                        @if ($def['petugas'])<th>Petugas</th>@endif
                        <th class="text-right">Biaya</th>
                        <th>Bayar</th>
                        <th></th>
                    </x-slot:head>

                    @forelse ($this->tindakan[$jenis] as $row)
                        @php
                            $pk = ['kd_jenis_prw' => $row->kd_jenis_prw, 'tgl_perawatan' => $row->tgl_perawatan, 'jam_rawat' => $row->jam_rawat];
                            if ($def['dokter']) { $pk['kd_dokter'] = $row->kd_dokter; }
                            if ($def['petugas']) { $pk['nip'] = $row->nip; }
                            $bolehHapus = $tindakanService->bolehHapus($row, $user, $terkunci);
                        @endphp
                        <tr wire:key="tdk-{{ $jenis }}-{{ md5(json_encode($pk)) }}">
                            <td class="whitespace-nowrap text-slate-600">
                                {{ \Carbon\Carbon::parse($row->tgl_perawatan)->format('d/m/Y') }}
                                <div class="text-xs text-slate-400">{{ $row->jam_rawat }}</div>
                            </td>
                            <td class="min-w-48">
                                <div class="font-medium text-slate-700">{{ $row->nm_perawatan }}</div>
                                <div class="text-xs text-slate-400">{{ $row->kd_jenis_prw }}</div>
                            </td>
                            @if ($def['dokter'])<td class="whitespace-nowrap">{{ $row->nm_dokter ?? $row->kd_dokter }}</td>@endif
                            @if ($def['petugas'])<td class="whitespace-nowrap">{{ $row->nm_petugas ?? $row->nip }}</td>@endif
                            <td class="whitespace-nowrap text-right font-medium">{{ $rp($row->biaya_rawat) }}</td>
                            <td><x-ui.badge :color="$row->stts_bayar === 'Sudah' ? 'green' : 'yellow'">{{ $row->stts_bayar ?? '-' }}</x-ui.badge></td>
                            <td class="text-right">
                                @if ($bolehHapus)
                                    <x-ui.button
                                        size="sm"
                                        variant="ghost"
                                        class="text-red-600 hover:bg-red-50"
                                        wire:click="hapusTindakan('{{ $jenis }}', {{ \Illuminate\Support\Js::from($pk) }})"
                                        wire:target="hapusTindakan"
                                        wire:confirm="Hapus tindakan {{ $row->nm_perawatan }}?"
                                    >Hapus</x-ui.button>
                                @else
                                    <span class="text-xs text-slate-300" title="Terkunci, sudah dibayar, atau lewat 48 jam">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="5 + (int) $def['dokter'] + (int) $def['petugas']" message="Belum ada tindakan {{ strtolower($def['label']) }}." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>
    @else
        {{-- ======================= SOAP ======================= --}}
        <div class="grid gap-4 lg:grid-cols-5">
            <x-ui.card :title="$editKunci ? 'Ubah Pemeriksaan' : 'Pemeriksaan Baru'" class="lg:col-span-2">
                @if ($editKunci)
                    <x-slot:actions>
                        <x-ui.badge color="yellow">Mode edit</x-ui.badge>
                    </x-slot:actions>
                @endif

                @if ($terkunci)
                    <x-ui.empty message="Registrasi terkunci, tidak bisa menambah atau mengubah pemeriksaan." />
                @elseif (! $user->isAdmin && ! $this->pegawaiLogin)
                    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">User login bukan pegawai/petugas, tidak bisa menyimpan pemeriksaan.</div>
                @else
                    <form wire:submit="simpanSoap" class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.input label="Tanggal" type="date" wire:model="soapTgl" required />
                            <x-ui.input label="Jam" type="time" step="1" wire:model="soapJam" required />
                            @if ($user->isAdmin)
                                <x-ui.select label="Dokter / petugas pemeriksa" wire:model="soapNip" :options="$this->opsi['pegawai']" placeholder="Pilih pegawai" required class="sm:col-span-2" />
                            @else
                                <div class="sm:col-span-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">Disimpan atas nama <span class="font-semibold text-slate-800">{{ $this->pegawaiLogin }}</span> ({{ $user->kode }})</div>
                            @endif
                        </div>

                        <fieldset class="rounded-lg border border-slate-200 p-3">
                            <legend class="px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">Tanda vital</legend>
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                <x-ui.input label="Suhu (°C)" wire:model="soap.suhu_tubuh" maxlength="5" />
                                <x-ui.input label="Tensi (mmHg)" wire:model="soap.tensi" maxlength="8" placeholder="120/80" />
                                <x-ui.input label="Nadi (/mnt)" wire:model="soap.nadi" maxlength="3" />
                                <x-ui.input label="Respirasi (/mnt)" wire:model="soap.respirasi" maxlength="3" />
                                <x-ui.input label="SpO2 (%)" wire:model="soap.spo2" maxlength="3" />
                                <x-ui.input label="GCS (E,V,M)" wire:model="soap.gcs" maxlength="10" />
                                <x-ui.input label="Tinggi (cm)" wire:model="soap.tinggi" maxlength="5" />
                                <x-ui.input label="Berat (kg)" wire:model="soap.berat" maxlength="5" />
                                <x-ui.input label="Lingkar perut (cm)" wire:model="soap.lingkar_perut" maxlength="5" />
                                <x-ui.select label="Kesadaran" wire:model="soap.kesadaran" :options="array_combine(\App\Services\RawatJalan\PemeriksaanService::KESADARAN, \App\Services\RawatJalan\PemeriksaanService::KESADARAN)" class="col-span-2" />
                                <x-ui.input label="Alergi" wire:model="soap.alergi" maxlength="80" class="col-span-2 sm:col-span-1" />
                            </div>
                        </fieldset>

                        <x-ui.textarea label="S — Subjek (keluhan)" wire:model="soap.keluhan" rows="3" maxlength="2000" />
                        <x-ui.textarea label="O — Objek (pemeriksaan)" wire:model="soap.pemeriksaan" rows="3" maxlength="2000" />
                        <x-ui.textarea label="A — Asesmen (penilaian)" wire:model="soap.penilaian" rows="2" maxlength="2000" />
                        <x-ui.textarea label="P — Plan (tindak lanjut)" wire:model="soap.rtl" rows="2" maxlength="2000" />
                        <x-ui.textarea label="Instruksi" wire:model="soap.instruksi" rows="2" maxlength="2000" />
                        <x-ui.textarea label="Evaluasi" wire:model="soap.evaluasi" rows="2" maxlength="2000" />

                        <div class="flex gap-2">
                            <x-ui.button type="submit" wire:target="simpanSoap" class="flex-1">{{ $editKunci ? 'Simpan perubahan' : 'Simpan pemeriksaan' }}</x-ui.button>
                            @if ($editKunci)
                                <x-ui.button variant="secondary" wire:click="batalEditSoap">Batal</x-ui.button>
                            @endif
                        </div>
                    </form>
                @endif
            </x-ui.card>

            <div class="space-y-3 lg:col-span-3">
                @forelse ($this->pemeriksaan as $row)
                    @php
                        $bolehUbah = $pemeriksaanService->bolehUbah($row, $user, $terkunci);
                        $vital = array_filter([
                            'Suhu' => $row->suhu_tubuh ? $row->suhu_tubuh.' °C' : null,
                            'Tensi' => $row->tensi ?: null,
                            'Nadi' => $row->nadi ? $row->nadi.'/mnt' : null,
                            'RR' => $row->respirasi ? $row->respirasi.'/mnt' : null,
                            'SpO2' => $row->spo2 ? $row->spo2.'%' : null,
                            'GCS' => $row->gcs ?: null,
                            'TB' => $row->tinggi ? $row->tinggi.' cm' : null,
                            'BB' => $row->berat ? $row->berat.' kg' : null,
                            'LP' => $row->lingkar_perut ? $row->lingkar_perut.' cm' : null,
                        ]);
                        $isi = array_filter([
                            'S' => $row->keluhan, 'O' => $row->pemeriksaan, 'A' => $row->penilaian, 'P' => $row->rtl,
                            'Instruksi' => $row->instruksi, 'Evaluasi' => $row->evaluasi,
                        ], fn ($v) => trim((string) $v) !== '');
                    @endphp
                    <article wire:key="soap-{{ $row->tgl_perawatan }}-{{ $row->jam_rawat }}" @class([
                        'rounded-xl border bg-white p-4 shadow-sm',
                        'border-accent-400 ring-2 ring-accent-400/40' => $editKunci === $row->tgl_perawatan.'|'.$row->jam_rawat,
                        'border-slate-200' => $editKunci !== $row->tgl_perawatan.'|'.$row->jam_rawat,
                    ])>
                        <header class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <div class="font-semibold text-slate-800">{{ \Carbon\Carbon::parse($row->tgl_perawatan)->format('d/m/Y') }} · {{ $row->jam_rawat }}</div>
                                <div class="text-xs text-slate-500">{{ $row->nm_pegawai ?? $row->nip }}@if ($row->jbtn) · {{ $row->jbtn }}@endif</div>
                            </div>
                            <div class="flex items-center gap-1">
                                <x-ui.badge color="blue">{{ $row->kesadaran }}</x-ui.badge>
                                @if ($bolehUbah)
                                    <x-ui.button size="sm" variant="ghost" wire:click="editSoap('{{ $row->tgl_perawatan }}', '{{ $row->jam_rawat }}')">Ubah</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" class="text-red-600 hover:bg-red-50" wire:click="hapusSoap('{{ $row->tgl_perawatan }}', '{{ $row->jam_rawat }}')" wire:target="hapusSoap" wire:confirm="Hapus pemeriksaan {{ $row->tgl_perawatan }} {{ $row->jam_rawat }}?">Hapus</x-ui.button>
                                @endif
                            </div>
                        </header>

                        @if ($vital || $row->alergi)
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @foreach ($vital as $label => $nilai)
                                    <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-600"><span class="text-slate-400">{{ $label }}</span> {{ $nilai }}</span>
                                @endforeach
                                @if ($row->alergi)
                                    <span class="rounded-md bg-red-50 px-2 py-0.5 text-xs text-red-700"><span class="text-red-400">Alergi</span> {{ $row->alergi }}</span>
                                @endif
                            </div>
                        @endif

                        @if ($isi)
                            <dl class="mt-3 space-y-2 text-sm">
                                @foreach ($isi as $label => $teks)
                                    <div class="flex gap-3">
                                        <dt class="w-20 shrink-0 font-semibold text-brand-600">{{ $label }}</dt>
                                        <dd class="whitespace-pre-line text-slate-700">{{ $teks }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </article>
                @empty
                    <x-ui.card>
                        <x-ui.empty message="Belum ada pemeriksaan untuk kunjungan ini." />
                    </x-ui.card>
                @endforelse
            </div>
        </div>
    @endif
</div>
