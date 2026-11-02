@props([
    'variant' => 'primary', // primary | secondary | accent | danger | ghost
    'size' => 'md',         // sm | md
    'type' => 'button',
    'href' => null,
])

@php
    $classes = \Illuminate\Support\Arr::toCssClasses([
        'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50',
        'px-3 py-1.5 text-xs' => $size === 'sm',
        'px-4 py-2 text-sm' => $size === 'md',
        'bg-brand-500 text-white hover:bg-brand-600 focus-visible:ring-brand-500' => $variant === 'primary',
        'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 focus-visible:ring-slate-400' => $variant === 'secondary',
        'bg-accent-400 text-brand-900 hover:bg-accent-500 focus-visible:ring-accent-500' => $variant === 'accent',
        'bg-red-600 text-white hover:bg-red-700 focus-visible:ring-red-500' => $variant === 'danger',
        'text-slate-600 hover:bg-slate-100 focus-visible:ring-slate-400' => $variant === 'ghost',
    ]);
    $target = $attributes->get('wire:target', $attributes->get('wire:click'));
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($target)
            <svg wire:loading wire:target="{{ $target }}" class="size-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
        @endif
        {{ $slot }}
    </button>
@endif
