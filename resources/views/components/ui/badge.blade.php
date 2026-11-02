@props(['color' => 'gray']) {{-- gray | green | yellow | red | blue | orange --}}

<span {{ $attributes->merge(['class' => \Illuminate\Support\Arr::toCssClasses([
    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap',
    'bg-slate-100 text-slate-700' => $color === 'gray',
    'bg-brand-100 text-brand-700' => $color === 'green',
    'bg-accent-100 text-accent-700' => $color === 'yellow',
    'bg-red-100 text-red-700' => $color === 'red',
    'bg-sky-100 text-sky-700' => $color === 'blue',
    'bg-orange-100 text-orange-700' => $color === 'orange',
])]) }}>{{ $slot }}</span>
