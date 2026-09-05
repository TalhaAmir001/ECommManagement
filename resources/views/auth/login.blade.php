@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
    <div class="w-full max-w-sm">
        {{-- Validation / error banner --}}
        @if ($errors->any())
            <div class="mb-4 rounded-lg border border-negative/30 bg-negative-soft px-4 py-3 text-sm text-negative">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <div class="rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.03]">
            {{-- Card header --}}
            <div class="flex flex-col items-center border-b border-line px-6 py-8 text-center">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-ink text-surface shadow-sm shadow-ink/10">
                    <x-dashboard.icon name="package" class="h-6 w-6" />
                </span>
                <h1 class="mt-4 text-lg font-semibold tracking-tight text-ink">Welcome to Storefront</h1>
                <p class="mt-1 text-sm text-muted">Sign in to your dashboard to continue.</p>
            </div>

            {{-- Card body --}}
            <form method="POST" action="{{ route('login') }}" class="space-y-4 px-6 py-6">
                @csrf

                {{-- Email --}}
                <div>
                    <label for="email" class="mb-1.5 block text-sm font-medium text-ink">Email</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-faint">
                            <x-dashboard.icon name="mail" class="h-4 w-4" />
                        </span>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                            placeholder="you@example.com"
                            class="w-full rounded-lg border border-line bg-surface py-2 pl-9 pr-3 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                    </div>
                </div>

                {{-- Password --}}
                <div>
                    <div class="mb-1.5 flex items-center justify-between">
                        <label for="password" class="block text-sm font-medium text-ink">Password</label>
                    </div>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-faint">
                            <x-dashboard.icon name="lock" class="h-4 w-4" />
                        </span>
                        <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="••••••••"
                            class="w-full rounded-lg border border-line bg-surface py-2 pl-9 pr-10 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                        <button type="button" id="toggle-password" aria-label="Show password"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-faint transition-colors hover:text-ink focus:outline-none">
                            <x-dashboard.icon name="eye" id="password-icon-eye" class="h-[18px] w-[18px]" />
                            <x-dashboard.icon name="eye-off" id="password-icon-eye-off" class="hidden h-[18px] w-[18px]" />
                        </button>
                    </div>
                </div>

                {{-- Remember me --}}
                <label for="remember" class="flex w-max cursor-pointer select-none items-center gap-2 text-sm text-muted">
                    <input id="remember" type="checkbox" name="remember" value="1"
                        class="h-4 w-4 rounded border-line-strong bg-surface text-ink accent-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                    Remember me
                </label>

                {{-- Submit --}}
                <button type="submit"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-ink px-3.5 py-2.5 text-sm font-medium text-surface transition-colors hover:bg-ink/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-ink/20">
                    Sign in
                </button>
            </form>
        </div>

        {{-- Footer hint --}}
        <p class="mt-5 text-center text-xs text-faint">
            Credentials are provided by your site administrator.
        </p>
    </div>

    {{-- Toggle password visibility --}}
    <script>
        const toggle = document.getElementById('toggle-password');
        const password = document.getElementById('password');
        const eye = document.getElementById('password-icon-eye');
        const eyeOff = document.getElementById('password-icon-eye-off');

        toggle?.addEventListener('click', () => {
            const shown = password.type === 'text';
            password.type = shown ? 'password' : 'text';
            toggle.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
            eye?.classList.toggle('hidden', !shown);
            eyeOff?.classList.toggle('hidden', shown);
        });
    </script>
@endsection
