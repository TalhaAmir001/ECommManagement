@extends('layouts.dashboard')

@section('title', 'Audit')

@section('content')
    @php
        $activePreset = $range['preset'];
        $rangeLabel = $range['label'];

        $isPositive = $totals['profit'] >= 0;
        $isEmpty = $totals['orders'] === 0;

        $money = fn (float $v) => format_money($v, 2);
        $compactMoney = fn (float $v) => compact_money($v);

        $presetLink = fn (string $key) => route('audit.index', ['preset' => $key]);
        $categoryMax = $byCategory ? max(array_column($byCategory, 'revenue')) : 0;
        $monthMax = $monthly ? max(array_column($monthly, 'revenue')) : 0;
    @endphp

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- Page header --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-[22px] font-semibold tracking-tight text-ink">Audit</h1>
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface py-1 pl-2.5 pr-3 text-[11px] font-medium text-muted">
                        <x-dashboard.icon name="calendar" class="h-3.5 w-3.5 text-faint" />
                        {{ $rangeLabel }}
                    </span>
                </div>
                <p class="mt-1 text-sm text-muted">
                    Net profit calculated from successful sales — revenue minus the cost of goods sold.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium text-ink transition-colors hover:bg-canvas">
                    <x-dashboard.icon name="download" class="h-4 w-4" />
                    Export
                </button>
            </div>
        </div>

        {{-- Date range toolbar --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <form method="GET" action="{{ route('audit.index') }}" class="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="mr-1 text-xs font-medium text-muted">Period</span>
                    <div class="inline-flex shrink-0 rounded-lg border border-line bg-canvas p-0.5">
                        @foreach (['today' => 'Today', '7d' => '7D', '30d' => '30D', '90d' => '90D', '12m' => '12M', 'ytd' => 'YTD', 'all' => 'All'] as $key => $label)
                            <a href="{{ $presetLink($key) }}"
                                class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors {{ $activePreset === $key ? 'bg-ink text-surface' : 'text-muted hover:text-ink' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center gap-2 rounded-lg border border-line bg-canvas px-2.5 py-1.5">
                        <label for="audit-from" class="text-[11px] font-medium uppercase tracking-wider text-faint">From</label>
                        <input id="audit-from" type="date" name="from" value="{{ request('from') }}"
                            class="border-0 bg-transparent text-sm text-ink focus:outline-none focus:ring-0" />
                    </div>
                    <div class="flex items-center gap-2 rounded-lg border border-line bg-canvas px-2.5 py-1.5">
                        <label for="audit-to" class="text-[11px] font-medium uppercase tracking-wider text-faint">To</label>
                        <input id="audit-to" type="date" name="to" value="{{ request('to') }}"
                            class="border-0 bg-transparent text-sm text-ink focus:outline-none focus:ring-0" />
                    </div>
                    <input type="hidden" name="preset" value="custom" />
                    <button type="submit"
                        class="rounded-lg bg-ink px-3.5 py-2 text-xs font-medium text-surface transition-colors hover:bg-ink/90">
                        Apply
                    </button>
                </div>
            </form>
        </div>

        {{-- Headline KPI row --}}
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {{-- Net profit — the headline metric --}}
            <div class="rounded-xl border {{ $isPositive ? 'border-line bg-surface' : 'border-line bg-surface' }} p-5 xl:col-span-1">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg {{ $isPositive ? 'bg-positive-soft text-positive' : 'bg-negative-soft text-negative' }}">
                            <x-dashboard.icon name="trending-up" class="h-4.5 w-4.5" />
                        </span>
                        <span class="text-sm font-medium text-muted">Net Profit</span>
                    </div>
                    @if (! $isEmpty)
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $totals['margin'] >= 0 ? 'bg-positive-soft text-positive' : 'bg-negative-soft text-negative' }}">
                            {{ number_format($totals['margin'], 1) }}% margin
                        </span>
                    @endif
                </div>
                <p class="mt-4 text-[30px] font-semibold leading-none tracking-tight text-ink {{ $isPositive ? '' : 'text-negative' }}">
                    {{ $money($totals['profit']) }}
                </p>
                <p class="mt-2 text-xs text-muted">
                    Gross {{ $money($totals['gross_profit'] ?? 0) }} − courier cost {{ $money($totals['shipping_cost'] ?? 0) }} − expenses {{ $money($totals['journal_expense'] ?? 0) }} + other income {{ $money($totals['journal_income'] ?? 0) }}
                </p>
            </div>

            <x-dashboard.stat-card label="Revenue" :value="$totals['revenue']" format="currency" :delta="0" :spark="[]" icon="trending-up" />
            <x-dashboard.stat-card label="Cost of Goods" :value="$totals['cogs']" format="currency" :delta="0" :spark="[]" icon="receipt" />
            <x-dashboard.stat-card label="Profit Margin" :value="$totals['margin']" format="number" :delta="0" :spark="[]" icon="trending-up" />
        </div>

        {{-- Secondary stat row --}}
        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-sm font-medium text-muted">Successful orders</p>
                <p class="mt-3 text-2xl font-semibold tracking-tight text-ink tabular-nums">{{ number_format($totals['orders']) }}</p>
                <p class="mt-1 text-xs text-muted">Excludes cancelled and refunded orders.</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-sm font-medium text-muted">Units sold</p>
                <p class="mt-3 text-2xl font-semibold tracking-tight text-ink tabular-nums">{{ number_format($totals['units']) }}</p>
                <p class="mt-1 text-xs text-muted">Total items shipped to customers.</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-sm font-medium text-muted">Avg profit / order</p>
                <p class="mt-3 text-2xl font-semibold tracking-tight text-ink tabular-nums">{{ $money($totals['avg_profit_per_order']) }}</p>
                <p class="mt-1 text-xs text-muted">Net profit divided by successful orders.</p>
            </div>
        </div>

        {{-- Shipping & courier money --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="flex flex-col gap-1 border-b border-line p-5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Shipping &amp; courier money</h2>
                    <p class="mt-0.5 text-xs text-muted">Courier fees and COD cash inside this period, captured from manual entries, courier API syncs and Shopify fulfillments.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-px bg-line sm:grid-cols-3">
                <div class="bg-surface p-5">
                    <p class="text-sm font-medium text-muted">Courier cost</p>
                    <p class="mt-3 text-2xl font-semibold tracking-tight text-negative tabular-nums">
                        @if (($totals['shipping_cost'] ?? 0) > 0) − @endif{{ $money($totals['shipping_cost'] ?? 0) }}
                    </p>
                    <p class="mt-1 text-xs text-muted">What we paid couriers. Deducted from net profit.</p>
                    @if (($totals['shipping_estimated_cost'] ?? 0) > 0)
                        <p class="mt-1 text-xs text-muted">
                            {{ $money($totals['shipping_actual_cost'] ?? 0) }} actual +
                            {{ $money($totals['shipping_estimated_cost'] ?? 0) }} estimated from rate cards
                        </p>
                    @endif
                </div>
                <div class="bg-surface p-5">
                    <p class="text-sm font-medium text-muted">COD collected</p>
                    <p class="mt-3 text-2xl font-semibold tracking-tight text-positive tabular-nums">
                        @if (($totals['cod_collected'] ?? 0) > 0) + @endif{{ $money($totals['cod_collected'] ?? 0) }}
                    </p>
                    <p class="mt-1 text-xs text-muted">Cash received on delivered parcels. Shown as cash, separate from product revenue so it is never double counted.</p>
                </div>
                <div class="bg-surface p-5">
                    <p class="text-sm font-medium text-muted">Net shipping</p>
                    <p class="mt-3 text-2xl font-semibold tracking-tight tabular-nums {{ ($totals['shipping_net'] ?? 0) < 0 ? 'text-negative' : 'text-positive' }}">
                        {{ $money($totals['shipping_net'] ?? 0) }}
                    </p>
                    <p class="mt-1 text-xs text-muted">COD collected minus courier cost.</p>
                </div>
            </div>
        </div>

        {{-- Operating adjustments (journal entries) --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="flex flex-col gap-1 border-b border-line p-5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Operating adjustments</h2>
                    <p class="mt-0.5 text-xs text-muted">Manual expenses and other income recorded as journal entries inside this period.</p>
                </div>
                <a href="{{ route('journal.index', ['date' => $range['preset'] === 'custom' ? null : ($range['preset'] === 'all' ? null : $range['preset'])]) }}"
                    class="inline-flex items-center gap-1 text-xs font-medium text-ink hover:text-accent">
                    Manage entries
                    <x-dashboard.icon name="arrow-up-right" class="h-3.5 w-3.5" />
                </a>
            </div>

            <div class="grid grid-cols-1 gap-px bg-line sm:grid-cols-3">
                <div class="bg-surface p-5">
                    <p class="text-sm font-medium text-muted">Gross profit</p>
                    <p class="mt-3 text-2xl font-semibold tracking-tight text-ink tabular-nums">{{ $money($totals['gross_profit'] ?? 0) }}</p>
                    <p class="mt-1 text-xs text-muted">Revenue minus COGS.</p>
                </div>
                <div class="bg-surface p-5">
                    <p class="text-sm font-medium text-muted">Manual expenses</p>
                    <p class="mt-3 text-2xl font-semibold tracking-tight text-negative tabular-nums">−{{ $money($totals['journal_expense'] ?? 0) }}</p>
                    <p class="mt-1 text-xs text-muted">Sum of posted expense journal entries.</p>
                </div>
                <div class="bg-surface p-5">
                    <p class="text-sm font-medium text-muted">Other income</p>
                    <p class="mt-3 text-2xl font-semibold tracking-tight text-positive tabular-nums">+{{ $money($totals['journal_income'] ?? 0) }}</p>
                    <p class="mt-1 text-xs text-muted">Non-Shopify income (refunds recovered, etc.).</p>
                </div>
            </div>

            @if (! empty($journalByCategory))
                <div class="overflow-x-auto border-t border-line">
                    <table class="w-full min-w-[520px] text-left text-sm">
                        <thead>
                            <tr class="border-b border-line text-[11px] font-medium uppercase tracking-[0.1em] text-faint">
                                <th class="py-3 pl-5 pr-4 font-medium">Category</th>
                                <th class="py-3 pr-4 font-medium">Account</th>
                                <th class="py-3 pr-4 text-right font-medium">Effect</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($journalByCategory as $row)
                                <tr class="group transition-colors hover:bg-canvas/60">
                                    <td class="py-3.5 pl-5 pr-4">
                                        <div class="flex items-center gap-2">
                                            <span class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $row['color'] ?? '#1b1b18' }}"></span>
                                            <p class="font-medium text-ink">{{ $row['name'] }}</p>
                                            <span class="inline-flex items-center rounded-full border border-line bg-canvas px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider text-muted">
                                                {{ $row['type'] }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="py-3.5 pr-4 text-muted">{{ $row['account'] }}</td>
                                    <td class="py-3.5 pr-4 text-right tabular-nums font-semibold {{ $row['signed'] >= 0 ? 'text-positive' : 'text-negative' }}">
                                        {{ $row['signed'] >= 0 ? '+' : '−' }}{{ $money(abs($row['signed'])) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="border-t border-line px-5 py-10 text-center">
                    <p class="text-sm font-medium text-ink">No operating adjustments yet</p>
                    <p class="mt-1 text-xs text-muted">Record shipping, marketing, rent or other entries to see how they shape net profit.</p>
                    <a href="{{ route('journal.create') }}"
                        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-sm font-medium text-surface transition-colors hover:bg-ink/90">
                        <x-dashboard.icon name="plus" class="h-4 w-4" />
                        New entry
                    </a>
                </div>
            @endif
        </div>

        {{-- Profit by category --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="flex flex-col gap-1 border-b border-line p-5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Profit by category</h2>
                    <p class="mt-0.5 text-xs text-muted">Revenue, cost and net profit grouped by product category.</p>
                </div>
                <span class="text-xs text-muted">{{ count($byCategory) }} {{ count($byCategory) === 1 ? 'category' : 'categories' }}</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[680px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-line text-[11px] font-medium uppercase tracking-[0.1em] text-faint">
                            <th class="py-3 pl-5 pr-4 font-medium">Category</th>
                            <th class="py-3 pr-4 font-medium">Revenue</th>
                            <th class="py-3 pr-4 font-medium">COGS</th>
                            <th class="py-3 pr-4 font-medium">Net Profit</th>
                            <th class="py-3 pr-4 font-medium">Margin</th>
                            <th class="py-3 pr-4 font-medium">Share of revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($byCategory as $row)
                            @php
                                $width = $categoryMax > 0 ? max(2, ($row['revenue'] / $categoryMax) * 100) : 0;
                                $share = $totals['revenue'] > 0 ? round(($row['revenue'] / $totals['revenue']) * 100, 1) : 0.0;
                            @endphp
                            <tr class="group transition-colors hover:bg-canvas/60">
                                <td class="py-3.5 pl-5 pr-4">
                                    <p class="font-medium text-ink">{{ $row['category'] }}</p>
                                </td>
                                <td class="py-3.5 pr-4 tabular-nums text-ink">{{ $money($row['revenue']) }}</td>
                                <td class="py-3.5 pr-4 tabular-nums text-muted">{{ $money($row['cogs']) }}</td>
                                <td class="py-3.5 pr-4 tabular-nums font-semibold {{ $row['profit'] >= 0 ? 'text-ink' : 'text-negative' }}">
                                    {{ $money($row['profit']) }}
                                </td>
                                <td class="py-3.5 pr-4">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold {{ $row['margin'] >= 30 ? 'bg-positive-soft text-positive' : ($row['margin'] >= 10 ? 'bg-accent-soft text-accent' : ($row['margin'] < 0 ? 'bg-negative-soft text-negative' : 'bg-canvas text-muted')) }}">
                                        {{ number_format($row['margin'], 1) }}%
                                    </span>
                                </td>
                                <td class="py-3.5 pr-4">
                                    <div class="flex items-center gap-3">
                                        <div class="h-1.5 w-32 overflow-hidden rounded-full bg-canvas">
                                            <div class="h-full rounded-full bg-ink" style="width: {{ $width }}%"></div>
                                        </div>
                                        <span class="w-12 text-right text-xs tabular-nums text-muted">{{ $share }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-14 text-center">
                                    <p class="text-sm font-medium text-ink">No category data</p>
                                    <p class="mt-1 text-xs text-muted">There are no successful sales in this period.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Top profitable products --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="flex flex-col gap-1 border-b border-line p-5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Top profitable products</h2>
                    <p class="mt-0.5 text-xs text-muted">Highest-revenue SKUs in the selected period.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-line text-[11px] font-medium uppercase tracking-[0.1em] text-faint">
                            <th class="py-3 pl-5 pr-4 font-medium">Product</th>
                            <th class="py-3 pr-4 font-medium">Category</th>
                            <th class="py-3 pr-4 text-right font-medium">Units</th>
                            <th class="py-3 pr-4 text-right font-medium">Revenue</th>
                            <th class="py-3 pr-4 text-right font-medium">COGS</th>
                            <th class="py-3 pr-4 text-right font-medium">Net Profit</th>
                            <th class="py-3 pr-4 text-right font-medium">Margin</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($topProducts as $i => $product)
                            <tr class="group transition-colors hover:bg-canvas/60">
                                <td class="py-3.5 pl-5 pr-4">
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-canvas text-[11px] font-semibold text-muted tabular-nums">
                                            {{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="truncate font-medium text-ink">{{ $product['name'] }}</p>
                                            <p class="truncate text-xs text-muted tabular-nums">{{ $product['sku'] }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3.5 pr-4">
                                    <span class="inline-flex items-center rounded-full border border-line bg-canvas px-2 py-0.5 text-xs font-medium text-muted">
                                        {{ $product['category'] }}
                                    </span>
                                </td>
                                <td class="py-3.5 pr-4 text-right tabular-nums text-ink">{{ number_format($product['units']) }}</td>
                                <td class="py-3.5 pr-4 text-right tabular-nums text-ink">{{ $money($product['revenue']) }}</td>
                                <td class="py-3.5 pr-4 text-right tabular-nums text-muted">{{ $money($product['cogs']) }}</td>
                                <td class="py-3.5 pr-4 text-right tabular-nums font-semibold {{ $product['profit'] >= 0 ? 'text-ink' : 'text-negative' }}">
                                    {{ $money($product['profit']) }}
                                </td>
                                <td class="py-3.5 pr-4 text-right">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $product['margin'] >= 30 ? 'bg-positive-soft text-positive' : ($product['margin'] >= 10 ? 'bg-accent-soft text-accent' : ($product['margin'] < 0 ? 'bg-negative-soft text-negative' : 'bg-canvas text-muted')) }}">
                                        {{ number_format($product['margin'], 1) }}%
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-14 text-center">
                                    <p class="text-sm font-medium text-ink">No product data</p>
                                    <p class="mt-1 text-xs text-muted">No products have been sold in this period yet.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Monthly P&L --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="flex flex-col gap-1 border-b border-line p-5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Monthly profit &amp; loss</h2>
                    <p class="mt-0.5 text-xs text-muted">Revenue and net profit per month inside the selected range.</p>
                </div>
                @if (! $isEmpty)
                    <div class="flex items-center gap-3 text-xs">
                        <span class="inline-flex items-center gap-1.5 text-muted">
                            <span class="h-2 w-2 rounded-full bg-ink"></span> Revenue
                        </span>
                        <span class="inline-flex items-center gap-1.5 text-muted">
                            <span class="h-2 w-2 rounded-full bg-accent"></span> Net profit
                        </span>
                    </div>
                @endif
            </div>

            @if (! $isEmpty && count($monthly) > 1)
                @php
                    $n = count($monthly);
                    $w = 800;
                    $h = 220;
                    $pad = 6;
                    $innerW = $w - 2 * $pad;
                    $innerH = $h - 2 * $pad;
                    $niceMax = $monthMax > 0 ? $monthMax * 1.15 : 100;

                    $revenuePts = [];
                    $profitPts = [];
                    foreach ($monthly as $i => $m) {
                        $x = $pad + ($n > 1 ? $i * $innerW / ($n - 1) : $innerW / 2);
                        $yRev = $h - $pad - (($m['revenue'] / $niceMax) * $innerH);
                        $yProfit = $h - $pad - (max(0, $m['profit']) / $niceMax) * $innerH;
                        $revenuePts[] = [$x, $yRev];
                        $profitPts[] = [$x, $yProfit];
                    }
                    $revLine = implode(' ', array_map(fn ($p) => number_format($p[0], 1, '.', '') . ',' . number_format($p[1], 1, '.', ''), $revenuePts));
                    $profitLine = implode(' ', array_map(fn ($p) => number_format($p[0], 1, '.', '') . ',' . number_format($p[1], 1, '.', ''), $profitPts));
                @endphp
                <div class="relative px-5 pb-2 pt-5">
                    <div class="absolute inset-y-0 left-5 flex w-11 flex-col justify-between py-7 text-right">
                        <span class="text-[11px] tabular-nums text-faint">{{ $compactMoney($niceMax) }}</span>
                        <span class="text-[11px] tabular-nums text-faint">{{ $compactMoney($niceMax / 2) }}</span>
                        <span class="text-[11px] tabular-nums text-faint">{{ currency_symbol() }}0</span>
                    </div>
                    <div class="pl-11">
                        <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" class="h-52 w-full" aria-hidden="true">
                            <defs>
                                <linearGradient id="audit-revenue-fill" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#1b1b18" stop-opacity="0.08" />
                                    <stop offset="100%" stop-color="#1b1b18" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                            @foreach ([0, 0.5, 1] as $step)
                                @php($gy = $h - $pad - $step * $innerH)
                                <line x1="{{ $pad }}" y1="{{ $gy }}" x2="{{ $w - $pad }}" y2="{{ $gy }}"
                                    stroke="#e9e7e1" stroke-width="1" vector-effect="non-scaling-stroke" />
                            @endforeach
                            <polygon points="{{ $revLine }} {{ number_format($revenuePts[$n - 1][0], 1, '.', '') }},{{ $h - $pad }} {{ number_format($revenuePts[0][0], 1, '.', '') }},{{ $h - $pad }}" fill="url(#audit-revenue-fill)" />
                            <polyline points="{{ $revLine }}" fill="none" stroke="#1b1b18" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                            <polyline points="{{ $profitLine }}" fill="none" stroke="#ff750f" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" stroke-dasharray="4 3" />
                        </svg>
                    </div>
                </div>
            @endif

            @if ($isEmpty)
                <div class="px-5 py-12 text-center">
                    <p class="text-sm font-medium text-ink">No monthly data</p>
                    <p class="mt-1 text-xs text-muted">No successful sales in the selected period to chart yet.</p>
                </div>
            @else
                <div class="overflow-x-auto border-t border-line">
                    <table class="w-full min-w-[680px] text-left text-sm">
                        <thead>
                            <tr class="border-b border-line text-[11px] font-medium uppercase tracking-[0.1em] text-faint">
                                <th class="py-3 pl-5 pr-4 font-medium">Month</th>
                                <th class="py-3 pr-4 text-right font-medium">Orders</th>
                                <th class="py-3 pr-4 text-right font-medium">Revenue</th>
                                <th class="py-3 pr-4 text-right font-medium">COGS</th>
                                <th class="py-3 pr-4 text-right font-medium">Journal Adj.</th>
                                <th class="py-3 pr-4 text-right font-medium">Ship. cost</th>
                                <th class="py-3 pr-4 text-right font-medium">COD</th>
                                <th class="py-3 pr-4 text-right font-medium">Net Profit</th>
                                <th class="py-3 pr-4 text-right font-medium">Margin</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($monthly as $row)
                                <tr class="group transition-colors hover:bg-canvas/60">
                                    <td class="py-3 pl-5 pr-4 font-medium text-ink">{{ $row['label'] }}</td>
                                    <td class="py-3 pr-4 text-right tabular-nums text-muted">{{ number_format($row['orders']) }}</td>
                                    <td class="py-3 pr-4 text-right tabular-nums text-ink">{{ $money($row['revenue']) }}</td>
                                    <td class="py-3 pr-4 text-right tabular-nums text-muted">{{ $money($row['cogs']) }}</td>
                                    <td class="py-3 pr-4 text-right tabular-nums {{ ($row['journal_net'] ?? 0) < 0 ? 'text-negative' : 'text-positive' }}">
                                        {{ ($row['journal_net'] ?? 0) >= 0 ? '+' : '−' }}{{ $money(abs($row['journal_net'] ?? 0)) }}
                                    </td>
                                    <td class="py-3 pr-4 text-right tabular-nums text-muted">
                                        @if (($row['shipping_cost'] ?? 0) > 0) − @endif{{ $money($row['shipping_cost'] ?? 0) }}
                                    </td>
                                    <td class="py-3 pr-4 text-right tabular-nums text-muted">
                                        @if (($row['cod_collected'] ?? 0) > 0) + @endif{{ $money($row['cod_collected'] ?? 0) }}
                                    </td>
                                    <td class="py-3 pr-4 text-right tabular-nums font-semibold {{ $row['profit'] >= 0 ? 'text-ink' : 'text-negative' }}">
                                        {{ $money($row['profit']) }}
                                    </td>
                                    <td class="py-3 pr-4 text-right">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $row['margin'] >= 30 ? 'bg-positive-soft text-positive' : ($row['margin'] >= 10 ? 'bg-accent-soft text-accent' : ($row['margin'] < 0 ? 'bg-negative-soft text-negative' : 'bg-canvas text-muted')) }}">
                                            {{ number_format($row['margin'], 1) }}%
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-5 py-10 text-center text-sm text-muted">No monthly data in this range.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Methodology footnote --}}
        <div class="mt-6 rounded-xl border border-line bg-canvas/60 p-5">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-accent-soft text-accent">
                    <x-dashboard.icon name="alert-triangle" class="h-3.5 w-3.5" />
                </span>
                <div class="text-xs leading-relaxed text-muted">
                    <p class="font-semibold text-ink">How this is calculated</p>
                    <p class="mt-1">
                        <strong class="text-ink">Successful sales</strong> include any order that is paid (or authorized / pending payment) and
                        not cancelled, voided or fully refunded.
                        <strong class="text-ink">Revenue</strong> is the sum of unit price × quantity across each order's line items.
                        <strong class="text-ink">COGS</strong> is the sum of product cost × quantity for those same items.
                        <strong class="text-ink">Gross profit</strong> = Revenue − COGS.
                        <strong class="text-ink">Courier cost</strong> is the sum of each shipment's shipping cost
                        (attributed to its shipping date). COD collected is cash received on delivered COD parcels and is
                        reported separately so it never double counts revenue that was already recognised at sale time.
                        <strong class="text-ink">Journal entries</strong> (manual expenses and other income) recorded on the
                        <a href="{{ route('journal.index') }}" class="text-ink underline-offset-2 hover:underline">Journal</a> page are
                        posted as balanced double-entry lines and then folded in:
                        <strong class="text-ink">Net profit</strong> = Gross profit − Courier cost − Manual expenses + Other income.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
