<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard SIMRS')] class extends Component
{
    public int $antrian = 0;

    public function tambahAntrian(): void
    {
        $this->antrian++;
    }

    public function resetAntrian(): void
    {
        $this->antrian = 0;
    }
};
?>

<div class="min-h-screen bg-slate-100 p-6">
    <div class="mx-auto max-w-3xl space-y-6">
        <header class="rounded-xl bg-emerald-600 p-6 text-white shadow">
            <h1 class="text-2xl font-semibold">SIMRS Web</h1>
            <p class="text-emerald-100">Laravel + Blade + Livewire + Alpine.js + Tailwind</p>
        </header>

        {{-- Livewire: state disimpan di server --}}
        <section class="rounded-xl bg-white p-6 shadow">
            <h2 class="mb-2 font-semibold text-slate-700">Livewire</h2>
            <p class="mb-4 text-slate-600">Nomor antrian: <span class="text-3xl font-bold text-emerald-600">{{ $antrian }}</span></p>
            <div class="flex gap-2">
                <button wire:click="tambahAntrian" class="rounded-lg bg-emerald-600 px-4 py-2 text-white hover:bg-emerald-700">Ambil Antrian</button>
                <button wire:click="resetAntrian" class="rounded-lg bg-slate-200 px-4 py-2 text-slate-700 hover:bg-slate-300">Reset</button>
            </div>
        </section>

        {{-- Alpine.js: state di browser (Alpine sudah dibundel oleh Livewire) --}}
        <section x-data="{ open: false }" class="rounded-xl bg-white p-6 shadow">
            <h2 class="mb-2 font-semibold text-slate-700">Alpine.js</h2>
            <button x-on:click="open = !open" class="rounded-lg bg-sky-600 px-4 py-2 text-white hover:bg-sky-700">
                <span x-text="open ? 'Sembunyikan' : 'Tampilkan'"></span> Info
            </button>
            <p x-show="open" x-transition class="mt-4 text-slate-600">Alpine.js berjalan tanpa request ke server.</p>
        </section>
    </div>
</div>
