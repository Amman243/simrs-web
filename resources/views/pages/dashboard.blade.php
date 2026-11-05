<?php

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    #[Computed]
    public function ringkasan(): array
    {
        $hariIni = today()->toDateString();

        return [
            'igd' => DB::table('reg_periksa')->where('tgl_registrasi', $hariIni)->where('kd_poli', config('khanza.kd_poli_igd'))->count(),
            'ralan' => DB::table('reg_periksa')->where('tgl_registrasi', $hariIni)->where('status_lanjut', 'Ralan')->where('stts', '<>', 'Batal')->count(),
            'ranap' => DB::table('kamar_inap')->where('stts_pulang', '-')->count(),
            'kamar_kosong' => DB::table('kamar')->where('status', 'KOSONG')->where('statusdata', '1')->count(),
        ];
    }
};
?>

<div>
    <x-ui.page-header title="Selamat datang, {{ auth()->user()->nama }}" subtitle="Ringkasan pelayanan hari ini, {{ now()->translatedFormat('d F Y') }}." />

    @php
        $kartu = [
            ['label' => 'Registrasi IGD hari ini', 'value' => $this->ringkasan['igd'], 'route' => 'registrasi.index', 'params' => ['jenis' => 'igd'], 'tone' => 'bg-red-50 text-red-700'],
            ['label' => 'Kunjungan rawat jalan hari ini', 'value' => $this->ringkasan['ralan'], 'route' => 'rawat-jalan.index', 'tone' => 'bg-brand-50 text-brand-700'],
            ['label' => 'Pasien sedang dirawat inap', 'value' => $this->ringkasan['ranap'], 'route' => 'rawat-inap.index', 'tone' => 'bg-sky-50 text-sky-700'],
            ['label' => 'Kamar kosong', 'value' => $this->ringkasan['kamar_kosong'], 'route' => 'rawat-inap.index', 'tone' => 'bg-accent-50 text-accent-700'],
        ];
    @endphp

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($kartu as $item)
            <a href="{{ Route::has($item['route']) ? route($item['route'], $item['params'] ?? []) : '#' }}" wire:navigate class="group rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-brand-300 hover:shadow">
                <div class="text-sm text-slate-500">{{ $item['label'] }}</div>
                <div class="mt-3 flex items-end justify-between">
                    <span class="text-3xl font-semibold text-slate-800">{{ number_format($item['value']) }}</span>
                    <span class="rounded-lg px-2 py-1 text-xs font-medium {{ $item['tone'] }}">Lihat &rarr;</span>
                </div>
            </a>
        @endforeach
    </div>
</div>
