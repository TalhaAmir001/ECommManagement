@extends('layouts.dashboard')

@section('title', 'Edit '.$provider->display_name)

@section('content')
    @php
        $driverShort = class_basename($provider->driver_class);
    @endphp

    <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
        <div>
            <a href="{{ route('couriers.settings') }}"
                class="inline-flex items-center gap-1 text-xs font-medium text-muted transition-colors hover:text-ink">
                <x-dashboard.icon name="chevron-left" class="h-3.5 w-3.5" />
                Back to courier settings
            </a>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <h1 class="text-[22px] font-semibold tracking-tight text-ink">{{ $provider->display_name }}</h1>
                <span class="inline-flex items-center gap-1.5 rounded-full border border-line px-2.5 py-1 text-xs font-medium text-muted">
                    <span class="h-1.5 w-1.5 rounded-full {{ $provider->enabled ? 'bg-positive' : 'bg-faint' }}"></span>
                    {{ $provider->enabled ? 'Enabled' : 'Disabled' }}
                </span>
            </div>
            <p class="mt-1 text-sm text-muted">
                Key <span class="font-medium text-ink">{{ $provider->key }}</span>
                · Driver <span class="font-medium text-ink">{{ $driverShort }}</span>
                @if ($provider->last_synced_at)
                    · Last sync {{ $provider->last_synced_at->diffForHumans() }}
                @else
                    · Never synced
                @endif
            </p>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <a href="{{ route('couriers.rates.index', $provider) }}"
                class="inline-flex items-center gap-2 rounded-lg border border-line bg-surface px-3.5 py-2 text-xs font-medium text-ink transition-colors hover:bg-canvas">
                <x-dashboard.icon name="edit" class="h-3.5 w-3.5" />
                Delivery rates
            </a>
        </div>

        <form method="POST" action="{{ route('couriers.update', $provider) }}" class="mt-4">
            @csrf
            @method('PUT')
            @include('couriers._form', [
                'provider' => $provider,
                'schema' => $schema,
                'capabilities' => $capabilities,
            ])
        </form>
    </div>
@endsection
