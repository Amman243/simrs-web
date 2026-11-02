<?php

use App\Services\Khanza\Registrasi;
use App\Services\RawatInap\BiayaKamar;
use App\Services\RawatInap\KamarInapService;
use App\Services\RawatInap\RawatInapException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Rawat Inap')] class extends Component
{
    use WithPagination;

    #[Url] public string $mode = 'dirawat'; // dirawat | masuk | pulang

    #[Url] public string $dari = '';

    #[Url] public string $sampai = '';

    #[Url] public string $bangsal = '';

    #[Url] public string $cari = '';

    public bool $pasienSaya = false;

    public bool $tampilKamar = false;

    /** PK baris kamar_inap yang sedang diproses + info tampilan. */
    #[Locked] public array $target = [];

    public bool $showPindah = false;

    public string $kamarBaru = '';

    public string $tglPindah = '';

    public string $jamPindah = '';

    public bool $showPulang = false;

    public string $sttsPulang = '';

    public string $diagnosaAkhir = '';

    public string $tglKeluar = '';

    public string $jamKeluar = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->pasienSaya = $user->isDokter() && ! $user->isAdmin;
        $this->dari = $this->dari ?: today()->toDateString();
        $this->sampai = $this->sampai ?: today()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['mode', 'dari', 'sampai', 'bangsal', 'cari', 'pasienSaya'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function rows()
    {
        $service = app(KamarInapService::class);
        $user = auth()->user();

        $paginator = $service->daftar([
            'mode' => $this->mode,
            'dari' => $this->dari,
            'sampai' => $this->sampai,
            'kd_bangsal' => $this->bangsal,
            'cari' => $this->cari,
            'kd_dokter' => $this->pasienSaya && $user->isDokter() ? $user->kdDokter : null,
        ])->paginate(15);

        $sekarang = now();
        $paginator->getCollection()->transform(function ($row) use ($service, $sekarang) {
            $row->berjalan = $row->stts_pulang === '-';
            if ($row->berjalan) {
                ['lama' => $row->lama, 'ttl_biaya' => $row->ttl_biaya] = $service->berjalan($row, $sekarang);
            }

            return $row;
        });

        return $paginator;
    }

    #[Computed]
    public function daftarBangsal(): array
    {
        return DB::table('bangsal')
            ->whereIn('kd_bangsal', DB::table('kamar')->where('statusdata', '1')->select('kd_bangsal'))
            ->orderBy('nm_bangsal')
            ->pluck('nm_bangsal', 'kd_bangsal')
            ->all();
    }

    #[Computed]
    public function ketersediaan()
    {
        return app(KamarInapService::class)->ketersediaan();
    }

    #[Computed]
    public function kamarKosong()
    {
        return app(KamarInapService::class)->kamarKosong($this->target['kd_pj'] ?? null);
    }

    #[Computed]
    public function statusPulang(): array
    {
        return array_combine(KamarInapService::STATUS_PULANG, KamarInapService::STATUS_PULANG);
    }

    #[Computed]
    public function diagnosaAkhirWajib(): bool
    {
        return app(BiayaKamar::class)->diagnosaAkhirWajib();
    }

    // ------------------------------------------------------------------ Aksi

    private function pilih(string $noRawat, string $tglMasuk, string $jamMasuk): bool
    {
        $row = app(KamarInapService::class)->baris($noRawat, $tglMasuk, $jamMasuk);

        if (! $row) {
            $this->dispatch('toast', message: 'Data kamar inap tidak ditemukan.', type: 'error');

            return false;
        }
        if (Registrasi::terkunci($noRawat)) {
            $this->dispatch('toast', message: 'Data billing sudah terverifikasi. Silakan hubungi bagian kasir/keuangan.', type: 'error');

            return false;
        }

        $this->target = [
            'no_rawat' => $row->no_rawat,
            'tgl_masuk' => $row->tgl_masuk,
            'jam_masuk' => $row->jam_masuk,
            'kd_kamar' => $row->kd_kamar,
            'nm_bangsal' => $row->nm_bangsal,
            'kd_pj' => $row->kd_pj,
            'nm_pasien' => $row->nm_pasien,
            'no_rkm_medis' => $row->no_rkm_medis,
            'diagnosa_awal' => $row->diagnosa_awal,
            'stts_pulang' => $row->stts_pulang,
        ];
        $this->resetErrorBag();
        unset($this->kamarKosong);

        return true;
    }

    public function bukaPindah(string $noRawat, string $tglMasuk, string $jamMasuk): void
    {
        $this->authorize('kamar_inap');

        if ($this->pilih($noRawat, $tglMasuk, $jamMasuk)) {
            $this->kamarBaru = '';
            $this->tglPindah = now()->toDateString();
            $this->jamPindah = now()->format('H:i:s');
            $this->showPindah = true;
        }
    }

    public function simpanPindah(): void
    {
        $this->authorize('kamar_inap');
        $this->validate([
            'kamarBaru' => 'required|string',
            'tglPindah' => 'required|date_format:Y-m-d',
            'jamPindah' => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
        ], [], ['kamarBaru' => 'kamar tujuan', 'tglPindah' => 'tanggal pindah', 'jamPindah' => 'jam pindah']);

        try {
            app(KamarInapService::class)->pindah(
                $this->target['no_rawat'], $this->target['tgl_masuk'], $this->target['jam_masuk'],
                $this->kamarBaru, KamarInapService::parseWaktu($this->tglPindah, $this->jamPindah),
            );
        } catch (RawatInapException $e) {
            $this->addError('kamarBaru', $e->getMessage());

            return;
        }

        $this->showPindah = false;
        $this->dispatch('toast', message: "{$this->target['nm_pasien']} dipindah ke kamar {$this->kamarBaru}.");
        $this->segarkan();
    }

    public function bukaPulang(string $noRawat, string $tglMasuk, string $jamMasuk): void
    {
        $this->authorize('kamar_inap');

        if ($this->pilih($noRawat, $tglMasuk, $jamMasuk)) {
            $this->sttsPulang = '';
            $this->diagnosaAkhir = '';
            $this->tglKeluar = now()->toDateString();
            $this->jamKeluar = now()->format('H:i:s');
            $this->showPulang = true;
        }
    }

    public function simpanPulang(): void
    {
        $this->authorize('kamar_inap');
        $this->validate([
            'sttsPulang' => ['required', Rule::in(KamarInapService::STATUS_PULANG)],
            'diagnosaAkhir' => [$this->diagnosaAkhirWajib ? 'required' : 'nullable', 'string', 'max:100'],
            'tglKeluar' => 'required|date_format:Y-m-d',
            'jamKeluar' => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
        ], [], ['sttsPulang' => 'status pulang', 'diagnosaAkhir' => 'diagnosa akhir', 'tglKeluar' => 'tanggal keluar', 'jamKeluar' => 'jam keluar']);

        try {
            app(KamarInapService::class)->pulang(
                $this->target['no_rawat'], $this->target['tgl_masuk'], $this->target['jam_masuk'],
                $this->sttsPulang, $this->diagnosaAkhir, KamarInapService::parseWaktu($this->tglKeluar, $this->jamKeluar),
            );
        } catch (RawatInapException $e) {
            $this->addError('sttsPulang', $e->getMessage());

            return;
        }

        $this->showPulang = false;
        $this->dispatch('toast', message: "{$this->target['nm_pasien']} dipulangkan ({$this->sttsPulang}).");
        $this->segarkan();
    }

    public function batalPulang(string $noRawat, string $tglMasuk, string $jamMasuk): void
    {
        $this->authorize('kamar_inap');

        try {
            app(KamarInapService::class)->batalPulang($noRawat, $tglMasuk, $jamMasuk);
        } catch (RawatInapException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->dispatch('toast', message: 'Status pulang dibatalkan, pasien kembali dirawat.');
        $this->segarkan();
    }

    private function segarkan(): void
    {
        unset($this->rows, $this->ketersediaan, $this->kamarKosong);
    }
};
?>

<div>
    <x-ui.page-header title="Rawat Inap" subtitle="Daftar pasien kamar inap, pindah kamar, dan pemulangan.">
        <x-slot:actions>
            <x-ui.button variant="secondary" wire:click="$toggle('tampilKamar')">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 18v-6a3 3 0 013-3h12a3 3 0 013 3v6M3 14h18M7 9V6" /></svg>
                Ketersediaan kamar
            </x-ui.button>
            <x-ui.button href="{{ route('rawat-inap.masuk') }}" wire:navigate>
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                Masuk kamar
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Ketersediaan kamar --}}
    @if ($tampilKamar)
        @php
            $kamarPerBangsal = $this->ketersediaan->groupBy('nm_bangsal');
            $jumlahKosong = $this->ketersediaan->where('status', 'KOSONG')->count();
        @endphp
        <x-ui.card title="Ketersediaan kamar" subtitle="{{ $jumlahKosong }} dari {{ $this->ketersediaan->count() }} kamar kosong" class="mb-6">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($kamarPerBangsal as $nmBangsal => $daftar)
                    <div class="rounded-lg border border-slate-200">
                        <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-3 py-2">
                            <span class="text-sm font-semibold text-slate-700">{{ $nmBangsal }}</span>
                            <span class="text-xs text-slate-500">{{ $daftar->where('status', 'KOSONG')->count() }}/{{ $daftar->count() }} kosong</span>
                        </div>
                        <ul class="divide-y divide-slate-100 text-sm">
                            @foreach ($daftar as $k)
                                <li class="flex items-center justify-between gap-2 px-3 py-2">
                                    <div>
                                        <div class="font-medium text-slate-700">{{ $k->kd_kamar }}</div>
                                        <div class="text-xs text-slate-500">{{ $k->kelas }} &middot; Rp {{ number_format($k->trf_kamar, 0, ',', '.') }}</div>
                                    </div>
                                    <x-ui.status :value="$k->status" />
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    {{-- Filter --}}
    <x-ui.card class="mb-6">
        <div class="flex flex-wrap items-end gap-4">
            <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1 text-sm">
                @foreach (['dirawat' => 'Masih dirawat', 'masuk' => 'Tanggal masuk', 'pulang' => 'Tanggal pulang'] as $val => $label)
                    <button type="button" wire:click="$set('mode', '{{ $val }}')" @class([
                        'rounded-md px-3 py-1.5 font-medium transition',
                        'bg-accent-400 text-brand-900 shadow-sm' => $mode === $val,
                        'text-slate-600 hover:text-slate-800' => $mode !== $val,
                    ])>{{ $label }}</button>
                @endforeach
            </div>

            @if ($mode !== 'dirawat')
                <x-ui.input type="date" label="Dari" wire:model.live="dari" class="w-40" />
                <x-ui.input type="date" label="Sampai" wire:model.live="sampai" class="w-40" />
            @endif

            <x-ui.select label="Bangsal" wire:model.live="bangsal" :options="$this->daftarBangsal" placeholder="Semua bangsal" class="w-48" />

            <x-ui.input label="Cari" wire:model.live.debounce.400ms="cari" placeholder="No. rawat, RM, nama, kamar, dokter..." class="min-w-56 flex-1" />

            @if (auth()->user()->isDokter())
                <label class="flex items-center gap-2 pb-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model.live="pasienSaya" class="size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500" />
                    Pasien saya (DPJP)
                </label>
            @endif
        </div>
    </x-ui.card>

    {{-- Daftar --}}
    <x-ui.card :padding="false">
        <x-ui.table>
            <x-slot:head>
                <th>No. Rawat</th>
                <th>Pasien</th>
                <th>Kamar</th>
                <th class="text-right">Tarif</th>
                <th>Masuk</th>
                <th>Keluar</th>
                <th class="text-right">Lama</th>
                <th class="text-right">Total biaya</th>
                <th>Dokter / DPJP</th>
                <th>Penjamin</th>
                <th>Diagnosa</th>
                <th>Status</th>
                <th class="text-right">Aksi</th>
            </x-slot:head>

            @forelse ($this->rows as $row)
                <tr wire:key="ki-{{ $row->no_rawat }}-{{ $row->tgl_masuk }}-{{ $row->jam_masuk }}" class="align-top">
                    <td class="whitespace-nowrap font-mono text-xs text-slate-600">{{ $row->no_rawat }}</td>
                    <td class="min-w-44">
                        <div class="font-medium text-slate-800">{{ $row->nm_pasien }}</div>
                        <div class="text-xs text-slate-500">RM {{ $row->no_rkm_medis }} &middot; {{ $row->jk }} &middot; {{ $row->umurdaftar }} {{ $row->sttsumur }}</div>
                    </td>
                    <td class="whitespace-nowrap">
                        <div class="font-medium text-slate-700">{{ $row->kd_kamar }}</div>
                        <div class="text-xs text-slate-500">{{ $row->nm_bangsal }} &middot; {{ $row->kelas }}</div>
                    </td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ number_format($row->trf_kamar, 0, ',', '.') }}</td>
                    <td class="whitespace-nowrap text-xs">
                        <div>{{ \Carbon\Carbon::parse($row->tgl_masuk)->format('d-m-Y') }}</div>
                        <div class="text-slate-500">{{ $row->jam_masuk }}</div>
                    </td>
                    <td class="whitespace-nowrap text-xs">
                        @if ($row->tgl_keluar && $row->tgl_keluar !== '0000-00-00')
                            <div>{{ \Carbon\Carbon::parse($row->tgl_keluar)->format('d-m-Y') }}</div>
                            <div class="text-slate-500">{{ $row->jam_keluar }}</div>
                        @else
                            <span class="text-slate-400">&mdash;</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap text-right tabular-nums">
                        {{ (int) $row->lama }} hr
                        @if ($row->berjalan)<div class="text-[10px] text-slate-400">s.d. sekarang</div>@endif
                    </td>
                    <td class="whitespace-nowrap text-right font-medium tabular-nums">{{ number_format($row->ttl_biaya, 0, ',', '.') }}</td>
                    <td class="min-w-40 text-xs">
                        <div class="text-slate-700">{{ $row->nm_dokter ?? '-' }}</div>
                        @if ($row->dpjp)<div class="text-slate-500">DPJP: {{ $row->dpjp }}</div>@endif
                    </td>
                    <td class="whitespace-nowrap text-xs">{{ $row->png_jawab ?? '-' }}</td>
                    <td class="min-w-40 text-xs">
                        <div class="text-slate-700">{{ $row->diagnosa_awal ?: '-' }}</div>
                        @if ($row->diagnosa_akhir)<div class="text-slate-500">Akhir: {{ $row->diagnosa_akhir }}</div>@endif
                    </td>
                    <td><x-ui.status :value="$row->stts_pulang" /></td>
                    <td class="whitespace-nowrap text-right">
                        <div class="flex justify-end gap-1">
                            @if ($row->stts_pulang === '-')
                                <x-ui.button size="sm" variant="secondary" wire:click="bukaPindah('{{ $row->no_rawat }}', '{{ $row->tgl_masuk }}', '{{ $row->jam_masuk }}')">Pindah</x-ui.button>
                                <x-ui.button size="sm" variant="accent" wire:click="bukaPulang('{{ $row->no_rawat }}', '{{ $row->tgl_masuk }}', '{{ $row->jam_masuk }}')">Pulang</x-ui.button>
                            @elseif ($row->stts_pulang !== 'Pindah Kamar' && $row->status_kamar === 'KOSONG')
                                <x-ui.button size="sm" variant="ghost"
                                    wire:click="batalPulang('{{ $row->no_rawat }}', '{{ $row->tgl_masuk }}', '{{ $row->jam_masuk }}')"
                                    wire:confirm="Batalkan status pulang {{ $row->nm_pasien }}? Pasien akan kembali menempati kamar {{ $row->kd_kamar }}.">
                                    Batalkan pulang
                                </x-ui.button>
                            @endif
                            <x-ui.button size="sm" href="{{ route('rawat-inap.show', ['noRawat' => $row->no_rawat]) }}" wire:navigate>Perawatan</x-ui.button>
                        </div>
                    </td>
                </tr>
            @empty
                <x-ui.empty colspan="13" message="Tidak ada pasien rawat inap sesuai filter." />
            @endforelse
        </x-ui.table>

        @if ($this->rows->hasPages())
            <div class="border-t border-slate-100 px-4 py-3">{{ $this->rows->links() }}</div>
        @endif
    </x-ui.card>

    {{-- Modal pindah kamar --}}
    <x-ui.modal wire:model="showPindah" title="Pindah Kamar" maxWidth="xl">
        @if ($target)
            <div class="mb-4 rounded-lg bg-slate-50 p-3 text-sm">
                <div class="font-medium text-slate-800">{{ $target['nm_pasien'] }} <span class="font-normal text-slate-500">(RM {{ $target['no_rkm_medis'] }})</span></div>
                <div class="text-slate-600">Kamar saat ini: <span class="font-medium">{{ $target['kd_kamar'] }}</span> &middot; {{ $target['nm_bangsal'] }}</div>
                <div class="text-xs text-slate-500">Masuk {{ \Carbon\Carbon::parse($target['tgl_masuk'])->format('d-m-Y') }} {{ $target['jam_masuk'] }}</div>
            </div>

            <div class="space-y-4">
                <x-ui.select label="Kamar tujuan" wire:model="kamarBaru" placeholder="Pilih kamar kosong" required>
                    @foreach ($this->kamarKosong as $nmBangsal => $daftar)
                        <optgroup label="{{ $nmBangsal }}">
                            @foreach ($daftar as $k)
                                <option value="{{ $k->kd_kamar }}">{{ $k->kd_kamar }} — {{ $k->kelas }} — Rp {{ number_format($k->tarif, 0, ',', '.') }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </x-ui.select>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input type="date" label="Tanggal pindah" wire:model="tglPindah" required />
                    <x-ui.input type="time" step="1" label="Jam pindah" wire:model="jamPindah" required />
                </div>
                <p class="text-xs text-slate-500">Segmen kamar lama ditutup dengan status <em>Pindah Kamar</em> pada waktu pindah, lalu dibuat segmen baru di kamar tujuan.</p>
            </div>
        @endif
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Batal</x-ui.button>
            <x-ui.button wire:click="simpanPindah">Simpan</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- Modal pulang --}}
    <x-ui.modal wire:model="showPulang" title="Pulang / Check Out" maxWidth="xl">
        @if ($target)
            <div class="mb-4 rounded-lg bg-slate-50 p-3 text-sm">
                <div class="font-medium text-slate-800">{{ $target['nm_pasien'] }} <span class="font-normal text-slate-500">(RM {{ $target['no_rkm_medis'] }})</span></div>
                <div class="text-slate-600">Kamar {{ $target['kd_kamar'] }} &middot; {{ $target['nm_bangsal'] }} &middot; masuk {{ \Carbon\Carbon::parse($target['tgl_masuk'])->format('d-m-Y') }} {{ $target['jam_masuk'] }}</div>
                <div class="text-xs text-slate-500">Diagnosa awal: {{ $target['diagnosa_awal'] ?: '-' }}</div>
            </div>

            <div class="space-y-4">
                <x-ui.select label="Status pulang" wire:model="sttsPulang" :options="$this->statusPulang" placeholder="Pilih status" required />
                <x-ui.input label="Diagnosa akhir" wire:model="diagnosaAkhir" maxlength="100" :required="$this->diagnosaAkhirWajib" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input type="date" label="Tanggal keluar" wire:model="tglKeluar" required />
                    <x-ui.input type="time" step="1" label="Jam keluar" wire:model="jamKeluar" required />
                </div>
                <p class="text-xs text-slate-500">Lama inap dan total biaya kamar dihitung otomatis sesuai pengaturan jam minimal.</p>
            </div>
        @endif
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="open = false">Batal</x-ui.button>
            <x-ui.button wire:click="simpanPulang">Simpan</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
