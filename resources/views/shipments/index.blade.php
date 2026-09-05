@extends('layouts.dashboard')

@section('title', 'Shipments')

@section('content')
    @php
        $currentProvider = (string) ($filters['provider'] ?? '');
        $currentStatus = (string) ($filters['status'] ?? '');
        $currentDate = (string) ($filters['date'] ?? '');
        $currentSearch = (string) ($filters['q'] ?? '');
        $humanize = fn (string $v) => ucwords(strtolower(str_replace('_', ' ', $v)));
    @endphp

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- Page header --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-[22px] font-semibold tracking-tight text-ink">Shipments</h1>
                <p class="mt-1 text-sm text-muted">Track every parcel handed off to a courier.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('shipments.index', array_merge($filters, ['show_form' => 'create'])) }}"
                    class="inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-sm font-medium text-surface transition-colors hover:bg-ink/90">
                    <x-dashboard.icon name="plus" class="h-4 w-4" />
                    New shipment
                </a>
            </div>
        </div>

        {{-- KPI row --}}
        <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-sm font-medium text-muted">Total shipments</p>
                <p class="mt-3 text-2xl font-semibold tracking-tight text-ink tabular-nums">{{ number_format($totals['total']) }}</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-sm font-medium text-muted">In transit</p>
                <p class="mt-3 text-2xl font-semibold tracking-tight text-ink tabular-nums">{{ number_format($totals['in_transit']) }}</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-sm font-medium text-muted">Delivered</p>
                <p class="mt-3 text-2xl font-semibold tracking-tight text-ink tabular-nums">{{ number_format($totals['delivered']) }}</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-sm font-medium text-muted">Needs attention</p>
                <p class="mt-3 text-2xl font-semibold tracking-tight text-ink tabular-nums">
                    {{ number_format($totals['exceptions'] + $totals['orphaned']) }}
                </p>
                <p class="mt-1 text-xs text-muted">
                    {{ $totals['exceptions'] }} exception{{ $totals['exceptions'] === 1 ? '' : 's' }},
                    {{ $totals['orphaned'] }} unlinked
                </p>
            </div>
        </div>

        {{-- Provider health row --}}
        @if ($providers->isNotEmpty())
            <div class="mt-4 rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-ink">Providers</h2>
                </div>
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($providers as $provider)
                        <div class="flex items-center justify-between gap-3 rounded-xl border border-line bg-canvas/40 px-4 py-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="truncate text-sm font-semibold text-ink">{{ $provider->display_name }}</p>
                                    <span class="inline-flex h-4 w-4 items-center justify-center rounded-full {{ $provider->enabled ? 'bg-positive' : 'bg-faint' }}"></span>
                                </div>
                                <p class="mt-0.5 truncate text-xs text-muted">
                                    @if ($provider->last_synced_at)
                                        Last sync {{ $provider->last_synced_at->diffForHumans() }}
                                    @else
                                        Never synced
                                    @endif
                                    @if ($provider->last_sync_status === 'failed')
                                        · <span class="text-negative">error</span>
                                    @endif
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <form method="POST" action="{{ route('courier-providers.sync', $provider) }}">
                                    @csrf
                                    <button type="submit" class="rounded-md p-1.5 text-muted transition-colors hover:bg-surface hover:text-ink" title="Sync now">
                                        <x-dashboard.icon name="refresh" class="h-4 w-4" />
                                    </button>
                                </form>
                                @if ($provider->key !== 'manual')
                                    <form method="POST" action="{{ route('courier-providers.toggle', $provider) }}">
                                        @csrf
                                        <button type="submit" class="rounded-md p-1.5 text-muted transition-colors hover:bg-surface hover:text-ink" title="{{ $provider->enabled ? 'Disable' : 'Enable' }}">
                                            <x-dashboard.icon name="{{ $provider->enabled ? 'pause' : 'play' }}" class="h-4 w-4" />
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Manual new-shipment form (collapsible) --}}
        @if (request('show_form') === 'create')
            <div class="mt-4 rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-ink">New manual shipment</h2>
                    <a href="{{ route('shipments.index', collect($filters)->except('show_form')->all()) }}" class="text-xs text-muted transition-colors hover:text-ink">Cancel</a>
                </div>
                <form method="POST" action="{{ route('shipments.store') }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3" data-shipment-form>
                    @csrf
                    {{-- Order picker (hidden id + visible typeahead). The
                         typeahead is filled from /shipments/lookup-orders as
                         the operator types. The form submits the id, not a
                         free-text number, so a typo can't link to the wrong
                         order. Results are grouped as "Linked" (already has
                         a shipment) and "Unlinked" (still needs one). --}}
                    <div class="sm:col-span-2 lg:col-span-3"
                        data-order-picker
                        data-lookup-url="{{ route('shipments.lookup-orders') }}">
                        <label class="mb-1.5 block text-xs font-medium text-muted">Link to order (optional)</label>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            <div>
                                <input type="hidden" name="order_id" value="{{ old('order_id') }}" data-order-picker-id />
                                <input
                                    type="text"
                                    name="order_search"
                                    value="{{ old('order_number') }}"
                                    placeholder="Search by order #, customer name, phone or email…"
                                    autocomplete="off"
                                    data-order-picker-input
                                    class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                            </div>
                            <div class="sm:col-span-2 rounded-lg border border-line bg-canvas px-3 py-2 text-xs text-muted" data-order-picker-summary>
                                {{-- The chosen order's number, customer and
                                     total land here once a suggestion is
                                     picked. Server-side validation still
                                     verifies the order exists. --}}
                                <span class="text-faint">No order selected. Search above to pick one — results are grouped as <span class="font-semibold uppercase tracking-wider">Linked</span> or <span class="font-semibold uppercase tracking-wider">Unlinked</span>.</span>
                            </div>
                        </div>
                        <ul class="mt-2 hidden divide-y divide-line rounded-lg border border-line bg-surface shadow-sm shadow-ink/[0.02]" data-order-picker-results role="listbox"></ul>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-muted">Tracking number</label>
                        <div class="flex gap-1.5">
                            <input
                                name="tracking_number"
                                value="{{ old('tracking_number') }}"
                                data-tracking-number-input
                                placeholder="Leave blank to auto-generate"
                                class="flex-1 rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                            <button
                                type="button"
                                data-generate-tracking-number
                                data-generate-url="{{ route('shipments.generate-tracking-number') }}"
                                class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-line bg-surface px-2.5 py-2 text-xs font-medium text-ink transition-colors hover:bg-canvas"
                                title="Generate a new tracking number">
                                <x-dashboard.icon name="refresh" class="h-3.5 w-3.5" />
                                Generate
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-muted">Initial status</label>
                        <select name="status" class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected($status === \App\Enums\Courier\ShipmentStatus::Created)>{{ $status->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-muted">Consignee name</label>
                        <input name="consignee_name" class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-muted">Consignee phone</label>
                        <input name="consignee_phone" data-order-picker-phone class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-muted">Consignee email</label>
                        <input name="consignee_email" value="{{ old('consignee_email') }}" class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-muted">Consignee city</label>
                        <input name="consignee_city" class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <label class="mb-1.5 block text-xs font-medium text-muted">Consignee address</label>
                        <input name="consignee_address" class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-muted">Weight (kg)</label>
                        <input name="weight_kg" type="number" step="0.001" min="0" class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-muted">Pieces</label>
                        <input name="pieces" type="number" min="1" class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-muted">COD amount</label>
                        <input name="cod_amount" type="number" step="0.01" min="0" class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-medium text-muted">Shipping cost</label>
                        <input name="cost" type="number" step="0.01" min="0" class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="rounded-lg bg-ink px-3.5 py-2 text-xs font-medium text-surface transition-colors hover:bg-ink/90">Create shipment</button>
                    </div>
                </form>
            </div>
        @endif

        {{-- Filter toolbar --}}
        <div class="mt-4 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <form method="GET" action="{{ route('shipments.index') }}" class="flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
                <div class="relative w-full lg:max-w-xs">
                    <span class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-faint">
                        <x-dashboard.icon name="search" class="h-4 w-4" />
                    </span>
                    <input type="search" name="q" value="{{ $currentSearch }}"
                        placeholder="Search tracking #, order #, phone…"
                        class="w-full rounded-lg border border-line bg-canvas py-2.5 pl-10 pr-9 text-sm text-ink placeholder:text-faint transition-shadow focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                </div>

                <div class="flex flex-wrap items-center gap-2 lg:ml-auto">
                    <select name="provider" class="rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                        <option value="">All providers</option>
                        @foreach ($providers as $provider)
                            <option value="{{ $provider->key }}" @selected($currentProvider === $provider->key)>{{ $provider->display_name }}</option>
                        @endforeach
                    </select>

                    <select name="status" class="rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected($currentStatus === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                    </select>

                    <select name="date" class="rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                        <option value="">All time</option>
                        <option value="today" @selected($currentDate === 'today')>Today</option>
                        <option value="7d" @selected($currentDate === '7d')>Last 7 days</option>
                        <option value="30d" @selected($currentDate === '30d')>Last 30 days</option>
                    </select>

                    <button type="submit" class="rounded-lg bg-ink px-3.5 py-2 text-xs font-medium text-surface transition-colors hover:bg-ink/90">Apply</button>
                </div>
            </form>
        </div>

        {{-- Flash message --}}
        @if (session('status'))
            <div class="mt-4 rounded-xl border border-line bg-positive-soft px-4 py-3 text-sm text-positive">{{ session('status') }}</div>
        @endif
        @if (session('errors') && session('errors')->any())
            <div class="mt-4 rounded-xl border border-line bg-negative-soft px-4 py-3 text-sm text-negative">
                @foreach (session('errors')->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        {{-- Shipments table --}}
        <div class="mt-4 overflow-hidden rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[960px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-line text-[11px] font-medium uppercase tracking-[0.1em] text-faint">
                            <th class="py-3.5 pl-5 pr-4 font-medium">Tracking</th>
                            <th class="py-3.5 pr-4 font-medium">Provider</th>
                            <th class="py-3.5 pr-4 font-medium">Status</th>
                            <th class="py-3.5 pr-4 font-medium">Consignee</th>
                            <th class="py-3.5 pr-4 font-medium">Order</th>
                            <th class="py-3.5 pr-4 text-right font-medium">Last event</th>
                            <th class="py-3.5 pr-4 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line" data-shipments-table>
                        @forelse ($shipments as $shipment)
                            @php
                                // Auto-reopen the picker if the previous
                                // submission failed validation for this row.
                                $reopenPicker = (string) old('shipment_id') === (string) $shipment->id;
                                $isLinked = $shipment->order_id !== null;
                            @endphp
                            <tr class="group transition-colors hover:bg-canvas/60" data-shipment-row="{{ $shipment->id }}">
                                <td class="py-3.5 pl-5 pr-4">
                                    <div class="flex items-center gap-1.5">
                                        <a href="{{ route('shipments.show', $shipment) }}" class="font-semibold text-ink hover:text-accent">
                                            {{ $shipment->tracking_number }}
                                        </a>
                                        @if ($shipment->tracking_url)
                                            <a href="{{ $shipment->tracking_url }}" target="_blank" rel="noopener" class="text-muted transition-colors hover:text-ink" title="Open courier tracking">
                                                <x-dashboard.icon name="arrow-up-right" class="h-3.5 w-3.5" />
                                            </a>
                                        @endif
                                    </div>
                                    @if ($shipment->carrier_name)
                                        <p class="text-xs text-muted">{{ $shipment->carrier_name }}</p>
                                    @elseif ($shipment->reference)
                                        <p class="text-xs text-muted">Ref {{ $shipment->reference }}</p>
                                    @endif
                                </td>
                                <td class="py-3.5 pr-4">
                                    <span class="inline-flex items-center rounded-full border border-line bg-canvas px-2 py-0.5 text-xs font-medium text-muted">
                                        {{ $shipment->provider->display_name }}
                                    </span>
                                </td>
                                <td class="py-3.5 pr-4">
                                    <x-dashboard.courier-status-pill :status="$shipment->status" />
                                </td>
                                <td class="py-3.5 pr-4">
                                    <p class="font-medium text-ink">{{ $shipment->consignee_name ?? '—' }}</p>
                                    <p class="text-xs text-muted">{{ $shipment->consignee_city ?? '' }}</p>
                                </td>
                                <td class="py-3.5 pr-4">
                                    @if ($shipment->order)
                                        <a href="{{ route('orders.index', ['q' => $shipment->order->number]) }}" class="font-medium text-ink hover:text-accent">
                                            {{ $shipment->order->number }}
                                        </a>
                                        @if ($shipment->matched_method)
                                            <p class="text-xs text-muted">via {{ $shipment->matched_method }}</p>
                                        @endif
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full border border-line bg-canvas px-2 py-0.5 text-xs font-medium text-muted">
                                            <span class="h-1.5 w-1.5 rounded-full bg-faint"></span>Unlinked
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3.5 pr-4 text-right tabular-nums text-muted">
                                    {{ $shipment->last_event_at?->diffForHumans() ?? '—' }}
                                </td>
                                <td class="py-3.5 pr-4">
                                    <div class="flex flex-wrap items-center justify-end gap-1.5">
                                        <a href="{{ route('shipments.show', $shipment) }}"
                                            class="inline-flex items-center gap-1 rounded-md border border-line bg-surface px-2.5 py-1 text-xs font-medium text-ink transition-colors hover:bg-canvas"
                                            title="View shipment details">
                                            <x-dashboard.icon name="book-open" class="h-3 w-3" />
                                            Details
                                        </a>
                                        <button type="button"
                                            data-toggle-picker="{{ $shipment->id }}"
                                            data-picker-kind="status"
                                            class="inline-flex items-center gap-1 rounded-md border border-line bg-surface px-2.5 py-1 text-xs font-medium text-ink transition-colors hover:bg-canvas"
                                            title="Update shipment status">
                                            <x-dashboard.icon name="trending-up" class="h-3 w-3" />
                                            Status
                                        </button>
                                        @if ($isLinked)
                                            <button type="button"
                                                data-toggle-picker="{{ $shipment->id }}"
                                                data-picker-kind="link"
                                                class="inline-flex items-center gap-1 rounded-md border border-line bg-surface px-2.5 py-1 text-xs font-medium text-ink transition-colors hover:bg-canvas"
                                                title="Switch to a different order">
                                                <x-dashboard.icon name="link-2" class="h-3 w-3" />
                                                Switch
                                            </button>
                                            <form method="POST" action="{{ route('shipments.unlink', $shipment) }}" data-unlink-form class="contents">
                                                @csrf
                                                <input type="hidden" name="return" value="index">
                                                <button type="submit"
                                                    onclick="return confirm('Unlink shipment {{ $shipment->tracking_number }} from order {{ $shipment->order->number }}?');"
                                                    class="inline-flex items-center gap-1 rounded-md border border-negative/30 bg-surface px-2.5 py-1 text-xs font-medium text-negative transition-colors hover:bg-negative-soft"
                                                    title="Unlink from the current order">
                                                    <x-dashboard.icon name="unlink" class="h-3 w-3" />
                                                    Unlink
                                                </button>
                                            </form>
                                        @else
                                            <button type="button"
                                                data-toggle-picker="{{ $shipment->id }}"
                                                data-picker-kind="link"
                                                class="inline-flex items-center gap-1 rounded-md bg-ink px-2.5 py-1 text-xs font-medium text-surface transition-colors hover:bg-ink/90"
                                                title="Link to an order">
                                                <x-dashboard.icon name="link" class="h-3 w-3" />
                                                Link
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            <tr id="shipment-picker-link-{{ $shipment->id }}"
                                data-picker-row="{{ $shipment->id }}"
                                data-picker-kind="link"
                                class="{{ $reopenPicker ? '' : 'hidden' }} bg-canvas/30">
                                <td colspan="7" class="px-5 py-4">
                                    <form method="POST"
                                        action="{{ route('shipments.link', $shipment) }}"
                                        data-link-form
                                        data-shipment-link-form
                                        class="space-y-3">
                                        @csrf
                                        <input type="hidden" name="return" value="index">
                                        <input type="hidden" name="shipment_id" value="{{ $shipment->id }}">
                                        @if ($isLinked)
                                            <input type="hidden" name="confirm" value="1">
                                        @endif

                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-xs font-medium text-muted">
                                                {{ $isLinked ? 'Switch shipment ' . $shipment->tracking_number . ' to a different order' : 'Link shipment ' . $shipment->tracking_number . ' to an order' }}
                                            </p>
                                            <button type="button" data-cancel-picker="{{ $shipment->id }}" data-cancel-kind="link" class="text-xs font-medium text-muted transition-colors hover:text-ink">Cancel</button>
                                        </div>

                                        @error('order_id')
                                            <p class="rounded-lg border border-negative/30 bg-negative-soft px-3 py-2 text-xs text-negative">{{ $message }}</p>
                                        @enderror

                                        <div data-order-picker
                                            data-lookup-url="{{ route('shipments.lookup-orders') }}"
                                            data-current-order-id="{{ $shipment->order_id ?? '' }}">
                                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                                <div class="sm:col-span-2">
                                                    <input type="hidden" name="order_id" value="" data-order-picker-id />
                                                    <input type="text"
                                                        name="order_search"
                                                        placeholder="Type order #, customer name, phone or email…"
                                                        autocomplete="off"
                                                        data-order-picker-input
                                                        class="w-full rounded-lg border border-line bg-surface px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                                                </div>
                                                <div class="rounded-lg border border-line bg-surface px-3 py-2 text-xs text-muted" data-order-picker-summary>
                                                    <span class="text-faint">
                                                        @if ($isLinked)
                                                            Currently linked to <span class="font-medium text-ink">{{ $shipment->order->number }}</span>. Pick an order above to switch.
                                                        @else
                                                            No order selected. Pick an order above to link.
                                                        @endif
                                                    </span>
                                                </div>
                                            </div>
                                            <ul class="mt-2 hidden divide-y divide-line rounded-lg border border-line bg-surface shadow-sm shadow-ink/[0.02]" data-order-picker-results role="listbox"></ul>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-xs font-medium text-surface transition-colors hover:bg-ink/90">
                                                <x-dashboard.icon name="link" class="h-3.5 w-3.5" />
                                                {{ $isLinked ? 'Switch order' : 'Link order' }}
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            <tr id="shipment-picker-status-{{ $shipment->id }}"
                                data-picker-row="{{ $shipment->id }}"
                                data-picker-kind="status"
                                class="hidden bg-canvas/30">
                                <td colspan="7" class="px-5 py-4">
                                    <form method="POST"
                                        action="{{ route('shipments.events.store', $shipment) }}"
                                        data-status-form
                                        class="space-y-3">
                                        @csrf
                                        <input type="hidden" name="return" value="index">

                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-xs font-medium text-muted">
                                                Update status for {{ $shipment->tracking_number }}
                                                <span class="ml-2 text-faint">— currently <x-dashboard.courier-status-pill :status="$shipment->status" /></span>
                                            </p>
                                            <button type="button" data-cancel-picker="{{ $shipment->id }}" data-cancel-kind="status" class="text-xs font-medium text-muted transition-colors hover:text-ink">Cancel</button>
                                        </div>

                                        @error('status')
                                            <p class="rounded-lg border border-negative/30 bg-negative-soft px-3 py-2 text-xs text-negative">{{ $message }}</p>
                                        @enderror

                                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                            <div>
                                                <label class="mb-1.5 block text-xs font-medium text-muted">New status</label>
                                                <select name="status"
                                                    data-status-picker-select
                                                    required
                                                    class="w-full rounded-lg border border-line bg-surface px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                                                    @foreach ($statuses as $status)
                                                        <option value="{{ $status->value }}" @selected($status === $shipment->status)>{{ $status->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="mb-1.5 block text-xs font-medium text-muted">Description <span class="text-faint">(optional)</span></label>
                                                <input name="description"
                                                    maxlength="255"
                                                    placeholder="e.g. Picked up from warehouse"
                                                    class="w-full rounded-lg border border-line bg-surface px-2.5 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                                            </div>
                                            <div>
                                                <label class="mb-1.5 block text-xs font-medium text-muted">Location <span class="text-faint">(optional)</span></label>
                                                <input name="location"
                                                    maxlength="128"
                                                    placeholder="e.g. Karachi hub"
                                                    class="w-full rounded-lg border border-line bg-surface px-2.5 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap items-center gap-3">
                                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-xs font-medium text-surface transition-colors hover:bg-ink/90">
                                                <x-dashboard.icon name="trending-up" class="h-3.5 w-3.5" />
                                                Save status
                                            </button>
                                            @if ($shipment->provider->key !== 'manual')
                                                <span class="text-xs text-muted">
                                                    This shipment is synced from <span class="font-medium text-ink">{{ $shipment->provider->display_name }}</span>, so the actual status will be pulled from the courier API on the next refresh.
                                                </span>
                                            @endif
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-14 text-center">
                                    <p class="text-sm font-medium text-ink">No shipments yet</p>
                                    <p class="mt-1 text-xs text-muted">
                                        Use the “New shipment” button to add a manual entry, or wait for the next provider sync.
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($shipments->hasPages())
                <nav class="flex items-center justify-between gap-4 border-t border-line px-4 py-3 sm:px-5">
                    <p class="text-xs text-muted">
                        Showing <span class="font-medium text-ink">{{ $shipments->firstItem() }}</span>–<span class="font-medium text-ink">{{ $shipments->lastItem() }}</span> of <span class="font-medium text-ink">{{ number_format($shipments->total()) }}</span>
                    </p>
                    <div class="flex items-center gap-1">
                        <a href="{{ $shipments->previousPageUrl() }}" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-line text-muted transition-colors hover:bg-canvas hover:text-ink {{ $shipments->onFirstPage() ? 'pointer-events-none opacity-40' : '' }}" aria-label="Previous page">
                            <x-dashboard.icon name="chevron-left" class="h-4 w-4" />
                        </a>
                        @for ($page = max(1, $shipments->currentPage() - 2); $page <= min($shipments->lastPage(), $shipments->currentPage() + 2); $page++)
                            <a href="{{ $shipments->url($page) }}" class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-xs font-medium transition-colors {{ $page === $shipments->currentPage() ? 'bg-ink text-surface' : 'text-muted hover:bg-canvas hover:text-ink' }}">{{ $page }}</a>
                        @endfor
                        <a href="{{ $shipments->nextPageUrl() }}" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-line text-muted transition-colors hover:bg-canvas hover:text-ink {{ $shipments->hasMorePages() ? '' : 'pointer-events-none opacity-40' }}" aria-label="Next page">
                            <x-dashboard.icon name="chevron-right" class="h-4 w-4" />
                        </a>
                    </div>
                </nav>
            @endif
        </div>
    </div>
@endsection
