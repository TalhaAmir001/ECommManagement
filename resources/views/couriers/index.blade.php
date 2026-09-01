@extends('layouts.dashboard')

@section('title', 'Courier settings')

@section('content')
    @php
        $capLabels = collect($capabilities)->pluck('label', 'value');
    @endphp

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- Header --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-[22px] font-semibold tracking-tight text-ink">Courier settings</h1>
                <p class="mt-1 text-sm text-muted">
                    Connect and configure the courier service provider APIs this store ships with.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('couriers.create') }}"
                    class="inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-sm font-medium text-surface transition-colors hover:bg-ink/90">
                    <x-dashboard.icon name="plus" class="h-4 w-4" />
                    Add courier
                </a>
            </div>
        </div>

        {{-- Flash --}}
        @if (session('status'))
            <div class="mt-4 rounded-xl border border-line bg-positive-soft px-4 py-3 text-sm text-positive">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mt-4 rounded-xl border border-line bg-negative-soft px-4 py-3 text-sm text-negative">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        {{-- Provider table --}}
        <div class="mt-6 overflow-hidden rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-line text-[11px] uppercase tracking-[0.08em] text-faint">
                        <th class="px-5 py-3 font-medium">Provider</th>
                        <th class="px-5 py-3 font-medium">Capabilities</th>
                        <th class="px-5 py-3 font-medium">Poll</th>
                        <th class="px-5 py-3 font-medium">Last sync</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($providers as $provider)
                        <tr class="group">
                            <td class="px-5 py-4">
                                <a href="{{ route('couriers.edit', $provider) }}"
                                    class="font-semibold text-ink transition-colors hover:text-accent">
                                    {{ $provider->display_name }}
                                </a>
                                <p class="mt-0.5 text-xs text-muted">
                                    <span class="font-medium text-ink">{{ $provider->key }}</span>
                                    · {{ class_basename($provider->driver_class) }}
                                    @if (! $provider->enabled)
                                        · <span class="text-muted">disabled</span>
                                    @endif
                                </p>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex flex-wrap gap-1">
                                    @foreach (array_slice($provider->capabilities ?? [], 0, 3) as $cap)
                                        <span class="rounded-full border border-line bg-canvas/40 px-2 py-0.5 text-[11px] font-medium text-muted">
                                            {{ $capLabels[$cap] ?? ucwords(str_replace('_', ' ', $cap)) }}
                                        </span>
                                    @endforeach
                                    @if (count($provider->capabilities ?? []) > 3)
                                        <span class="text-[11px] text-faint">+{{ count($provider->capabilities) - 3 }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-5 py-4 text-muted tabular-nums">
                                @if ($provider->poll_interval_minutes > 0)
                                    {{ $provider->poll_interval_minutes }}m
                                @else
                                    <span class="text-faint">off</span>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                @if ($provider->last_sync_status === 'failed')
                                    <p class="text-negative">Failed</p>
                                    @if ($provider->last_sync_error)
                                        <p class="mt-0.5 max-w-[220px] truncate text-xs text-muted" title="{{ $provider->last_sync_error }}">
                                            {{ $provider->last_sync_error }}
                                        </p>
                                    @endif
                                @elseif ($provider->last_synced_at)
                                    <p class="text-muted">{{ $provider->last_synced_at->diffForHumans() }}</p>
                                @else
                                    <p class="text-faint">Never</p>
                                @endif
                            </td>

                            <td class="px-5 py-4">
                                <div class="flex items-center justify-end gap-1">
                                    <form method="POST" action="{{ route('courier-providers.sync', $provider) }}">
                                        @csrf
                                        <button type="submit"
                                            class="rounded-md p-1.5 text-muted transition-colors hover:bg-canvas hover:text-ink"
                                            title="Sync now">
                                            <x-dashboard.icon name="refresh" class="h-4 w-4" />
                                        </button>
                                    </form>
                                    @if ($provider->key !== 'manual')
                                        <form method="POST" action="{{ route('courier-providers.toggle', $provider) }}">
                                            @csrf
                                            <button type="submit"
                                                class="rounded-md p-1.5 text-muted transition-colors hover:bg-canvas hover:text-ink"
                                                title="{{ $provider->enabled ? 'Disable' : 'Enable' }}">
                                                <x-dashboard.icon name="{{ $provider->enabled ? 'pause' : 'play' }}" class="h-4 w-4" />
                                            </button>
                                        </form>
                                    @endif
                                    <a href="{{ route('couriers.edit', $provider) }}"
                                        class="rounded-md p-1.5 text-muted transition-colors hover:bg-canvas hover:text-ink"
                                        title="Edit API credentials & settings">
                                        <x-dashboard.icon name="edit" class="h-4 w-4" />
                                    </a>
                                    @if (! in_array($provider->key, ['manual', 'shopify'], true))
                                        <form method="POST" action="{{ route('couriers.destroy', $provider) }}"
                                            onsubmit="return confirm('Delete {{ $provider->display_name }}? Any linked shipments must be removed first.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                class="rounded-md p-1.5 text-muted transition-colors hover:bg-negative-soft hover:text-negative"
                                                title="Delete courier">
                                                <x-dashboard.icon name="trash" class="h-4 w-4" />
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-14 text-center">
                                <p class="text-sm font-medium text-ink">No courier providers yet</p>
                                <p class="mt-1 text-xs text-muted">Use “Add courier” to connect your first courier service provider API.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

