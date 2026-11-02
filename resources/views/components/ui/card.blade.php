@props(['title' => null, 'subtitle' => null, 'padding' => true])

<section {{ $attributes->merge(['class' => 'rounded-xl border border-slate-200 bg-white shadow-sm']) }}>
    @if ($title || isset($actions))
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
            <div>
                @if ($title)<h2 class="font-semibold text-slate-800">{{ $title }}</h2>@endif
                @if ($subtitle)<p class="text-sm text-slate-500">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
        </header>
    @endif

    <div @class(['p-5' => $padding])>
        {{ $slot }}
    </div>
</section>
