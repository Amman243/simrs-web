{{--
    Notifikasi. Dari komponen Livewire:
        $this->dispatch('toast', message: 'Data tersimpan', type: 'success'); // success | error | warning | info
    Setelah redirect:
        session()->flash('toast', ['message' => '...', 'type' => 'success']);
--}}
<div
    x-data="{
        items: [],
        push(detail) {
            let id = Date.now() + Math.random()
            this.items.push({ id, type: detail.type ?? 'success', message: detail.message })
            setTimeout(() => this.items = this.items.filter(i => i.id !== id), 4000)
        },
    }"
    @if (session('toast')) x-init="push(@js(session('toast')))" @endif
    x-on:toast.window="push($event.detail)"
    class="pointer-events-none fixed right-4 top-20 z-[60] flex w-80 flex-col gap-2"
>
    <template x-for="item in items" :key="item.id">
        <div
            x-transition
            class="pointer-events-auto rounded-lg border px-4 py-3 text-sm shadow-lg"
            :class="{
                'border-brand-200 bg-brand-50 text-brand-800': item.type === 'success',
                'border-red-200 bg-red-50 text-red-800': item.type === 'error',
                'border-accent-300 bg-accent-50 text-accent-700': item.type === 'warning',
                'border-sky-200 bg-sky-50 text-sky-800': item.type === 'info',
            }"
            x-text="item.message"
        ></div>
    </template>
</div>
