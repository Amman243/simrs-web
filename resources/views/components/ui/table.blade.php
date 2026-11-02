{{--
    <x-ui.table>
        <x-slot:head><th>...</th></x-slot:head>
        <tr>...</tr>
    </x-ui.table>
--}}
<div {{ $attributes->merge(['class' => 'overflow-x-auto']) }}>
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 [&_th]:whitespace-nowrap [&_th]:px-4 [&_th]:py-3">
            <tr>{{ $head }}</tr>
        </thead>
        <tbody class="divide-y divide-slate-100 bg-white [&_td]:px-4 [&_td]:py-3 [&_tr:hover]:bg-brand-50/50">
            {{ $slot }}
        </tbody>
    </table>
</div>
