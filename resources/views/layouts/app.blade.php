<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
        <div x-data="{ sidebar: false }" class="flex min-h-screen">
            {{-- Sidebar --}}
            <div x-show="sidebar" x-cloak x-on:click="sidebar = false" class="fixed inset-0 z-30 bg-black/40 lg:hidden"></div>

            <aside
                class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-brand-600 text-white transition-transform lg:static lg:translate-x-0"
                x-bind:class="sidebar && 'translate-x-0'"
            >
                <div class="flex h-16 items-center gap-3 border-b border-white/10 px-5">
                    <div class="flex size-9 items-center justify-center rounded-lg bg-accent-400 text-lg font-bold text-brand-800">+</div>
                    <div>
                        <div class="font-semibold leading-tight">{{ config('app.name') }}</div>
                        <div class="text-xs text-brand-100">Sistem Informasi Rumah Sakit</div>
                    </div>
                </div>

                <nav class="flex-1 space-y-1 overflow-y-auto p-3 text-sm">
                    @php
                        $menu = [
                            ['route' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'M3 12l9-9 9 9M5 10v10h14V10', 'can' => null],
                            ['route' => 'registrasi.index', 'label' => 'Registrasi', 'icon' => 'M12 6v12M6 12h12', 'can' => 'akses_registrasi'],
                            ['route' => 'rawat-jalan.index', 'label' => 'Rawat Jalan', 'icon' => 'M4 6h16M4 12h16M4 18h10', 'can' => 'tindakan_ralan'],
                            ['route' => 'rawat-inap.index', 'label' => 'Rawat Inap', 'icon' => 'M3 18v-6a3 3 0 013-3h12a3 3 0 013 3v6M3 14h18M7 9V6', 'can' => 'kamar_inap'],
                        ];
                    @endphp

                    @foreach ($menu as $item)
                        @if (Route::has($item['route']) && ($item['can'] === null || auth()->user()?->can($item['can'])))
                            @php $active = request()->routeIs(str_replace('.index', '', $item['route']).'*'); @endphp
                            <a
                                href="{{ route($item['route']) }}"
                                wire:navigate
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 font-medium transition',
                                    'bg-accent-400 text-brand-900' => $active,
                                    'text-brand-50 hover:bg-white/10' => ! $active,
                                ])
                            >
                                <svg class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" /></svg>
                                {{ $item['label'] }}
                            </a>
                        @endif
                    @endforeach
                </nav>

                <div class="border-t border-white/10 p-4 text-xs font-semibold text-white">
                    Rumah Sakit Al - Arif
                </div>
            </aside>

            {{-- Konten --}}
            <div class="flex min-w-0 flex-1 flex-col">
                <header class="sticky top-0 z-20 flex h-16 items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 lg:px-8">
                    <button type="button" class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" x-on:click="sidebar = true" aria-label="Buka menu">
                        <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
                    </button>

                    <div class="hidden text-sm text-slate-500 lg:block">{{ now()->translatedFormat('l, d F Y') }}</div>

                    @auth
                        <div x-data="{ open: false }" class="relative">
                            <button type="button" x-on:click="open = !open" class="flex items-center gap-3 rounded-lg px-2 py-1 hover:bg-slate-100">
                                <div class="text-right">
                                    <div class="text-sm font-semibold text-slate-800">{{ auth()->user()->nama }}</div>
                                    <div class="text-xs text-slate-500">{{ auth()->user()->jabatan ?? auth()->user()->kode }}</div>
                                </div>
                                <div class="flex size-9 items-center justify-center rounded-full bg-brand-500 font-semibold text-white">
                                    {{ mb_strtoupper(mb_substr(auth()->user()->nama, 0, 1)) }}
                                </div>
                            </button>
                            <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition class="absolute right-0 mt-2 w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50">Keluar</button>
                                </form>
                            </div>
                        </div>
                    @endauth
                </header>

                <main class="flex-1 p-4 lg:p-8">
                    {{ $slot }}
                </main>
            </div>
        </div>

        <x-ui.toast />

        @livewireScripts
    </body>
</html>
