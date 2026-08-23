@extends('layouts.dashboard')

@section('title', 'Orders')

@section('content')
    @php
        $baseFilters = collect($filters)->except(['page', 'sort', 'direction'])->toArray();

        $currentSort = $filters['sort'] ?? 'created_at';
        $currentDirection = $filters['direction'] ?? 'desc';
        $currentStatus = (string) ($filters['status'] ?? '');

        $sortLink = fn (string $column) => route('orders.index', array_merge($baseFilters, [
            'sort' => $column,
            'direction' => $column === $currentSort && $currentDirection === 'asc' ? 'desc' : 'asc',
        ]));

        $statusLink = fn (string $status) => route('orders.index', array_merge($baseFilters, ['status' => $status]));

        $activeFilters = collect($filters)
            ->except(['page', 'sort', 'direction'])
            ->filter(fn ($value) => $value !== '' && $value !== null);

        $hasFilters = $activeFilters->isNotEmpty();

        $humanize = fn (string $value) => ucwords(strtolower(str_replace('_', ' ', $value)));

        $filterKeyLabels = [
            'q' => 'Search', 'payment' => 'Payment', 'fulfillment' => 'Fulfillment',
            'status' => 'Status', 'date' => 'Date', 'from' => 'From', 'to' => 'To',
        ];

        $filterValueLabels = [
            'date' => ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days'],
            'status' => ['open' => 'Open', 'closed' => 'Closed'],
        ];
    @endphp

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- Page header --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-[22px] font-semibold tracking-tight text-ink">Orders</h1>
                <p class="mt-1 text-sm text-muted">Track and manage every order from your store.</p>
            </div>
            <button type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-sm font-medium text-surface transition-colors hover:bg-ink/90">
                <x-dashboard.icon name="download" class="h-4 w-4" />
                Export
            </button>
        </div>

        {{-- Toolbar --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <form method="GET" action="{{ route('orders.index') }}" class="p-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <div class="relative w-full lg:max-w-xs">
                        <span class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-faint">
                            <x-dashboard.icon name="search" class="h-4 w-4" />
                        </span>
                        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}"
                            placeholder="Search orders or customers…"
                            class="w-full rounded-lg border border-line bg-canvas py-2.5 pl-10 pr-9 text-sm text-ink placeholder:text-faint transition-shadow focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                        @if (! empty($filters['q'] ?? ''))
                            <a href="{{ route('orders.index', array_merge($baseFilters, ['q' => ''])) }}"
                                class="absolute inset-y-0 right-2 flex items-center rounded-md p-1 text-faint transition-colors hover:text-ink"
                                aria-label="Clear search">
                                <x-dashboard.icon name="close" class="h-4 w-4" />
                            </a>
                        @endif
                    </div>

                    <input type="hidden" name="status" value="{{ $currentStatus }}" />

                    <div class="flex flex-wrap items-center gap-2 lg:ml-auto">
                        <div class="inline-flex shrink-0 rounded-lg border border-line bg-canvas p-0.5">
                            @foreach (['' => 'All', 'open' => 'Open', 'closed' => 'Closed'] as $statusValue => $statusLabel)
                                <a href="{{ $statusLink($statusValue) }}"
                                    class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors {{ $currentStatus === $statusValue ? 'bg-ink text-surface' : 'text-muted hover:text-ink' }}">
                                    {{ $statusLabel }}
                                </a>
                            @endforeach
                        </div>

                        <details class="relative" @if ($hasFilters) open @endif>
                            <summary
                                class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium text-ink transition-colors hover:bg-canvas [&::-webkit-details-marker]:hidden">
                                <x-dashboard.icon name="filter" class="h-4 w-4 text-faint" />
                                <span>Filter</span>
                                @if ($hasFilters)
                                    <span class="flex h-5 min-w-5 items-center justify-center rounded-full bg-accent-soft px-1.5 text-[11px] font-semibold text-accent">{{ $activeFilters->count() }}</span>
                                @endif
                                <x-dashboard.icon name="chevron-down" class="h-4 w-4 text-faint" />
                            </summary>

                            <div class="absolute right-0 z-20 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-2xl border border-line bg-surface p-4 shadow-xl shadow-ink/5">
                                <p class="text-sm font-semibold text-ink">Filters</p>

                                <div class="mt-3 space-y-3">
                                    <div>
                                        <label for="filter-payment" class="mb-1.5 block text-xs font-medium text-muted">Payment</label>
                                        <select id="filter-payment" name="payment"
                                            class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                                            <option value="">Any</option>
                                            @foreach ($paymentStatuses as $payment)
                                                <option value="{{ $payment }}" @selected(($filters['payment'] ?? '') === $payment)>{{ $humanize($payment) }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <label for="filter-fulfillment" class="mb-1.5 block text-xs font-medium text-muted">Fulfillment</label>
                                        <select id="filter-fulfillment" name="fulfillment"
                                            class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                                            <option value="">Any</option>
                                            @foreach ($fulfillmentStatuses as $fulfillment)
                                                <option value="{{ $fulfillment }}" @selected(($filters['fulfillment'] ?? '') === $fulfillment)>{{ $humanize($fulfillment) }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <label for="filter-date" class="mb-1.5 block text-xs font-medium text-muted">Order date</label>
                                        <select id="filter-date" name="date"
                                            class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                                            <option value="">All time</option>
                                            <option value="today" @selected(($filters['date'] ?? '') === 'today')>Today</option>
                                            <option value="7d" @selected(($filters['date'] ?? '') === '7d')>Last 7 days</option>
                                            <option value="30d" @selected(($filters['date'] ?? '') === '30d')>Last 30 days</option>
                                        </select>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label for="filter-from" class="mb-1.5 block text-xs font-medium text-muted">From</label>
                                            <input id="filter-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}"
                                                class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                                        </div>
                                        <div>
                                            <label for="filter-to" class="mb-1.5 block text-xs font-medium text-muted">To</label>
                                            <input id="filter-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}"
                                                class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 flex items-center justify-between border-t border-line pt-3">
                                    <a href="{{ route('orders.index') }}" class="text-xs font-medium text-muted transition-colors hover:text-ink">Clear all</a>
                                    <button type="submit"
                                        class="rounded-lg bg-ink px-4 py-2 text-xs font-medium text-surface transition-colors hover:bg-ink/90">Apply filters</button>
                                </div>
                            </div>
                        </details>
                    </div>
                </div>
            </form>

            @if ($hasFilters)
                <div class="flex flex-wrap items-center gap-2 rounded-b-2xl border-t border-line bg-canvas/50 px-4 py-3">
                    @foreach ($activeFilters as $key => $value)
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface py-1 pl-3 pr-1.5 text-xs font-medium text-ink">
                            <span class="text-muted">{{ $filterKeyLabels[$key] ?? $humanize($key) }}:</span>
                            <span>{{ $filterValueLabels[$key][$value] ?? $humanize((string) $value) }}</span>
                            <a href="{{ route('orders.index', array_merge($baseFilters, [$key => ''])) }}"
                                class="flex h-4 w-4 items-center justify-center rounded-full text-faint transition-colors hover:bg-canvas hover:text-ink"
                                aria-label="Remove {{ $filterKeyLabels[$key] ?? $key }} filter">
                                <x-dashboard.icon name="close" class="h-3 w-3" />
                            </a>
                        </span>
                    @endforeach
                    <a href="{{ route('orders.index') }}" class="text-xs font-medium text-muted transition-colors hover:text-ink">Clear all</a>
                </div>
            @endif
        </div>

        {{-- Orders table --}}
        <div class="mt-4 overflow-hidden rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-line text-[11px] font-medium uppercase tracking-[0.1em] text-faint">
                            <th class="w-12 py-3.5 pl-5 pr-2">
                                <input type="checkbox" class="h-4 w-4 rounded border-line-strong accent-ink" aria-label="Select all orders" />
                            </th>
                            <th class="py-3.5 pr-4 font-medium">
                                <a href="{{ $sortLink('number') }}" class="inline-flex items-center gap-1 transition-colors hover:text-ink">Order
                                    <x-dashboard.icon :name="$currentSort === 'number' ? ($currentDirection === 'asc' ? 'chevron-up' : 'chevron-down') : 'chevrons-up-down'" class="h-3 w-3 {{ $currentSort === 'number' ? 'text-ink' : 'text-faint' }}" />
                                </a>
                            </th>
                            <th class="py-3.5 pr-4 font-medium">
                                <a href="{{ $sortLink('created_at') }}" class="inline-flex items-center gap-1 transition-colors hover:text-ink">Date
                                    <x-dashboard.icon :name="$currentSort === 'created_at' ? ($currentDirection === 'asc' ? 'chevron-up' : 'chevron-down') : 'chevrons-up-down'" class="h-3 w-3 {{ $currentSort === 'created_at' ? 'text-ink' : 'text-faint' }}" />
                                </a>
                            </th>
                            <th class="py-3.5 pr-4 font-medium">Customer</th>
                            <th class="py-3.5 pr-4 font-medium">Payment</th>
                            <th class="py-3.5 pr-4 font-medium">Fulfillment</th>
                            <th class="py-3.5 pr-4 font-medium">Shipment</th>
                            <th class="py-3.5 pr-4 text-right font-medium">
                                <a href="{{ $sortLink('total') }}" class="inline-flex items-center gap-1 transition-colors hover:text-ink">Total
                                    <x-dashboard.icon :name="$currentSort === 'total' ? ($currentDirection === 'asc' ? 'chevron-up' : 'chevron-down') : 'chevrons-up-down'" class="h-3 w-3 {{ $currentSort === 'total' ? 'text-ink' : 'text-faint' }}" />
                                </a>
                            </th>
                            <th class="py-3.5 pr-4 text-right font-medium">Items</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line" id="orders-tbody">
                        @forelse ($orders as $order)
                            @include('orders._row', ['order' => $order])
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-16">
                                    <div class="mx-auto flex max-w-xs flex-col items-center text-center">
                                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-canvas text-faint">
                                            <x-dashboard.icon name="orders" class="h-5 w-5" />
                                        </span>
                                        <p class="mt-3 text-sm font-medium text-ink">No orders found</p>
                                        <p class="mt-1 text-xs text-muted">Try adjusting your search or filters to find what you're looking for.</p>
                                        @if ($hasFilters || ! empty($filters['q'] ?? ''))
                                            <a href="{{ route('orders.index') }}" class="mt-3 text-xs font-medium text-accent transition-colors hover:underline">Clear all filters</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div id="orders-poll-anchor"
                 data-since="{{ $orders->max('updated_at')?->toIso8601String() ?? '' }}"
                 data-poll-url="{{ route('orders.updates') }}"
                 data-rows-url="{{ route('orders.rows') }}"
                 style="display:none">
            </div>

            @if ($orders->hasPages())
                <nav class="flex items-center justify-between gap-4 border-t border-line px-4 py-3 sm:px-5">
                    <p class="text-xs text-muted">
                        Showing <span class="font-medium text-ink">{{ $orders->firstItem() }}</span>–<span class="font-medium text-ink">{{ $orders->lastItem() }}</span> of <span class="font-medium text-ink">{{ number_format($orders->total()) }}</span>
                    </p>
                    <div class="flex items-center gap-1">
                        <a href="{{ $orders->previousPageUrl() }}"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-line text-muted transition-colors hover:bg-canvas hover:text-ink {{ $orders->onFirstPage() ? 'pointer-events-none opacity-40' : '' }}"
                            aria-label="Previous page">
                            <x-dashboard.icon name="chevron-left" class="h-4 w-4" />
                        </a>
                        @for ($page = max(1, $orders->currentPage() - 2); $page <= min($orders->lastPage(), $orders->currentPage() + 2); $page++)
                            <a href="{{ $orders->url($page) }}"
                                class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-xs font-medium transition-colors {{ $page === $orders->currentPage() ? 'bg-ink text-surface' : 'text-muted hover:bg-canvas hover:text-ink' }}">{{ $page }}</a>
                        @endfor
                        <a href="{{ $orders->nextPageUrl() }}"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-line text-muted transition-colors hover:bg-canvas hover:text-ink {{ $orders->hasMorePages() ? '' : 'pointer-events-none opacity-40' }}"
                            aria-label="Next page">
                            <x-dashboard.icon name="chevron-right" class="h-4 w-4" />
                        </a>
                    </div>
                </nav>
            @endif
        </div>
    </div>
@endsection
