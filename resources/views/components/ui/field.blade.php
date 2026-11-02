{{-- Pembungkus label + pesan error. Dipakai oleh input/select/textarea. --}}
@props(['label' => null, 'for' => null, 'error' => null, 'hint' => null, 'required' => false])

<div {{ $attributes->merge(['class' => 'space-y-1']) }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif class="block text-sm font-medium text-slate-700">
            {{ $label }} @if ($required)<span class="text-red-500">*</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if ($error)
        @error($error)<p class="text-xs text-red-600">{{ $message }}</p>@enderror
    @endif
    @if ($hint)<p class="text-xs text-slate-500">{{ $hint }}</p>@endif
</div>
