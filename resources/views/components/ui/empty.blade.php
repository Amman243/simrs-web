@props(['colspan' => null, 'message' => 'Tidak ada data.'])

@if ($colspan)
    <tr><td colspan="{{ $colspan }}" class="py-10 text-center text-sm text-slate-500">{{ $message }}</td></tr>
@else
    <div {{ $attributes->merge(['class' => 'py-10 text-center text-sm text-slate-500']) }}>{{ $message }}</div>
@endif
