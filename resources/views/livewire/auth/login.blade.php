<?php

use App\Support\PostLoginRedirect;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth.sru')] class extends Component {
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        Session::regenerate();

        $this->redirectIntended(
            default: PostLoginRedirect::routeFor(Auth::user()),
            navigate: true,
        );
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the authentication rate limiting throttle key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}; ?>

<div class="flex flex-col gap-8">
    <div class="space-y-2">
        <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">
            เข้าสู่ระบบ
        </h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            เข้าสู่ระบบด้วยบัญชีมหาวิทยาลัย หรือใช้อีเมลและรหัสผ่านที่ได้รับจากผู้ดูแลระบบ
        </p>
    </div>

    <x-auth-session-status class="text-center" :status="session('status')" />

    @if (config('services.uapp.client_id'))
        <a
            href="{{ route('auth.uapp.redirect') }}"
            class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-800 shadow-sm transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100 dark:hover:bg-zinc-800"
        >
            <flux:icon.academic-cap class="size-5 text-blue-600 dark:text-blue-400" />
            เข้าสู่ระบบด้วยบัญชีมหาวิทยาลัย
        </a>

        <div class="relative">
            <div class="absolute inset-0 flex items-center">
                <span class="w-full border-t border-zinc-200 dark:border-zinc-700"></span>
            </div>
            <div class="relative flex justify-center text-xs uppercase">
                <span class="bg-zinc-50 px-2 text-zinc-500 dark:bg-zinc-950 dark:text-zinc-400">หรือ</span>
            </div>
        </div>
    @endif

    <form wire:submit="login" class="flex flex-col gap-5">
        <flux:input
            wire:model="email"
            label="อีเมล"
            type="email"
            name="email"
            required
            autofocus
            autocomplete="email"
            placeholder="name@example.com"
        />

        <div class="space-y-2">
            <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">รหัสผ่าน</span>
                @if (Route::has('password.request'))
                    <x-text-link href="{{ route('password.request') }}" class="text-xs">
                        ลืมรหัสผ่าน?
                    </x-text-link>
                @endif
            </div>
            <flux:input
                wire:model="password"
                type="password"
                name="password"
                required
                autocomplete="current-password"
                placeholder="••••••••"
            />
        </div>

        <flux:checkbox wire:model="remember" label="จดจำการเข้าสู่ระบบ" />

        <flux:button
            variant="primary"
            type="submit"
            class="w-full"
            wire:loading.attr="disabled"
            wire:target="login"
        >
            <span wire:loading.remove wire:target="login">เข้าสู่ระบบ</span>
            <span wire:loading wire:target="login">กำลังเข้าสู่ระบบ...</span>
        </flux:button>
    </form>

    <p class="text-center text-xs text-zinc-500 dark:text-zinc-500">
        หากไม่สามารถเข้าสู่ระบบได้ กรุณาติดต่อผู้ดูแลระบบงานบุคคล
    </p>
</div>
