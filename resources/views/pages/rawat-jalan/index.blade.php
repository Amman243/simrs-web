<?php

use App\Models\Dokter;
use App\Models\Penjab;
use App\Models\Poliklinik;
use App\Services\RawatJalan\AturanException;
use App\Services\RawatJalan\StatusService;
use App\Services\RawatJalan\WorklistQuery;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Rawat Jalan')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $tgl1 = '';

    #[Url]
    public string $tgl2 = '';

    #[Url]
    public string $kd_poli = '';

    #[Url]
    public string $kd_dokter = '';

    #[Url]
    public string $stts = '';

    #[Url]
    public string $kd_pj = '';

    #[Url]
    public string $status_bayar = '';

    #[Url]
    public string $cari = '';

    public function mount(): void
    {
        $this->authorize('tindakan_ralan');

        $this->tgl1 = $this->tanggalValid($this->tgl1) ?? today()->toDateString();
        $this->tgl2 = $this->tanggalValid($this->tgl2) ?? $this->tgl1;

        if ($kunci = $this->dokterKunci()) {
            $this->kd_dokter = $kunci;
        }
    }

    public function updated(string $property): void
    {
        if ($property === 'kd_dokter' && ($kunci = $this->dokterKunci())) {
            $this->kd_dokter = $kunci;
        }

        $this->resetPage();
    }

    public function dokterKunci(): ?string
    {
        return WorklistQuery::dokterTerkunci(auth()->user());
    }

    /**
     * @return array<string, string>
     */
    public function filter(): array
    {
        $tgl1 = $this->tanggalValid($this->tgl1) ?? today()->toDateString();
        $tgl2 = $this->tanggalValid($this->tgl2) ?? $tgl1;

        return [
            'tgl1' => min($tgl1, $tgl2),
            'tgl2' => max($tgl1, $tgl2),
            'kd_poli' => $this->kd_poli,
            'kd_dokter' => $this->dokterKunci() ?? $this->kd_dokter,
            'stts' => $this->stts,
            'kd_pj' => $this->kd_pj,
            'status_bayar' => $this->status_bayar,
            'cari' => $this->cari,
        ];
    }

    #[Computed]
    public function rows()
    {
        return WorklistQuery::paginate($this->filter(), 20);
    }

    #[Computed]
    public function ringkasan(): array
    {
        return WorklistQuery::ringkasan($this->filter());
    }

    #[Computed(persist: true)]
    public function opsi(): array
    {
        return [
            'poli' => Poliklinik::aktif()->where('kd_poli', '<>', '-')->orderBy('nm_poli')->pluck('nm_poli', 'kd_poli')->all(),
            'dokter' => Dokter::aktif()->where('kd_dokter', '<>', '-')->orderBy('nm_dokter')->pluck('nm_dokter', 'kd_dokter')->all(),
            'penjab' => Penjab::aktif()->where('kd_pj', '<>', '-')->orderBy('png_jawab')->pluck('png_jawab', 'kd_pj')->all(),
            'stts' => array_combine(WorklistQuery::STATUS, WorklistQuery::STATUS),
            'status_bayar' => array_combine(WorklistQuery::STATUS_BAYAR, WorklistQuery::STATUS_BAYAR),
        ];
    }

    public function filterStatus(string $stts): void
    {
        $this->stts = $stts;
        $this->resetPage();
    }

    public function hariIni(): void
    {
        $this->tgl1 = $this->tgl2 = today()->toDateString();
        $this->resetPage();
    }

    public function resetFilter(): void
    {
        $this->reset(['kd_poli', 'kd_dokter', 'stts', 'kd_pj', 'status_bayar', 'cari']);
        $this->hariIni();

        if ($kunci = $this->dokterKunci()) {
            $this->kd_dokter = $kunci;
        }
    }

    public function ubahStatus(string $noRawat, string $status): void
    {
        $this->authorize('tindakan_ralan');

        $kunci = $this->dokterKunci();
        if ($kunci && DB::table('reg_periksa')->where('no_rawat', $noRawat)->value('kd_dokter') !== $kunci) {
            abort(403);
        }

        try {
            app(StatusService::class)->ubah($noRawat, $status);
        } catch (AturanException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        unset($this->rows, $this->ringkasan);
        $this->dispatch('toast', message: "Status {$noRawat} diubah menjadi {$status}.", type: 'success');
    }

    private function tanggalValid(string $tgl): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) && strtotime($tgl) !== false ? $tgl : null;
    }
};
?>

<div>
    <x-ui.page-header title="Rawat Jalan" subtitle="Daftar kunjungan pasien rawat jalan & IGD.">
        <x-slot:actions>
            <x-ui.button variant="secondary" wire:click="hariIni">Hari ini</x-ui.button>
            <x-ui.button variant="ghost" wire:click="resetFilter">Reset filter</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Ringkasan --}}
    @php
        $chips = [
            ['label' => 'Total', 'value' => $this->ringkasan['total'], 'stts' => '', 'tone' => 'border-slate-200 bg-white text-slate-700'],
            ['label' => 'Belum', 'value' => $this->ringkasan['Belum'], 'stts' => 'Belum', 'tone' => 'border-accent-200 bg-accent-50 text-accent-700'],
            ['label' => 'Sudah', 'value' => $this->ringkasan['Sudah'], 'stts' => 'Sudah', 'tone' => 'border-brand-200 bg-brand-50 text-brand-700'],
            ['label' => 'Batal', 'value' => $this->ringkasan['Batal'], 'stts' => 'Batal', 'tone' => 'border-red-200 bg-red-50 text-red-700'],
        ];
    @endphp
    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ($chips as $chip)
            <button
                type="button"
                wire:click="filterStatus('{{ $chip['stts'] }}')"
                @class([
                    'flex items-center justify-between rounded-xl border px-4 py-3 text-left shadow-sm transition hover:shadow',
                    $chip['tone'],
                    'ring-2 ring-accent-400' => $stts === $chip['stts'],
                ])
            >
                <span class="text-sm font-medium">{{ $chip['label'] }}</span>
                <span class="text-2xl font-semibold">{{ number_format($chip['value']) }}</span>
            </button>
        @endforeach
    </div>

    {{-- Filter --}}
    <x-ui.card class="mb-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
            <x-ui.input label="Dari tanggal" type="date" wire:model.live="tgl1" class="xl:col-span-1" />
            <x-ui.input label="Sampai tanggal" type="date" wire:model.live="tgl2" class="xl:col-span-1" />
            <x-ui.select label="Poliklinik" wire:model.live="kd_poli" :options="$this->opsi['poli']" placeholder="Semua poli" class="xl:col-span-1" />
            <x-ui.select
                label="Dokter"
                wire:model.live="kd_dokter"
                :options="$this->opsi['dokter']"
                placeholder="Semua dokter"
                :disabled="(bool) $this->dokterKunci()"
                :hint="$this->dokterKunci() ? 'Terkunci ke dokter login' : null"
                class="xl:col-span-1"
            />
            <x-ui.select label="Status" wire:model.live="stts" :options="$this->opsi['stts']" placeholder="Semua status" class="xl:col-span-1" />
            <x-ui.select label="Cara bayar" wire:model.live="kd_pj" :options="$this->opsi['penjab']" placeholder="Semua" class="xl:col-span-1" />
            <x-ui.select label="Status bayar" wire:model.live="status_bayar" :options="$this->opsi['status_bayar']" placeholder="Semua" class="xl:col-span-1" />
            <x-ui.input label="Cari" type="search" wire:model.live.debounce.400ms="cari" placeholder="No. rawat, RM, nama…" class="xl:col-span-1" />
        </div>
    </x-ui.card>

    {{-- Daftar --}}
    <x-ui.card :padding="false">
        <div class="relative">
            <div wire:loading.flex wire:target="tgl1,tgl2,kd_poli,kd_dokter,stts,kd_pj,status_bayar,cari,filterStatus,hariIni,resetFilter,gotoPage,nextPage,previousPage" class="absolute inset-0 z-10 hidden items-center justify-center bg-white/60">
                <svg class="size-6 animate-spin text-brand-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
            </div>

            <x-ui.table>
                <x-slot:head>
                    <th>No. Reg</th>
                    <th>No. Rawat</th>
                    <th>Tgl / Jam</th>
                    <th>Pasien</th>
                    <th>Poli</th>
                    <th>Dokter</th>
                    <th>Cara Bayar</th>
                    <th>Status</th>
                    <th>Bayar</th>
                    <th class="text-right">Aksi</th>
                </x-slot:head>

                @forelse ($this->rows as $row)
                    <tr wire:key="reg-{{ $row->no_rawat }}">
                        <td class="font-semibold text-slate-700">{{ $row->no_reg }}</td>
                        <td class="whitespace-nowrap font-mono text-xs">{{ $row->no_rawat }}</td>
                        <td class="whitespace-nowrap text-slate-600">
                            {{ \Carbon\Carbon::parse($row->tgl_registrasi)->format('d/m/Y') }}
                            <div class="text-xs text-slate-400">{{ substr($row->jam_reg, 0, 5) }}</div>
                        </td>
                        <td class="min-w-48">
                            <div class="font-medium text-slate-800">{{ $row->nm_pasien }}</div>
                            <div class="text-xs text-slate-500">RM {{ $row->no_rkm_medis }} · {{ $row->jk }} · {{ $row->umur }}</div>
                        </td>
                        <td class="whitespace-nowrap">
                            @if ($row->kd_poli === config('khanza.kd_poli_igd'))
                                <x-ui.badge color="red">{{ $row->nm_poli }}</x-ui.badge>
                            @else
                                {{ $row->nm_poli }}
                            @endif
                        </td>
                        <td class="whitespace-nowrap">{{ $row->nm_dokter }}</td>
                        <td class="whitespace-nowrap">{{ $row->png_jawab }}</td>
                        <td><x-ui.status :value="$row->stts" /></td>
                        <td><x-ui.status :value="$row->status_bayar" /></td>
                        <td>
                            <div class="flex items-center justify-end gap-1.5">
                                <x-ui.button size="sm" :href="route('rawat-jalan.show', ['noRawat' => $row->no_rawat])" wire:navigate>Periksa</x-ui.button>

                                @can('kamar_inap')
                                    @if (Route::has('rawat-inap.masuk'))
                                        <x-ui.button size="sm" variant="secondary" :href="route('rawat-inap.masuk', ['no_rawat' => $row->no_rawat])" wire:navigate>Rawat Inap</x-ui.button>
                                    @endif
                                @endcan

                                <div
                                    x-data="{
                                        open: false, top: 0, left: 0,
                                        toggle(e) {
                                            const r = e.currentTarget.getBoundingClientRect()
                                            this.top = r.bottom + 4
                                            this.left = Math.max(8, r.right - 176)
                                            this.open = ! this.open
                                        },
                                    }"
                                    x-on:scroll.window="open = false"
                                    x-on:resize.window="open = false"
                                >
                                    <button type="button" x-on:click="toggle($event)" class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-slate-600 hover:bg-slate-50" aria-label="Ubah status">
                                        <svg class="size-4" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4z" /></svg>
                                    </button>
                                    <div
                                        x-show="open"
                                        x-cloak
                                        x-transition.opacity
                                        x-on:click.outside="open = false"
                                        x-bind:style="`top:${top}px;left:${left}px`"
                                        class="fixed z-40 w-44 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg"
                                    >
                                        <div class="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-400">Ubah status</div>
                                        <button type="button" x-on:click="open = false" wire:click="ubahStatus('{{ $row->no_rawat }}', 'Sudah')" class="flex w-full items-center gap-2 px-3 py-2 text-left text-slate-700 hover:bg-brand-50">
                                            <span class="size-2 rounded-full bg-brand-500"></span> Sudah diperiksa
                                        </button>
                                        <button type="button" x-on:click="open = false" wire:click="ubahStatus('{{ $row->no_rawat }}', 'Belum')" class="flex w-full items-center gap-2 px-3 py-2 text-left text-slate-700 hover:bg-accent-50">
                                            <span class="size-2 rounded-full bg-accent-400"></span> Belum diperiksa
                                        </button>
                                        <button type="button" x-on:click="open = false" wire:click="ubahStatus('{{ $row->no_rawat }}', 'Batal')" wire:confirm="Batalkan registrasi {{ $row->no_rawat }} ({{ $row->nm_pasien }})? Biaya registrasi akan dinolkan." class="flex w-full items-center gap-2 px-3 py-2 text-left text-red-700 hover:bg-red-50">
                                            <span class="size-2 rounded-full bg-red-500"></span> Batal
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <x-ui.empty colspan="10" message="Tidak ada kunjungan rawat jalan untuk filter ini." />
                @endforelse
            </x-ui.table>
        </div>

        @php $p = $this->rows; @endphp
        @if ($p->total() > 0)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-3 text-sm text-slate-600">
                <div>Menampilkan {{ $p->firstItem() }}–{{ $p->lastItem() }} dari {{ number_format($p->total()) }} kunjungan</div>
                @if ($p->hasPages())
                    <div class="flex items-center gap-2">
                        <x-ui.button size="sm" variant="secondary" wire:click="previousPage" :disabled="$p->onFirstPage()">&larr; Sebelumnya</x-ui.button>
                        <span class="text-xs text-slate-500">Hal. {{ $p->currentPage() }} / {{ $p->lastPage() }}</span>
                        <x-ui.button size="sm" variant="secondary" wire:click="nextPage" :disabled="! $p->hasMorePages()">Berikutnya &rarr;</x-ui.button>
                    </div>
                @endif
            </div>
        @endif
    </x-ui.card>
</div>
