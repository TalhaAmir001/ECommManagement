@extends('layouts.dashboard')

@section('title', 'Overview')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- Page header --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-ink">Dashboard</h1>
                <p class="mt-1 text-sm text-muted">Your store's performance at a glance.</p>
            </div>
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('dashboard.sync-shopify') }}" class="inline-flex">
                    @csrf
                    <button type="submit" title="Pull the latest products, customers and orders (incl. product weights) from Shopify"
                        class="inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-sm font-medium text-surface transition-colors hover:bg-ink/90">
                        <x-dashboard.icon name="refresh" class="h-4 w-4" />
                        Sync Shopify data
                    </button>
                </form>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-4 rounded-lg border border-positive/30 bg-positive-soft px-4 py-3 text-sm text-positive">
                {{ session('status') }}
            </div>
        @endif
        @if (session('error'))
            <div class="mt-4 rounded-lg border border-negative/30 bg-negative-soft px-4 py-3 text-sm text-negative">
                {{ session('error') }}
            </div>
        @endif

        {{-- Stat cards --}}
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.stat-card label="Revenue" :value="$stats['revenue']['value']" format="currency"
                :delta="$stats['revenue']['delta']" :spark="$stats['revenue']['spark']" icon="trending-up" />
            <x-dashboard.stat-card label="Orders" :value="$stats['orders']['value']" format="number"
                :delta="$stats['orders']['delta']" :spark="$stats['orders']['spark']" icon="shopping-cart" />
            <x-dashboard.stat-card label="New Customers" :value="$stats['customers']['value']" format="number"
                :delta="$stats['customers']['delta']" :spark="$stats['customers']['spark']" icon="user-plus" />
            <x-dashboard.stat-card label="Avg Order Value" :value="$stats['aov']['value']" format="currency"
                :delta="$stats['aov']['delta']" :spark="$stats['aov']['spark']" icon="receipt" />
        </div>

        {{-- P&L summary strip --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="flex flex-col gap-1 border-b border-line p-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Profit &amp; loss</h2>
                    <p class="mt-0.5 text-xs text-muted">
                        Gross Sale (online payments + net COD) minus vendor owed, courier charges, expenses &amp; 4% tax — last {{ $range }} days.
                    </p>
                </div>
                <a href="{{ route('audit.index', ['preset' => '30d']) }}" class="inline-flex items-center gap-1 text-xs font-medium text-ink hover:text-accent">
                    Open full statement
                    <x-dashboard.icon name="arrow-up-right" class="h-3.5 w-3.5" />
                </a>
            </div>

            <div class="grid grid-cols-1 gap-px bg-line sm:grid-cols-2 xl:grid-cols-4">
                <div class="bg-surface p-5">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-canvas text-muted"><x-dashboard.icon name="trending-up" class="h-4 w-4" /></span>
                        <p class="text-sm font-medium text-muted">Gross Sale</p>
                    </div>
                    <p class="mt-4 text-2xl font-semibold tracking-tight text-ink tabular-nums">{{ format_money($pnl['gross_sale'], 2) }}</p>
                    <p class="mt-1 text-xs text-muted">
                        <span class="text-positive">+{{ format_money($pnl['online_payments'], 2) }}</span> online
                        · <span class="text-positive">+{{ format_money($pnl['net_cod'], 2) }}</span> net COD
                    </p>
                    <p class="mt-1 text-xs {{ $pnl['gross_sale_delta'] >= 0 ? 'text-positive' : 'text-negative' }}">
                        {{ $pnl['gross_sale_delta'] >= 0 ? '+' : '' }}{{ number_format($pnl['gross_sale_delta'], 1) }}% vs prev {{ $range }}d
                    </p>
                </div>
                <div class="bg-surface p-5">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-canvas text-muted"><x-dashboard.icon name="receipt" class="h-4 w-4" /></span>
                        <p class="text-sm font-medium text-muted">CoGS (Vendor owed)</p>
                    </div>
                    <p class="mt-4 text-2xl font-semibold tracking-tight text-negative tabular-nums">−{{ format_money($pnl['cogs_vendor'], 2) }}</p>
                    <p class="mt-1 text-xs text-muted">Vendor purchases inside the period.</p>
                    <a href="{{ route('vendors.index') }}" class="mt-1 inline-flex items-center gap-1 text-xs text-ink hover:text-accent">
                        Vendors <x-dashboard.icon name="arrow-up-right" class="h-3 w-3" />
                    </a>
                </div>
                <div class="bg-surface p-5">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-canvas text-muted"><x-dashboard.icon name="book-open" class="h-4 w-4" /></span>
                        <p class="text-sm font-medium text-muted">Net Profit</p>
                    </div>
                    <p class="mt-4 text-2xl font-semibold tracking-tight tabular-nums {{ $pnl['net_profit'] >= 0 ? 'text-ink' : 'text-negative' }}">{{ format_money($pnl['net_profit'], 2) }}</p>
                    <p class="mt-1 text-xs text-muted">
                        <span class="text-positive">+{{ format_money($pnl['shipping_received'], 2) }}</span> shipping (manual)
                        · <span class="text-negative">−{{ format_money($pnl['courier_charges'], 2) }}</span> courier
                    </p>
                    <p class="mt-1 text-xs {{ $pnl['net_profit_delta'] >= 0 ? 'text-positive' : 'text-negative' }}">
                        {{ $pnl['net_profit_delta'] >= 0 ? '+' : '' }}{{ number_format($pnl['net_profit_delta'], 1) }}% vs prev {{ $range }}d
                    </p>
                </div>
                <div class="bg-surface p-5">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg {{ $pnl['total_net_profit'] >= 0 ? 'bg-positive-soft text-positive' : 'bg-negative-soft text-negative' }}"><x-dashboard.icon name="trending-up" class="h-4 w-4" /></span>
                        <p class="text-sm font-medium text-muted">Total Net Profit</p>
                    </div>
                    <p class="mt-4 text-2xl font-semibold tracking-tight tabular-nums {{ $pnl['total_net_profit'] >= 0 ? 'text-positive' : 'text-negative' }}">{{ format_money($pnl['total_net_profit'], 2) }}</p>
                    <p class="mt-1 text-xs text-muted">
                        <span class="text-negative">−{{ format_money($pnl['expenses'], 2) }}</span> expenses
                        · <span class="text-positive">+{{ format_money($pnl['other_income'], 2) }}</span> income
                        · <span class="text-negative">−{{ format_money($pnl['tax'], 2) }}</span> tax
                    </p>
                    <p class="mt-1 text-xs {{ $pnl['total_net_profit_delta'] >= 0 ? 'text-positive' : 'text-negative' }}">
                        {{ $pnl['total_net_profit_delta'] >= 0 ? '+' : '' }}{{ number_format($pnl['total_net_profit_delta'], 1) }}% vs prev {{ $range }}d
                    </p>
                </div>
            </div>
        </div>

        {{-- Charts --}}
        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="rounded-xl border border-line bg-surface p-5 lg:col-span-2">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-ink">Sales by day</h2>
                        <p class="mt-0.5 text-xs text-muted">Order revenue booked each day (cash-basis Gross Sale lives in the P&amp;L panel above)</p>
                    </div>
                    <div class="flex shrink-0 rounded-lg border border-line bg-canvas p-0.5">
                        @foreach ([7 => '7D', 30 => '30D', 90 => '90D'] as $days => $label)
                            <a href="{{ route('dashboard', ['range' => $days]) }}"
                                class="rounded-md px-3 py-1 text-xs font-medium transition-colors {{ $range === $days ? 'bg-ink text-surface' : 'text-muted hover:text-ink' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>
                <div class="mt-4">
                    <x-dashboard.area-chart :series="$revenueSeries" />
                </div>
            </div>

            <div class="rounded-xl border border-line bg-surface p-5">
                <h2 class="text-sm font-semibold text-ink">Sales by Category</h2>
                <p class="mt-0.5 text-xs text-muted">Revenue share this period</p>
                <div class="mt-5">
                    <x-dashboard.category-bars :data="$salesByCategory" />
                </div>
            </div>
        </div>

        {{-- Orders + right rail --}}
        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="rounded-xl border border-line bg-surface p-5 lg:col-span-2">
                <div class="mb-1 flex items-start justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-ink">Recent Orders</h2>
                        <p class="mt-0.5 text-xs text-muted">Latest customer orders</p>
                    </div>
                    <a href="{{ route('orders.index') }}" class="inline-flex items-center gap-1 text-xs font-medium text-ink hover:text-accent">
                        View all
                        <x-dashboard.icon name="arrow-up-right" class="h-3.5 w-3.5" />
                    </a>
                </div>
                <div class="mt-2">
                    <x-dashboard.orders-table :orders="$recentOrders" />
                </div>
            </div>

            <div class="space-y-4">
                <div class="rounded-xl border border-line bg-surface p-5">
                    <h2 class="text-sm font-semibold text-ink">Top Products</h2>
                    <p class="mt-0.5 text-xs text-muted">Best sellers by revenue</p>
                    <div class="mt-4">
                        <x-dashboard.top-products :products="$topProducts" />
                    </div>
                </div>

                <div class="rounded-xl border border-line bg-surface p-5">
                    <h2 class="text-sm font-semibold text-ink">Low Stock</h2>
                    <p class="mt-0.5 text-xs text-muted">Items running out soon</p>
                    <div class="mt-4">
                        <x-dashboard.low-stock :products="$lowStock" />
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
