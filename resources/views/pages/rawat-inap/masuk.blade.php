<?php

use App\Models\Dokter;
use App\Services\RawatInap\KamarInapService;
use App\Services\RawatInap\RawatInapException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Masuk Kamar Inap')] class extends Component
{
    #[Url(as: 'no_rawat')] public string $noRawat = '';

    public string $cariReg = '';

    public string $kd_kamar = '';

    public string $diagnosa_awal = '';

    public string $tgl_masuk = '';

    public string $jam_masuk = '';

    public string $kd_dokter = '';

    public function mount(): void
    {
        $this->siapkanForm();
    }

    public function pilih(string $noRawat): void
    {
        $this->noRawat = $noRawat;
        $this->siapkanForm();
    }

    public function ganti(): void
    {
        $this->noRawat = '';
        $this->siapkanForm();
    }

    private function siapkanForm(): void
    {
        unset($this->registrasi, $this->alasan, $this->kamarKosong, $this->kamarTerbuka);
        $this->resetErrorBag();
        $this->kd_kamar = '';
        $this->diagnosa_awal = '';
        $this->tgl_masuk = now()->toDateString();
        $this->jam_masuk = now()->format('H:i:s');
        $this->kd_dokter = (string) ($this->registrasi->kd_dokter ?? '');
    }

    #[Computed]
    public function registrasi(): ?object
    {
        return $this->noRawat !== '' ? app(KamarInapService::class)->registrasi($this->noRawat) : null;
    }

    #[Computed]
    public function alasan(): ?string
    {
        return $this->noRawat !== '' ? app(KamarInapService::class)->alasanTolakMasuk($this->registrasi) : null;
    }

    #[Computed]
    public function kamarTerbuka(): ?object
    {
        return $this->noRawat !== '' ? app(KamarInapService::class)->kamarAktif($this->noRawat) : null;
    }

    #[Computed]
    public function kandidat()
    {
        return app(KamarInapService::class)->kandidatMasuk($this->cariReg);
    }

    #[Computed]
    public function kamarKosong()
    {
        return app(KamarInapService::class)->kamarKosong($this->registrasi->kd_pj ?? null);
    }

    #[Computed]
    public function dokter(): array
    {
        return Dokter::aktif()->where('kd_dokter', '<>', '-')->orderBy('nm_dokter')->pluck('nm_dokter', 'kd_dokter')->all();
    }

    public function simpan(): void
    {
        $this->authorize('kamar_inap');

        if ($this->registrasi === null) {
            $this->dispatch('toast', message: 'Pilih registrasi terlebih dahulu.', type: 'error');

            return;
        }

        $this->validate([
            'kd_kamar' => 'required|string',
            'diagnosa_awal' => 'required|string|max:100',
            'tgl_masuk' => 'required|date_format:Y-m-d',
            'jam_masuk' => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'kd_dokter' => 'required|string',
        ], [], [
            'kd_kamar' => 'kamar', 'diagnosa_awal' => 'diagnosa awal', 'tgl_masuk' => 'tanggal masuk',
            'jam_masuk' => 'jam masuk', 'kd_dokter' => 'DPJP',
        ]);

        try {
            app(KamarInapService::class)->masuk(
                $this->noRawat,
                $this->kd_kamar,
                trim($this->diagnosa_awal),
                KamarInapService::parseWaktu($this->tgl_masuk, $this->jam_masuk),
                $this->kd_dokter,
            );
        } catch (RawatInapException $e) {
            unset($this->alasan, $this->kamarKosong);
            $this->addError('kd_kamar', $e->getMessage());
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        session()->flash('toast', [
            'message' => "{$this->registrasi->nm_pasien} masuk kamar {$this->kd_kamar}.",
            'type' => 'success',
        ]);

        $this->redirectRoute('rawat-inap.index', navigate: true);
    }
};
?>

<div>
    <x-ui.page-header title="Masuk Kamar Inap" subtitle="Check-in pasien dari registrasi rawat jalan / IGD ke kamar rawat inap.">
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('rawat-inap.index') }}" wire:navigate>&larr; Daftar rawat inap</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($noRawat === '')
        {{-- Pilih registrasi --}}
        <x-ui.card title="Pilih registrasi" subtitle="Registrasi 3 hari terakhir yang belum masuk kamar. Ketik untuk mencari registrasi lain." :padding="false">
            <div class="border-b border-slate-100 p-4">
                <x-ui.input wire:model.live.debounce.400ms="cariReg" placeholder="Cari no. rawat, no. RM, atau nama pasien..." />
            </div>
            <x-ui.table>
                <x-slot:head>
                    <th>No. Rawat</th>
                    <th>Pasien</th>
                    <th>Tanggal</th>
                    <th>Poli / Dokter</th>
                    <th>Penjamin</th>
                    <th>Status</th>
                    <th></th>
                </x-slot:head>
                @forelse ($this->kandidat as $r)
                    <tr wire:key="reg-{{ $r->no_rawat }}">
                        <td class="whitespace-nowrap font-mono text-xs text-slate-600">{{ $r->no_rawat }}</td>
                        <td>
                            <div class="font-medium text-slate-800">{{ $r->nm_pasien }}</div>
                            <div class="text-xs text-slate-500">RM {{ $r->no_rkm_medis }}</div>
                        </td>
                        <td class="whitespace-nowrap text-xs">{{ \Carbon\Carbon::parse($r->tgl_registrasi)->format('d-m-Y') }} {{ $r->jam_reg }}</td>
                        <td class="text-xs">
                            <div>{{ $r->nm_poli ?? '-' }}</div>
                            <div class="text-slate-500">{{ $r->nm_dokter ?? '-' }}</div>
                        </td>
                        <td class="whitespace-nowrap text-xs">{{ $r->png_jawab ?? '-' }}</td>
                        <td><x-ui.status :value="$r->stts" /></td>
                        <td class="text-right"><x-ui.button size="sm" wire:click="pilih('{{ $r->no_rawat }}')">Pilih</x-ui.button></td>
                    </tr>
                @empty
                    <x-ui.empty colspan="7" message="Tidak ada registrasi yang dapat dimasukkan kamar." />
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @else
        @php $reg = $this->registrasi; @endphp

        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Info registrasi --}}
            <x-ui.card title="Registrasi" class="lg:col-span-1">
                <x-slot:actions>
                    <x-ui.button size="sm" variant="ghost" wire:click="ganti">Ganti</x-ui.button>
                </x-slot:actions>

                @if ($reg)
                    <div class="mb-4 flex items-center gap-3">
                        <div class="flex size-11 items-center justify-center rounded-full bg-brand-100 text-lg font-semibold text-brand-700">{{ mb_substr($reg->nm_pasien, 0, 1) }}</div>
                        <div>
                            <div class="font-semibold text-slate-800">{{ $reg->nm_pasien }}</div>
                            <div class="text-xs text-slate-500">RM {{ $reg->no_rkm_medis }} &middot; {{ $reg->jk }} &middot; {{ $reg->umurdaftar }} {{ $reg->sttsumur }}</div>
                        </div>
                    </div>
                    <dl class="space-y-2 text-sm">
                        @foreach ([
                            'No. Rawat' => $reg->no_rawat,
                            'Tgl. Registrasi' => \Carbon\Carbon::parse($reg->tgl_registrasi)->format('d-m-Y').' '.$reg->jam_reg,
                            'Poli' => $reg->nm_poli ?? '-',
                            'Dokter' => $reg->nm_dokter ?? '-',
                            'Penjamin' => $reg->png_jawab ?? '-',
                            'Penanggung jawab' => trim(($reg->p_jawab ?? '').' ('.($reg->hubunganpj ?? '-').')'),
                            'Alamat' => $reg->alamat ?? '-',
                        ] as $label => $nilai)
                            <div class="flex justify-between gap-4">
                                <dt class="text-slate-500">{{ $label }}</dt>
                                <dd class="text-right font-medium text-slate-700">{{ $nilai }}</dd>
                            </div>
                        @endforeach
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500">Status</dt>
                            <dd class="flex gap-1"><x-ui.status :value="$reg->stts" /> <x-ui.badge>{{ $reg->status_lanjut }}</x-ui.badge></dd>
                        </div>
                    </dl>
                @else
                    <p class="text-sm text-slate-500">No. rawat <span class="font-mono">{{ $noRawat }}</span> tidak ditemukan.</p>
                @endif
            </x-ui.card>

            {{-- Form --}}
            <x-ui.card title="Data masuk kamar" class="lg:col-span-2">
                @if ($this->alasan)
                    <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                        <div class="font-semibold">Tidak dapat masuk kamar</div>
                        <p class="mt-1">{{ $this->alasan }}</p>
                        @if ($this->kamarTerbuka)
                            <p class="mt-2">Pasien sedang dirawat di kamar <span class="font-semibold">{{ $this->kamarTerbuka->kd_kamar }}</span> ({{ $this->kamarTerbuka->nm_bangsal }}).</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <x-ui.button size="sm" variant="secondary" href="{{ route('rawat-inap.index') }}" wire:navigate>Buka daftar rawat inap</x-ui.button>
                                <x-ui.button size="sm" href="{{ route('rawat-inap.show', ['noRawat' => $noRawat]) }}" wire:navigate>Perawatan</x-ui.button>
                            </div>
                        @endif
                    </div>
                @else
                    <form wire:submit="simpan" class="space-y-4">
                        <x-ui.select label="Kamar" wire:model="kd_kamar" placeholder="Pilih kamar kosong" required>
                            @foreach ($this->kamarKosong as $nmBangsal => $daftar)
                                <optgroup label="{{ $nmBangsal }}">
                                    @foreach ($daftar as $k)
                                        <option value="{{ $k->kd_kamar }}">{{ $k->kd_kamar }} — {{ $k->kelas }} — Rp {{ number_format($k->tarif, 0, ',', '.') }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </x-ui.select>
                        @if ($this->kamarKosong->isEmpty())
                            <p class="text-sm text-accent-700">Tidak ada kamar berstatus KOSONG saat ini.</p>
                        @endif

                        <x-ui.input label="Diagnosa awal" wire:model="diagnosa_awal" maxlength="100" required />

                        <div class="grid gap-4 sm:grid-cols-3">
                            <x-ui.input type="date" label="Tanggal masuk" wire:model="tgl_masuk" required />
                            <x-ui.input type="time" step="1" label="Jam masuk" wire:model="jam_masuk" required />
                            <x-ui.select label="DPJP" wire:model="kd_dokter" :options="$this->dokter" placeholder="Pilih dokter" required />
                        </div>

                        <p class="text-xs text-slate-500">Tarif kamar mengikuti tarif khusus penjamin bila ada, selain itu tarif kamar standar. Status registrasi akan diubah menjadi Ranap / Dirawat.</p>

                        <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                            <x-ui.button variant="secondary" href="{{ route('rawat-inap.index') }}" wire:navigate>Batal</x-ui.button>
                            <x-ui.button type="submit" wire:target="simpan">Simpan</x-ui.button>
                        </div>
                    </form>
                @endif
            </x-ui.card>
        </div>
    @endif
</div>
