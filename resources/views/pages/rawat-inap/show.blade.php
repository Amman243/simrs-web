<?php

use App\Models\Dokter;
use App\Models\Petugas;
use App\Services\Khanza\Registrasi;
use App\Services\RawatInap\BiayaKamar;
use App\Services\RawatInap\KamarInapService;
use App\Services\RawatInap\PerawatanService;
use App\Services\RawatInap\RawatInapException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Perawatan Rawat Inap')] class extends Component
{
    #[Locked] public string $noRawat = '';

    #[Url] public string $tab = 'tindakan'; // tindakan | soap

    public string $jenis = 'dr'; // dr | pr | drpr

    // Form tindakan
    public string $tTgl = '';

    public string $tJam = '';

    public string $tDokter = '';

    public string $tNip = '';

    public string $cariTindakan = '';

    /** @var list<string> */
    public array $pilihan = [];

    // DPJP
    public string $dpjpBaru = '';

    // Form SOAP
    public array $soap = [];

    public string $soapTgl = '';

    public string $soapJam = '';

    public string $soapNip = '';

    #[Locked] public ?array $editSoap = null;

    public function mount(string $noRawat): void
    {
        abort_unless(DB::table('reg_periksa')->where('no_rawat', $noRawat)->exists(), 404);

        $this->noRawat = $noRawat;
        $this->tab = in_array($this->tab, ['tindakan', 'soap'], true) ? $this->tab : 'tindakan';
        $this->resetTindakan();
        $this->resetSoap();
    }

    // ------------------------------------------------------------------ Data

    #[Computed]
    public function reg(): object
    {
        return app(PerawatanService::class)->registrasi($this->noRawat);
    }

    #[Computed]
    public function terkunci(): bool
    {
        return Registrasi::terkunci($this->noRawat);
    }

    #[Computed]
    public function kamarAktif(): ?object
    {
        return app(KamarInapService::class)->kamarAktif($this->noRawat);
    }

    #[Computed]
    public function riwayatKamar()
    {
        $service = app(KamarInapService::class);

        return DB::table('kamar_inap')
            ->join('kamar', 'kamar_inap.kd_kamar', '=', 'kamar.kd_kamar')
            ->join('bangsal', 'kamar.kd_bangsal', '=', 'bangsal.kd_bangsal')
            ->where('kamar_inap.no_rawat', $this->noRawat)
            ->orderBy('kamar_inap.tgl_masuk')->orderBy('kamar_inap.jam_masuk')
            ->get(['kamar_inap.*', 'kamar.kelas', 'bangsal.nm_bangsal'])
            ->map(function ($row) use ($service) {
                if ($row->stts_pulang === '-') {
                    ['lama' => $row->lama, 'ttl_biaya' => $row->ttl_biaya] = $service->berjalan($row);
                }

                return $row;
            });
    }

    #[Computed]
    public function dpjp()
    {
        return app(PerawatanService::class)->dpjp($this->noRawat);
    }

    #[Computed]
    public function dokter(): array
    {
        return Dokter::aktif()->where('kd_dokter', '<>', '-')->orderBy('nm_dokter')->pluck('nm_dokter', 'kd_dokter')->all();
    }

    #[Computed]
    public function petugas(): array
    {
        return Petugas::aktif()->where('nip', '<>', '-')->orderBy('nama')->pluck('nama', 'nip')->all();
    }

    #[Computed]
    public function pegawai(): array
    {
        return DB::table('pegawai')->where('stts_aktif', '<>', 'KELUAR')->where('nik', '<>', '-')
            ->orderBy('nama')->pluck('nama', 'nik')->all();
    }

    #[Computed]
    public function katalog()
    {
        return app(PerawatanService::class)->katalog($this->jenis, $this->noRawat, $this->cariTindakan, 50);
    }

    #[Computed]
    public function tindakan()
    {
        return app(PerawatanService::class)->tindakan($this->jenis, $this->noRawat);
    }

    #[Computed]
    public function daftarSoap()
    {
        return app(PerawatanService::class)->soap($this->noRawat);
    }

    private function user()
    {
        return auth()->user();
    }

    private function petugasTerkunci(): bool
    {
        return $this->user()->isPetugas() && ! $this->user()->isAdmin;
    }

    public function bolehUbah(string $tgl, string $jam, ?string $pemilik = null): bool
    {
        return ! $this->terkunci && app(PerawatanService::class)->bolehUbah($tgl, $jam, $this->user(), $pemilik);
    }

    // ------------------------------------------------------------------ DPJP

    public function tambahDpjp(): void
    {
        $this->authorize('dpjp_ranap');
        $this->validate(['dpjpBaru' => 'required|string'], [], ['dpjpBaru' => 'dokter']);

        try {
            app(PerawatanService::class)->tambahDpjp($this->noRawat, $this->dpjpBaru);
        } catch (RawatInapException $e) {
            $this->addError('dpjpBaru', $e->getMessage());

            return;
        }

        $this->dpjpBaru = '';
        unset($this->dpjp);
        $this->dispatch('toast', message: 'DPJP ditambahkan.');
    }

    public function hapusDpjp(string $kdDokter): void
    {
        $this->authorize('dpjp_ranap');

        try {
            app(PerawatanService::class)->hapusDpjp($this->noRawat, $kdDokter);
        } catch (RawatInapException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        unset($this->dpjp);
        $this->dispatch('toast', message: 'DPJP dihapus.');
    }

    // ------------------------------------------------------------------ Tindakan

    public function updatedJenis(): void
    {
        $this->jenis = array_key_exists($this->jenis, PerawatanService::JENIS) ? $this->jenis : 'dr';
        $this->pilihan = [];
        $this->resetErrorBag();
    }

    private function resetTindakan(): void
    {
        $this->pilihan = [];
        $this->cariTindakan = '';
        $this->tTgl = now()->toDateString();
        $this->tJam = now()->format('H:i:s');
        $this->tDokter = $this->tDokter ?: (string) app(PerawatanService::class)->dokterDefault($this->noRawat);
        $this->tNip = $this->petugasTerkunci() ? (string) $this->user()->nip : $this->tNip;
    }

    public function simpanTindakan(): void
    {
        $this->authorize('tindakan_ranap');

        if ($this->petugasTerkunci()) {
            $this->tNip = (string) $this->user()->nip;
        }

        $this->validate([
            'pilihan' => 'required|array|min:1',
            'pilihan.*' => 'string',
            'tTgl' => 'required|date_format:Y-m-d',
            'tJam' => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'tDokter' => [Rule::requiredIf($this->jenis !== 'pr'), 'nullable', 'string'],
            'tNip' => [Rule::requiredIf($this->jenis !== 'dr'), 'nullable', 'string'],
        ], ['pilihan.required' => 'Pilih minimal satu tindakan.'], [
            'tTgl' => 'tanggal', 'tJam' => 'jam', 'tDokter' => 'dokter', 'tNip' => 'petugas',
        ]);

        try {
            $jumlah = app(PerawatanService::class)->simpanTindakan(
                $this->jenis, $this->noRawat, $this->pilihan,
                $this->jenis !== 'pr' ? $this->tDokter : null,
                $this->jenis !== 'dr' ? $this->tNip : null,
                KamarInapService::parseWaktu($this->tTgl, $this->tJam),
            );
        } catch (RawatInapException $e) {
            $this->addError('pilihan', $e->getMessage());

            return;
        }

        $this->resetTindakan();
        unset($this->tindakan);
        $this->dispatch('toast', message: "{$jumlah} tindakan tersimpan.");
    }

    public function hapusTindakan(array $kunci): void
    {
        $this->authorize('tindakan_ranap');

        try {
            app(PerawatanService::class)->hapusTindakan($this->jenis, $this->noRawat, $kunci, $this->user());
        } catch (RawatInapException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        unset($this->tindakan);
        $this->dispatch('toast', message: 'Tindakan dihapus.');
    }

    // ------------------------------------------------------------------ SOAP

    private function resetSoap(): void
    {
        $this->soap = array_fill_keys(PerawatanService::KOLOM_SOAP, '');
        $this->soap['kesadaran'] = 'Compos Mentis';
        $this->soapTgl = now()->toDateString();
        $this->soapJam = now()->format('H:i:s');
        $this->soapNip = $this->user()->isAdmin ? $this->soapNip : $this->user()->kode;
        $this->editSoap = null;
        $this->resetErrorBag();
    }

    public function batalSoap(): void
    {
        $this->resetSoap();
    }

    private function aturanSoap(): array
    {
        return [
            'soap.suhu_tubuh' => 'nullable|string|max:5',
            'soap.tensi' => 'nullable|string|max:8',
            'soap.nadi' => 'nullable|string|max:3',
            'soap.respirasi' => 'nullable|string|max:3',
            'soap.tinggi' => 'nullable|string|max:5',
            'soap.berat' => 'nullable|string|max:5',
            'soap.spo2' => 'nullable|string|max:3',
            'soap.gcs' => 'nullable|string|max:10',
            'soap.kesadaran' => ['required', Rule::in(PerawatanService::KESADARAN)],
            'soap.keluhan' => 'nullable|string|max:2000',
            'soap.pemeriksaan' => 'nullable|string|max:2000',
            'soap.alergi' => 'nullable|string|max:80',
            'soap.penilaian' => 'nullable|string|max:2000',
            'soap.rtl' => 'nullable|string|max:2000',
            'soap.instruksi' => 'nullable|string|max:2000',
            'soap.evaluasi' => 'nullable|string|max:2000',
        ];
    }

    public function simpanSoap(): void
    {
        $this->authorize('tindakan_ranap');

        $atribut = [
            'soap.suhu_tubuh' => 'suhu', 'soap.tensi' => 'tensi', 'soap.nadi' => 'nadi', 'soap.respirasi' => 'respirasi',
            'soap.tinggi' => 'tinggi', 'soap.berat' => 'berat', 'soap.spo2' => 'SpO2', 'soap.gcs' => 'GCS',
            'soap.kesadaran' => 'kesadaran', 'soap.alergi' => 'alergi', 'soapTgl' => 'tanggal', 'soapJam' => 'jam',
            'soapNip' => 'pemeriksa',
        ];

        try {
            if ($this->editSoap) {
                $this->validate($this->aturanSoap(), [], $atribut);
                app(PerawatanService::class)->ubahSoap(
                    $this->noRawat, $this->editSoap['tgl'], $this->editSoap['jam'], $this->soap, $this->user(),
                );
                $pesan = 'Pemeriksaan diperbarui.';
            } else {
                if (! $this->user()->isAdmin) {
                    $this->soapNip = $this->user()->kode;
                }
                $this->validate($this->aturanSoap() + [
                    'soapTgl' => 'required|date_format:Y-m-d',
                    'soapJam' => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
                    'soapNip' => 'required|string',
                ], [], $atribut);
                app(PerawatanService::class)->simpanSoap(
                    $this->noRawat, $this->soap, $this->soapNip, KamarInapService::parseWaktu($this->soapTgl, $this->soapJam),
                );
                $pesan = 'Pemeriksaan tersimpan.';
            }
        } catch (RawatInapException $e) {
            $this->addError('soap', $e->getMessage());
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->resetSoap();
        unset($this->daftarSoap);
        $this->dispatch('toast', message: $pesan);
    }

    public function ubahSoap(string $tgl, string $jam): void
    {
        $this->authorize('tindakan_ranap');

        $row = DB::table('pemeriksaan_ranap')
            ->where(['no_rawat' => $this->noRawat, 'tgl_perawatan' => $tgl, 'jam_rawat' => $jam])
            ->first();

        if (! $row || ! $this->bolehUbah($row->tgl_perawatan, $row->jam_rawat, $row->nip)) {
            $this->dispatch('toast', message: 'Data pemeriksaan tidak dapat diubah.', type: 'error');

            return;
        }

        foreach (PerawatanService::KOLOM_SOAP as $kolom) {
            $this->soap[$kolom] = (string) ($row->{$kolom} ?? '');
        }
        $this->soapTgl = $row->tgl_perawatan;
        $this->soapJam = $row->jam_rawat;
        $this->soapNip = $row->nip;
        $this->editSoap = ['tgl' => $row->tgl_perawatan, 'jam' => $row->jam_rawat];
        $this->resetErrorBag();
    }

    public function hapusSoap(string $tgl, string $jam): void
    {
        $this->authorize('tindakan_ranap');

        try {
            app(PerawatanService::class)->hapusSoap($this->noRawat, $tgl, $jam, $this->user());
        } catch (RawatInapException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        if ($this->editSoap && $this->editSoap['tgl'] === $tgl && $this->editSoap['jam'] === $jam) {
            $this->resetSoap();
        }
        unset($this->daftarSoap);
        $this->dispatch('toast', message: 'Pemeriksaan dihapus.');
    }
};
?>


<div>
    @php
        $reg = $this->reg;
        $aktif = $this->kamarAktif;
        $riwayat = $this->riwayatKamar;
        $masukPertama = $riwayat->first();
        $totalLama = (int) $riwayat->sum('lama');
        $bisaTindakan = ! $this->terkunci && auth()->user()->can('tindakan_ranap');
        $bisaDpjp = ! $this->terkunci && auth()->user()->can('dpjp_ranap');
        $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
    @endphp

    <x-ui.page-header title="Perawatan Rawat Inap" subtitle="{{ $reg->nm_pasien }} · {{ $noRawat }}">
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('rawat-inap.index') }}" wire:navigate>&larr; Daftar rawat inap</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($this->terkunci)
        <div class="mb-6 flex items-start gap-3 rounded-xl border border-accent-300 bg-accent-50 p-4 text-sm text-accent-700">
            <svg class="mt-0.5 size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
            <div><span class="font-semibold">Hanya baca.</span> Data billing sudah terverifikasi atau registrasi dibatalkan, sehingga data perawatan tidak dapat diubah.</div>
        </div>
    @endif

    @if (! $masukPertama)
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800">
            <span>Registrasi ini belum masuk kamar inap.</span>
            <x-ui.button size="sm" href="{{ route('rawat-inap.masuk', ['no_rawat' => $noRawat]) }}" wire:navigate>Masukkan ke kamar</x-ui.button>
        </div>
    @endif

    {{-- Header pasien --}}
    <div class="mb-6 grid gap-6 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="flex size-12 items-center justify-center rounded-full bg-brand-100 text-lg font-semibold text-brand-700">{{ mb_substr($reg->nm_pasien, 0, 1) }}</div>
                    <div>
                        <div class="text-lg font-semibold text-slate-800">{{ $reg->nm_pasien }}</div>
                        <div class="text-sm text-slate-500">RM {{ $reg->no_rkm_medis }} &middot; {{ $reg->jk }} &middot; {{ $reg->umurdaftar }} {{ $reg->sttsumur }} &middot; {{ $reg->png_jawab ?? '-' }}</div>
                    </div>
                </div>
                <div>
                    @if ($aktif)
                        <x-ui.status value="-" />
                    @elseif ($riwayat->isNotEmpty())
                        <x-ui.status :value="$riwayat->last()->stts_pulang" />
                    @endif
                </div>
            </div>

            <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Kamar</dt>
                    <dd class="font-medium text-slate-800">{{ $aktif->kd_kamar ?? ($riwayat->last()->kd_kamar ?? '-') }}</dd>
                    <dd class="text-xs text-slate-500">{{ $aktif->nm_bangsal ?? ($riwayat->last()->nm_bangsal ?? '') }} {{ ($aktif->kelas ?? $riwayat->last()->kelas ?? null) ? '· '.($aktif->kelas ?? $riwayat->last()->kelas) : '' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Tgl. masuk</dt>
                    <dd class="font-medium text-slate-800">{{ $masukPertama ? \Carbon\Carbon::parse($masukPertama->tgl_masuk)->format('d-m-Y') : '-' }}</dd>
                    <dd class="text-xs text-slate-500">{{ $masukPertama->jam_masuk ?? '' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Lama dirawat</dt>
                    <dd class="font-medium text-slate-800">{{ $totalLama }} hari</dd>
                    <dd class="text-xs text-slate-500">Biaya kamar Rp {{ $rp($riwayat->sum('ttl_biaya')) }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Diagnosa awal</dt>
                    <dd class="font-medium text-slate-800">{{ $masukPertama->diagnosa_awal ?? '-' }}</dd>
                </div>
            </dl>

            @if ($riwayat->count() > 1)
                <div class="mt-5 border-t border-slate-100 pt-4">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Riwayat kamar</div>
                    <ol class="space-y-1 text-xs text-slate-600">
                        @foreach ($riwayat as $seg)
                            <li class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-slate-700">{{ $seg->kd_kamar }}</span>
                                <span>{{ $seg->nm_bangsal }}</span>
                                <span class="text-slate-400">{{ $seg->tgl_masuk }} {{ $seg->jam_masuk }} &rarr; {{ $seg->tgl_keluar !== '0000-00-00' ? $seg->tgl_keluar.' '.$seg->jam_keluar : 'sekarang' }}</span>
                                <span>{{ (int) $seg->lama }} hr &middot; Rp {{ $rp($seg->ttl_biaya) }}</span>
                                <x-ui.status :value="$seg->stts_pulang" />
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif
        </x-ui.card>

        {{-- DPJP --}}
        <x-ui.card title="DPJP" subtitle="Dokter penanggung jawab pelayanan">
            <ul class="mb-4 space-y-2">
                @forelse ($this->dpjp as $d)
                    <li wire:key="dpjp-{{ $d->kd_dokter }}" class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm">
                        <span class="font-medium text-slate-700">{{ $d->nm_dokter }}</span>
                        @if ($bisaDpjp)
                            <button type="button" wire:click="hapusDpjp('{{ $d->kd_dokter }}')" wire:confirm="Hapus {{ $d->nm_dokter }} dari DPJP?" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Hapus DPJP">
                                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" /></svg>
                            </button>
                        @endif
                    </li>
                @empty
                    <li class="text-sm text-slate-500">Belum ada DPJP.</li>
                @endforelse
            </ul>
            @if ($bisaDpjp)
                <div class="flex items-end gap-2">
                    <x-ui.select wire:model="dpjpBaru" :options="$this->dokter" placeholder="Pilih dokter" class="flex-1" />
                    <x-ui.button wire:click="tambahDpjp">Tambah</x-ui.button>
                </div>
            @endif
        </x-ui.card>
    </div>

    {{-- Tab --}}
    <div class="mb-4 flex gap-1 border-b border-slate-200">
        @foreach (['tindakan' => 'Tindakan', 'soap' => 'Pemeriksaan (SOAP)'] as $val => $label)
            <button type="button" wire:click="$set('tab', '{{ $val }}')" @class([
                '-mb-px border-b-2 px-4 py-2 text-sm font-medium transition',
                'border-brand-500 text-brand-700' => $tab === $val,
                'border-transparent text-slate-500 hover:text-slate-700' => $tab !== $val,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'tindakan')
        <div class="mb-4 inline-flex rounded-lg border border-slate-200 bg-white p-1 text-sm">
            @foreach (\App\Services\RawatInap\PerawatanService::JENIS as $kode => $info)
                <button type="button" wire:click="$set('jenis', '{{ $kode }}')" @class([
                    'rounded-md px-3 py-1.5 font-medium transition',
                    'bg-accent-400 text-brand-900 shadow-sm' => $jenis === $kode,
                    'text-slate-600 hover:text-slate-800' => $jenis !== $kode,
                ])>{{ $info['label'] }}</button>
            @endforeach
        </div>

        <div class="grid gap-6 xl:grid-cols-5">
            @if ($bisaTindakan)
                <x-ui.card title="Tambah tindakan" class="xl:col-span-2">
                    <div class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.input type="date" label="Tanggal" wire:model="tTgl" required />
                            <x-ui.input type="time" step="1" label="Jam" wire:model="tJam" required />
                        </div>
                        @if ($jenis !== 'pr')
                            <x-ui.select label="Dokter" wire:model="tDokter" :options="$this->dokter" placeholder="Pilih dokter" required />
                        @endif
                        @if ($jenis !== 'dr')
                            <x-ui.select label="Petugas" wire:model="tNip" :options="$this->petugas" placeholder="Pilih petugas" :disabled="auth()->user()->isPetugas() && ! auth()->user()->isAdmin" required />
                        @endif

                        <x-ui.input label="Cari tindakan" wire:model.live.debounce.400ms="cariTindakan" placeholder="Kode, nama, atau kategori..." />

                        <div class="max-h-80 overflow-y-auto rounded-lg border border-slate-200">
                            @forelse ($this->katalog as $item)
                                <label wire:key="kat-{{ $jenis }}-{{ $item->kd_jenis_prw }}" class="flex cursor-pointer items-start gap-3 border-b border-slate-100 px-3 py-2 text-sm last:border-0 hover:bg-brand-50/50">
                                    <input type="checkbox" value="{{ $item->kd_jenis_prw }}" wire:model="pilihan" class="mt-0.5 size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500" />
                                    <span class="flex-1">
                                        <span class="block font-medium text-slate-700">{{ $item->nm_perawatan }}</span>
                                        <span class="block text-xs text-slate-500">{{ $item->kd_jenis_prw }} &middot; {{ $item->nm_kategori }}@if ($item->kelas !== '-') &middot; {{ $item->kelas }}@endif</span>
                                    </span>
                                    <span class="whitespace-nowrap text-xs font-semibold tabular-nums text-slate-700">Rp {{ $rp($item->tarif) }}</span>
                                </label>
                            @empty
                                <x-ui.empty message="Tidak ada tindakan yang cocok." />
                            @endforelse
                        </div>
                        @error('pilihan')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs text-slate-500">{{ count($pilihan) }} dipilih &middot; maks. 50 hasil ditampilkan</span>
                            <x-ui.button wire:click="simpanTindakan">Simpan tindakan</x-ui.button>
                        </div>
                    </div>
                </x-ui.card>
            @endif

            <x-ui.card title="Tindakan {{ \App\Services\RawatInap\PerawatanService::JENIS[$jenis]['label'] }}" subtitle="Total Rp {{ $rp($this->tindakan->sum('biaya_rawat')) }}" :padding="false" :class="$bisaTindakan ? 'xl:col-span-3' : 'xl:col-span-5'">
                <x-ui.table>
                    <x-slot:head>
                        <th>Waktu</th>
                        <th>Tindakan</th>
                        @if ($jenis !== 'pr')<th>Dokter</th>@endif
                        @if ($jenis !== 'dr')<th>Petugas</th>@endif
                        <th class="text-right">Biaya</th>
                        <th></th>
                    </x-slot:head>
                    @forelse ($this->tindakan as $t)
                        @php
                            $kunci = ['kd_jenis_prw' => $t->kd_jenis_prw, 'tgl_perawatan' => $t->tgl_perawatan, 'jam_rawat' => $t->jam_rawat];
                            if ($jenis !== 'pr') $kunci['kd_dokter'] = $t->kd_dokter;
                            if ($jenis !== 'dr') $kunci['nip'] = $t->nip;
                        @endphp
                        <tr wire:key="t-{{ $jenis }}-{{ md5(json_encode($kunci)) }}">
                            <td class="whitespace-nowrap text-xs">{{ \Carbon\Carbon::parse($t->tgl_perawatan)->format('d-m-Y') }}<div class="text-slate-500">{{ $t->jam_rawat }}</div></td>
                            <td>
                                <div class="font-medium text-slate-700">{{ $t->nm_perawatan ?? $t->kd_jenis_prw }}</div>
                                <div class="text-xs text-slate-500">{{ $t->kd_jenis_prw }}</div>
                            </td>
                            @if ($jenis !== 'pr')<td class="text-xs">{{ $t->nm_dokter ?? $t->kd_dokter }}</td>@endif
                            @if ($jenis !== 'dr')<td class="text-xs">{{ $t->nm_petugas ?? $t->nip }}</td>@endif
                            <td class="whitespace-nowrap text-right tabular-nums">{{ $rp($t->biaya_rawat) }}</td>
                            <td class="text-right">
                                @if ($bisaTindakan && $this->bolehUbah($t->tgl_perawatan, $t->jam_rawat))
                                    <x-ui.button size="sm" variant="ghost" class="text-red-600 hover:bg-red-50" wire:click="hapusTindakan({{ \Illuminate\Support\Js::from($kunci) }})" wire:confirm="Hapus tindakan ini?">Hapus</x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty colspan="6" message="Belum ada tindakan." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>
    @else
        <div class="grid gap-6 xl:grid-cols-5">
            @if ($bisaTindakan)
                <x-ui.card :title="$editSoap ? 'Ubah pemeriksaan' : 'Pemeriksaan baru'" class="xl:col-span-2">
                    <form wire:submit="simpanSoap" class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.input type="date" label="Tanggal" wire:model="soapTgl" :disabled="(bool) $editSoap" required />
                            <x-ui.input type="time" step="1" label="Jam" wire:model="soapJam" :disabled="(bool) $editSoap" required />
                        </div>
                        @if (auth()->user()->isAdmin)
                            <x-ui.select label="Pemeriksa" wire:model="soapNip" :options="$this->pegawai" placeholder="Pilih pegawai" :disabled="(bool) $editSoap" required />
                        @endif

                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <x-ui.input label="Suhu (°C)" wire:model="soap.suhu_tubuh" maxlength="5" />
                            <x-ui.input label="Tensi" wire:model="soap.tensi" maxlength="8" placeholder="120/80" />
                            <x-ui.input label="Nadi" wire:model="soap.nadi" maxlength="3" />
                            <x-ui.input label="Respirasi" wire:model="soap.respirasi" maxlength="3" />
                            <x-ui.input label="Tinggi (cm)" wire:model="soap.tinggi" maxlength="5" />
                            <x-ui.input label="Berat (kg)" wire:model="soap.berat" maxlength="5" />
                            <x-ui.input label="SpO2" wire:model="soap.spo2" maxlength="3" />
                            <x-ui.input label="GCS" wire:model="soap.gcs" maxlength="10" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.select label="Kesadaran" wire:model="soap.kesadaran" :options="array_combine(\App\Services\RawatInap\PerawatanService::KESADARAN, \App\Services\RawatInap\PerawatanService::KESADARAN)" required />
                            <x-ui.input label="Alergi" wire:model="soap.alergi" maxlength="80" />
                        </div>
                        <x-ui.textarea label="Subjek (keluhan)" wire:model="soap.keluhan" rows="2" />
                        <x-ui.textarea label="Objek (pemeriksaan)" wire:model="soap.pemeriksaan" rows="2" />
                        <x-ui.textarea label="Asesmen (penilaian)" wire:model="soap.penilaian" rows="2" />
                        <x-ui.textarea label="Plan (rencana tindak lanjut)" wire:model="soap.rtl" rows="2" />
                        <x-ui.textarea label="Instruksi" wire:model="soap.instruksi" rows="2" />
                        <x-ui.textarea label="Evaluasi" wire:model="soap.evaluasi" rows="2" />
                        @error('soap')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

                        <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                            @if ($editSoap)
                                <x-ui.button variant="secondary" wire:click="batalSoap">Batal ubah</x-ui.button>
                            @endif
                            <x-ui.button type="submit" wire:target="simpanSoap">{{ $editSoap ? 'Simpan perubahan' : 'Simpan' }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif

            <div @class(['space-y-4', 'xl:col-span-3' => $bisaTindakan, 'xl:col-span-5' => ! $bisaTindakan])>
                @forelse ($this->daftarSoap as $s)
                    <x-ui.card wire:key="soap-{{ $s->tgl_perawatan }}-{{ $s->jam_rawat }}" :class="$editSoap && $editSoap['tgl'] === $s->tgl_perawatan && $editSoap['jam'] === $s->jam_rawat ? 'ring-2 ring-accent-400' : ''">
                        <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <div class="font-semibold text-slate-800">{{ \Carbon\Carbon::parse($s->tgl_perawatan)->format('d-m-Y') }} {{ $s->jam_rawat }}</div>
                                <div class="text-xs text-slate-500">{{ $s->nm_pegawai ?? $s->nip }}</div>
                            </div>
                            @if ($bisaTindakan && $this->bolehUbah($s->tgl_perawatan, $s->jam_rawat, $s->nip))
                                <div class="flex gap-1">
                                    <x-ui.button size="sm" variant="secondary" wire:click="ubahSoap('{{ $s->tgl_perawatan }}', '{{ $s->jam_rawat }}')">Ubah</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" class="text-red-600 hover:bg-red-50" wire:click="hapusSoap('{{ $s->tgl_perawatan }}', '{{ $s->jam_rawat }}')" wire:confirm="Hapus pemeriksaan ini?">Hapus</x-ui.button>
                                </div>
                            @endif
                        </div>
                        <div class="mb-3 flex flex-wrap gap-2 text-xs">
                            @foreach (['Suhu' => $s->suhu_tubuh, 'Tensi' => $s->tensi, 'Nadi' => $s->nadi, 'RR' => $s->respirasi, 'TB' => $s->tinggi, 'BB' => $s->berat, 'SpO2' => $s->spo2, 'GCS' => $s->gcs] as $label => $nilai)
                                @if (filled($nilai))
                                    <span class="rounded-md bg-slate-100 px-2 py-1 text-slate-600">{{ $label }} <span class="font-semibold text-slate-800">{{ $nilai }}</span></span>
                                @endif
                            @endforeach
                            <x-ui.badge color="blue">{{ $s->kesadaran }}</x-ui.badge>
                            @if (filled($s->alergi))<x-ui.badge color="red">Alergi: {{ $s->alergi }}</x-ui.badge>@endif
                        </div>
                        <dl class="grid gap-3 text-sm sm:grid-cols-2">
                            @foreach (['S' => $s->keluhan, 'O' => $s->pemeriksaan, 'A' => $s->penilaian, 'P' => $s->rtl, 'Instruksi' => $s->instruksi, 'Evaluasi' => $s->evaluasi] as $label => $nilai)
                                @if (filled($nilai))
                                    <div>
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-brand-600">{{ $label }}</dt>
                                        <dd class="whitespace-pre-line text-slate-700">{{ $nilai }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>
                    </x-ui.card>
                @empty
                    <x-ui.card><x-ui.empty message="Belum ada pemeriksaan." /></x-ui.card>
                @endforelse
            </div>
        </div>
    @endif
</div>
