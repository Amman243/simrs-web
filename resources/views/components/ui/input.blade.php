{{-- Contoh: <x-ui.input label="Nama" wire:model="nama" required /> --}}
@props(['label' => null, 'hint' => null, 'required' => false])

@php
    $model = $attributes->wire('model')->value();
    $id = $attributes->get('id', $model ? 'f-'.str_replace('.', '-', $model) : null);
    $invalid = $model && $errors->has($model);
@endphp

<x-ui.field :label="$label" :for="$id" :error="$model" :hint="$hint" :required="$required" :class="$attributes->get('class')">
    <input
        id="{{ $id }}"
        {{ $attributes->except(['class', 'id'])->merge(['type' => 'text']) }}
        @class([
            'block w-full rounded-lg border bg-white px-3 py-2 text-sm shadow-sm placeholder:text-slate-400 focus:outline-none focus:ring-2 disabled:bg-slate-100',
            'border-slate-300 focus:border-brand-500 focus:ring-brand-500/30' => ! $invalid,
            'border-red-400 focus:border-red-500 focus:ring-red-500/30' => $invalid,
        ])
    />
</x-ui.field>
