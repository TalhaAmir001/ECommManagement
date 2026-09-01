@extends('layouts.dashboard')

@section('title', 'Add courier')

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
        <div>
            <a href="{{ route('couriers.settings') }}"
                class="inline-flex items-center gap-1 text-xs font-medium text-muted transition-colors hover:text-ink">
                <x-dashboard.icon name="chevron-left" class="h-3.5 w-3.5" />
                Back to courier settings
            </a>
            <h1 class="mt-2 text-[22px] font-semibold tracking-tight text-ink">Add a courier service provider</h1>
            <p class="mt-1 text-sm text-muted">
                Create a configurable courier connection (endpoint, auth and JSON field mapping) without writing code.
            </p>
        </div>

        <form method="POST" action="{{ route('couriers.store') }}" class="mt-4">
            @csrf
            @include('couriers._form', [
                'provider' => $provider,
                'schema' => $schema,
                'capabilities' => $capabilities,
            ])
        </form>
    </div>
@endsection
