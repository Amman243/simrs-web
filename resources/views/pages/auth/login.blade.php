<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Masuk')] class extends Component
{
    #[Validate('required|string|max:30', as: 'ID user')]
    public string $id_user = '';

    #[Validate('required|string|max:30', as: 'password')]
    public string $password = '';

    public function login(): void
    {
        $this->validate();

        $key = Str::lower($this->id_user).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'id_user' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.',
            ]);
        }

        if (! Auth::attempt(['id_user' => $this->id_user, 'password' => $this->password])) {
            RateLimiter::hit($key);
            $this->reset('password');

            throw ValidationException::withMessages([
                'id_user' => 'ID user atau password salah.',
            ]);
        }

        RateLimiter::clear($key);
        session()->regenerate();

        $this->redirectIntended(route('dashboard'), navigate: false);
    }
};
?>

<div class="flex min-h-screen items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="mb-6 text-center text-white">
            <div class="mx-auto mb-3 flex size-14 items-center justify-center rounded-2xl bg-accent-400 text-3xl font-bold text-brand-800 shadow-lg">+</div>
            <h1 class="text-2xl font-semibold">{{ config('app.name') }}</h1>
            <p class="text-sm text-brand-100">Sistem Informasi Manajemen Rumah Sakit</p>
        </div>

        <div class="rounded-2xl bg-white p-8 shadow-xl">
            <h2 class="mb-1 text-lg font-semibold text-slate-800">Masuk</h2>
            <p class="mb-6 text-sm text-slate-500">Gunakan ID user dan password SIMRS Khanza.</p>

            <form wire:submit="login" class="space-y-4">
                <x-ui.input label="ID User" wire:model="id_user" autocomplete="username" autofocus required />
                <x-ui.input label="Password" type="password" wire:model="password" autocomplete="current-password" required />

                <x-ui.button type="submit" wire:target="login" class="w-full">Masuk</x-ui.button>
            </form>
        </div>

        <p class="mt-6 text-center text-xs text-brand-100">&copy; {{ date('Y') }} {{ config('app.name') }}</p>
    </div>
</div>
