{{--
    Modal yang dibuka/tutup lewat properti boolean Livewire:
    <x-ui.modal wire:model="showForm" title="Pindah Kamar" maxWidth="2xl"> ... <x-slot:footer>...</x-slot:footer> </x-ui.modal>

    Di dalam modal, `open = false` menutup modal (mis. tombol Batal di footer).
    Tampil/sembunyi memakai wire:show yang membaca properti $wire secara langsung, sehingga
    perubahan dari server ($this->showForm = true) selalu tercermin.
--}}
@props(['title' => null, 'maxWidth' => 'lg'])

@php
    $model = $attributes->wire('model')->value();
    $width = ['sm' => 'max-w-sm', 'md' => 'max-w-md', 'lg' => 'max-w-lg', 'xl' => 'max-w-xl', '2xl' => 'max-w-2xl', '4xl' => 'max-w-4xl'][$maxWidth] ?? 'max-w-lg';
@endphp

<div
    wire:show="{{ $model }}"
    x-data="{
        get open() { return $wire.{{ $model }} },
        set open(value) { $wire.{{ $model }} = value },
    }"
    x-cloak
    x-on:keydown.escape.window="if (open) open = false"
    x-on:click.self="open = false"
    class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:items-center"
    role="dialog"
    aria-modal="true"
>
    <div class="w-full {{ $width }} rounded-xl bg-white shadow-xl">
        @if ($title)
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <h3 class="font-semibold text-slate-800">{{ $title }}</h3>
                <button type="button" x-on:click="open = false" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" /></svg>
                </button>
            </div>
        @endif
        <div class="p-5">{{ $slot }}</div>
        @isset($footer)
            <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4">{{ $footer }}</div>
        @endisset
    </div>
</div>
