{{--
    Contoh:
    <x-ui.select label="Dokter" wire:model="kd_dokter" :options="$dokter" placeholder="Pilih dokter" />
    :options = array [value => label] atau Collection hasil pluck('nm_dokter', 'kd_dokter').
    Bisa juga isi <option> manual lewat slot.
--}}
@props(['label' => null, 'hint' => null, 'required' => false, 'options' => [], 'placeholder' => null])

@php
    $model = $attributes->wire('model')->value();
    $id = $attributes->get('id', $model ? 'f-'.str_replace('.', '-', $model) : null);
    $invalid = $model && $errors->has($model);
@endphp

<x-ui.field :label="$label" :for="$id" :error="$model" :hint="$hint" :required="$required" :class="$attributes->get('class')">
    <select
        id="{{ $id }}"
        {{ $attributes->except(['class', 'id']) }}
        @class([
            'block w-full rounded-lg border bg-white px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 disabled:bg-slate-100',
            'border-slate-300 focus:border-brand-500 focus:ring-brand-500/30' => ! $invalid,
            'border-red-400 focus:border-red-500 focus:ring-red-500/30' => $invalid,
        ])
    >
        @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}">{{ $text }}</option>
        @endforeach
        {{ $slot }}
    </select>
</x-ui.field>
