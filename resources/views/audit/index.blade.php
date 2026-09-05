@extends('layouts.dashboard')

@section('title', 'Audit')

@section('content')
    @php
        $activePreset = $range['preset'];
        $rangeLabel = $range['label'];

        $isPositive = $totals['total_net_profit'] >= 0;
        $isEmpty = $totals['orders'] === 0 && (float) $totals['total_net_profit'] == 0;

        $money = fn (float $v) => format_money($v, 2);
        $compactMoney = fn (float $v) => compact_money($v);

        $presetLink = fn (string $key) => route('audit.index', ['preset' => $key]);
        $categoryMax = $byCategory ? max(array_column($byCategory, 'revenue')) : 0;
        $monthMax = $monthly ? max(array_column($monthly, 'gross_sale')) : 0;
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
                    Cash-based profit &amp; loss — Gross Sale (online payments + net COD) minus vendor owed, courier fees, expenses and 4% tax.
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
            {{-- Total net profit — the headline metric --}}
            <div class="rounded-xl border border-line bg-surface p-5 xl:col-span-1">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg {{ $isPositive ? 'bg-positive-soft text-positive' : 'bg-negative-soft text-negative' }}">
                            <x-dashboard.icon name="trending-up" class="h-4.5 w-4.5" />
                        </span>
                        <span class="text-sm font-medium text-muted">Total Net Profit</span>
                    </div>
                    @if (! $isEmpty)
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $totals['margin'] >= 0 ? 'bg-positive-soft text-positive' : 'bg-negative-soft text-negative' }}">
                            {{ number_format($totals['margin'], 1) }}% margin
                        </span>
                    @endif
                </div>
                <p class="mt-4 text-[30px] font-semibold leading-none tracking-tight {{ $isPositive ? 'text-ink' : 'text-negative' }} tabular-nums">
                    {{ $money($totals['total_net_profit']) }}
                </p>
                <p class="mt-2 text-xs text-muted">
                    Net profit {{ $money($totals['net_profit']) }} − expenses {{ $money($totals['expenses']) }}
                    + other income {{ $money($totals['other_income']) }} − {{ number_format($taxRate * 100, 0) }}% tax {{ $money($totals['tax']) }}
                </p>
            </div>

            <x-dashboard.stat-card label="Gross Sale" :value="$totals['gross_sale']" format="currency" :delta="0" :spark="[]" icon="trending-up" />
            <x-dashboard.stat-card label="CoGS (Vendor Owed)" :value="$totals['cogs_vendor']" format="currency" :delta="0" :spark="[]" icon="receipt" />
            <x-dashboard.stat-card label="Net Profit" :value="$totals['net_profit']" format="currency" :delta="0" :spark="[]" icon="trending-up" />
        </div>

        {{-- P&L waterfall (the agreed formula) --}}
        <div class="mt-6 overflow-hidden rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="flex flex-col gap-1 border-b border-line p-5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Profit &amp; loss statement</h2>
                    <p class="mt-0.5 text-xs text-muted">
                        Gross Sale (online + net COD) − returned products − vendor owed + shipping received (manual) − courier charges − expenses − 4% tax.
                    </p>
                </div>
                <div class="flex items-center gap-3 text-xs">
                    <span class="inline-flex items-center gap-1.5 text-muted">
                        <span class="h-2.5 w-2.5 rounded-sm bg-positive-soft ring-1 ring-positive/40"></span> Income
                    </span>
                    <span class="inline-flex items-center gap-1.5 text-muted">
                        <span class="h-2.5 w-2.5 rounded-sm bg-negative-soft ring-1 ring-negative/40"></span> Expense / deduction
                    </span>
                </div>
            </div>

            <div class="divide-y divide-line text-sm">
                {{-- Gross Sale --}}
                <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <div>
                        <p class="font-semibold text-ink">Gross Sale</p>
                        <p class="mt-0.5 text-xs text-muted">Online payments + Net COD</p>
                    </div>
                    <p class="text-xl font-semibold tracking-tight text-ink tabular-nums">{{ $money($totals['gross_sale']) }}</p>
                </div>
                <div class="grid grid-cols-1 gap-3 px-5 py-3 sm:grid-cols-2 sm:gap-4">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs text-muted">Online payments <span class="text-faint">(paid at checkout)</span></p>
                        <p class="text-sm font-medium text-positive tabular-nums">+{{ $money($totals['online_payments']) }}</p>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs text-muted">Net COD <span class="text-faint">(COD {{ $money($totals['cod_collected']) }} − courier {{ $money($totals['cod_cost']) }})</span></p>
                        <p class="text-sm font-medium text-positive tabular-nums">+{{ $money($totals['net_cod']) }}</p>
                    </div>
                </div>


                {{-- Returned --}}
                <div class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <p class="text-sm text-ink">Returned products <span class="text-xs text-faint">(refunded / partially refunded orders)</span></p>
                    <p class="text-sm font-semibold text-negative tabular-nums">−{{ $money($totals['returned_products']) }}</p>
                </div>

                {{-- Total Sale --}}
                <div class="flex flex-col gap-3 bg-canvas/40 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <p class="text-sm font-semibold text-ink">Total Sale</p>
                    <p class="text-base font-semibold tracking-tight text-ink tabular-nums">{{ $money($totals['total_sale']) }}</p>
                </div>

                {{-- CoGS --}}
                <div class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <p class="text-sm text-ink">CoGS (Vendor owed) <span class="text-xs text-faint">(purchases from vendors)</span></p>
                    <p class="text-sm font-semibold text-negative tabular-nums">−{{ $money($totals['cogs_vendor']) }}</p>
                </div>

                {{-- Gross Profit --}}
                <div class="flex flex-col gap-3 bg-canvas/40 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <p class="text-sm font-semibold text-ink">Gross Profit</p>
                    <p class="text-base font-semibold tracking-tight text-ink tabular-nums">{{ $money($totals['gross_profit']) }}</p>
                </div>

                {{-- Shipping received --}}
                <div class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <p class="text-sm text-ink">Shipping costs received <span class="text-xs text-faint">(manual shipments only — charged to customers)</span></p>
                    <p class="text-sm font-semibold text-positive tabular-nums">+{{ $money($totals['shipping_received']) }}</p>
                </div>

                {{-- Total Profit --}}
                <div class="flex flex-col gap-3 bg-canvas/40 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <p class="text-sm font-semibold text-ink">Total Profit</p>
                    <p class="text-base font-semibold tracking-tight text-ink tabular-nums">{{ $money($totals['total_profit']) }}</p>
                </div>

                {{-- Courier service charges --}}
                <div class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <p class="text-sm text-ink">Courier service charges <span class="text-xs text-faint">(what we pay courier providers)</span></p>
                    <p class="text-sm font-semibold text-negative tabular-nums">−{{ $money($totals['courier_charges']) }}</p>
                </div>

                {{-- Net Profit --}}
                <div class="flex flex-col gap-3 bg-canvas/40 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <p class="text-sm font-semibold text-ink">Net Profit</p>
                    <p class="text-base font-semibold tracking-tight text-ink tabular-nums">{{ $money($totals['net_profit']) }}</p>
                </div>


                {{-- Expenses & other income --}}
                <div class="grid grid-cols-1 gap-3 px-5 py-3.5 sm:grid-cols-2 sm:gap-4">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs text-muted">Expenses <span class="text-faint">(journal entries)</span></p>
                        <p class="text-sm font-semibold text-negative tabular-nums">−{{ $money($totals['expenses']) }}</p>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs text-muted">Other income <span class="text-faint">(refunds recovered etc.)</span></p>
                        <p class="text-sm font-medium text-positive tabular-nums">+{{ $money($totals['other_income']) }}</p>
                    </div>
                </div>

                {{-- Tax --}}
                <div class="flex flex-col gap-3 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <p class="text-sm text-ink">{{ number_format($taxRate * 100, 0) }}% Tax <span class="text-xs text-faint">(on profit before tax)</span></p>
                    <p class="text-sm font-semibold text-negative tabular-nums">−{{ $money($totals['tax']) }}</p>
                </div>

                {{-- Total Net Profit --}}
                <div class="flex flex-col gap-3 bg-ink px-5 py-4 text-surface sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-wider text-surface/80">Total Net Profit</p>
                        <p class="mt-0.5 text-xs text-surface/60">{{ $rangeLabel }}</p>
                    </div>
                    <p class="text-2xl font-bold tracking-tight tabular-nums {{ $isPositive ? 'text-white' : 'text-negative-soft' }}">
                        {{ $money($totals['total_net_profit']) }}
                    </p>
                </div>
            </div>
        </div>

        {{-- Secondary stat row --}}
        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-sm font-medium text-muted">Paid orders</p>
                <p class="mt-3 text-2xl font-semibold tracking-tight text-ink tabular-nums">{{ number_format($totals['orders']) }}</p>
                <p class="mt-1 text-xs text-muted">Online-paid orders + delivered COD parcels.</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-sm font-medium text-muted">Avg profit / order</p>
                <p class="mt-3 text-2xl font-semibold tracking-tight text-ink tabular-nums">{{ $money($totals['avg_profit_per_order']) }}</p>
                <p class="mt-1 text-xs text-muted">Total net profit divided by paid orders.</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-sm font-medium text-muted">Returned products</p>
                <p class="mt-3 text-2xl font-semibold tracking-tight text-negative tabular-nums">−{{ $money($totals['returned_products']) }}</p>
                <p class="mt-1 text-xs text-muted">Value of refunded / partially refunded orders.</p>
            </div>
        </div>


        {{-- Expenses & other income breakdown (journal entries) --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="flex flex-col gap-1 border-b border-line p-5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Expenses &amp; other income</h2>
                    <p class="mt-0.5 text-xs text-muted">Journal entries inside this period — deducted from / added to net profit.</p>
                </div>
                <div class="flex items-center gap-3 text-xs">
                    <span class="text-muted">Expenses <span class="font-semibold text-negative">−{{ $money($totals['expenses']) }}</span></span>
                    <span class="text-muted">Income <span class="font-semibold text-positive">+{{ $money($totals['other_income']) }}</span></span>
                    <a href="{{ route('journal.create') }}"
                        class="inline-flex items-center gap-1 rounded-lg bg-ink px-2.5 py-1.5 text-xs font-medium text-surface transition-colors hover:bg-ink/90">
                        <x-dashboard.icon name="plus" class="h-3.5 w-3.5" />
                        New entry
                    </a>
                </div>
            </div>

            @if (! empty($journalByCategory))
                <div class="overflow-x-auto">
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
                    <p class="text-sm font-medium text-ink">No expenses or other income yet</p>
                    <p class="mt-1 text-xs text-muted">Record shipping, packaging, marketing, rent or other entries to see how they shape total net profit.</p>
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
                    <h2 class="text-sm font-semibold text-ink">Gross profit by category</h2>
                    <p class="mt-0.5 text-xs text-muted">Product-level revenue and cost (before courier charges, expenses &amp; tax).</p>
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
                            <th class="py-3 pr-4 font-medium">Gross Profit</th>
                            <th class="py-3 pr-4 font-medium">Margin</th>
                            <th class="py-3 pr-4 font-medium">Share of total sale</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($byCategory as $row)
                            @php
                                $width = $categoryMax > 0 ? max(2, ($row['revenue'] / $categoryMax) * 100) : 0;
                                $share = $totals['total_sale'] > 0 ? round(($row['revenue'] / $totals['total_sale']) * 100, 1) : 0.0;
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
                                    <p class="mt-1 text-xs text-muted">No products have been sold in this period yet.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>


        {{-- Top products --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="flex flex-col gap-1 border-b border-line p-5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Top products</h2>
                    <p class="mt-0.5 text-xs text-muted">Best sellers by revenue — gross profit before courier charges, expenses &amp; tax.</p>
                </div>
                <span class="text-xs text-muted">{{ count($topProducts) }} products</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-line text-[11px] font-medium uppercase tracking-[0.1em] text-faint">
                            <th class="py-3 pl-5 pr-4 font-medium">Product</th>
                            <th class="py-3 pr-4 font-medium">Category</th>
                            <th class="py-3 pr-4 text-right font-medium">Units</th>
                            <th class="py-3 pr-4 text-right font-medium">Revenue</th>
                            <th class="py-3 pr-4 text-right font-medium">COGS</th>
                            <th class="py-3 pr-4 text-right font-medium">Gross Profit</th>
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
                    <p class="mt-0.5 text-xs text-muted">Gross sale, vendor owed and total net profit per month inside the selected range.</p>
                </div>
                @if (! $isEmpty)
                    <div class="flex items-center gap-3 text-xs">
                        <span class="inline-flex items-center gap-1.5 text-muted">
                            <span class="h-0.5 w-4 bg-ink"></span> Gross sale
                        </span>
                        <span class="inline-flex items-center gap-1.5 text-muted">
                            <span class="h-0.5 w-4 border-t-2 border-dashed border-accent"></span> Total net profit
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

                    $salePts = [];
                    $profitPts = [];
                    foreach ($monthly as $i => $m) {
                        $x = $pad + ($n > 1 ? $i * $innerW / ($n - 1) : $innerW / 2);
                        $yRev = $h - $pad - (($m['gross_sale'] / $niceMax) * $innerH);
                        $yProfit = $h - $pad - (max(0, $m['total_net_profit']) / $niceMax) * $innerH;
                        $salePts[] = [$x, $yRev];
                        $profitPts[] = [$x, $yProfit];
                    }
                    $saleLine = implode(' ', array_map(fn ($p) => number_format($p[0], 1, '.', '') . ',' . number_format($p[1], 1, '.', ''), $salePts));
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
                                <linearGradient id="audit-sale-fill" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#1b1b18" stop-opacity="0.08" />
                                    <stop offset="100%" stop-color="#1b1b18" stop-opacity="0" />
                                </linearGradient>
                            </defs>
                            @foreach ([0, 0.5, 1] as $step)
                                @php($gy = $h - $pad - $step * $innerH)
                                <line x1="{{ $pad }}" y1="{{ $gy }}" x2="{{ $w - $pad }}" y2="{{ $gy }}"
                                    stroke="#e9e7e1" stroke-width="1" vector-effect="non-scaling-stroke" />
                            @endforeach
                            <polygon points="{{ $saleLine }} {{ number_format($salePts[$n - 1][0], 1, '.', '') }},{{ $h - $pad }} {{ number_format($salePts[0][0], 1, '.', '') }},{{ $h - $pad }}" fill="url(#audit-sale-fill)" />
                            <polyline points="{{ $saleLine }}" fill="none" stroke="#1b1b18" stroke-width="2"
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
                    <p class="mt-1 text-xs text-muted">No sales or COD collections in the selected period to chart yet.</p>
                </div>
            @else
                <div class="overflow-x-auto border-t border-line">
                    <table class="w-full min-w-[900px] text-left text-sm">
                        <thead>
                            <tr class="border-b border-line text-[11px] font-medium uppercase tracking-[0.1em] text-faint">
                                <th class="py-3 pl-5 pr-4 font-medium">Month</th>
                                <th class="py-3 pr-4 text-right font-medium">Paid orders</th>
                                <th class="py-3 pr-4 text-right font-medium">Gross Sale</th>
                                <th class="py-3 pr-4 text-right font-medium">Returned</th>
                                <th class="py-3 pr-4 text-right font-medium">CoGS (Vendor)</th>
                                <th class="py-3 pr-4 text-right font-medium">Gross Profit</th>
                                <th class="py-3 pr-4 text-right font-medium">Total Net Profit</th>
                                <th class="py-3 pr-4 text-right font-medium">Margin</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($monthly as $row)
                                <tr class="group transition-colors hover:bg-canvas/60">
                                    <td class="py-3 pl-5 pr-4 font-medium text-ink">{{ $row['label'] }}</td>
                                    <td class="py-3 pr-4 text-right tabular-nums text-muted">{{ number_format($row['orders']) }}</td>
                                    <td class="py-3 pr-4 text-right tabular-nums text-ink">{{ $money($row['gross_sale']) }}</td>
                                    <td class="py-3 pr-4 text-right tabular-nums text-negative">
                                        @if (($row['returned_products'] ?? 0) > 0) − @endif{{ $money($row['returned_products'] ?? 0) }}
                                    </td>
                                    <td class="py-3 pr-4 text-right tabular-nums text-muted">
                                        @if (($row['cogs_vendor'] ?? 0) > 0) − @endif{{ $money($row['cogs_vendor'] ?? 0) }}
                                    </td>
                                    <td class="py-3 pr-4 text-right tabular-nums text-ink">{{ $money($row['gross_profit']) }}</td>
                                    <td class="py-3 pr-4 text-right tabular-nums font-semibold {{ $row['total_net_profit'] >= 0 ? 'text-ink' : 'text-negative' }}">
                                        {{ $money($row['total_net_profit']) }}
                                    </td>
                                    <td class="py-3 pr-4 text-right">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $row['margin'] >= 30 ? 'bg-positive-soft text-positive' : ($row['margin'] >= 10 ? 'bg-accent-soft text-accent' : ($row['margin'] < 0 ? 'bg-negative-soft text-negative' : 'bg-canvas text-muted')) }}">
                                            {{ number_format($row['margin'], 1) }}%
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-5 py-10 text-center text-sm text-muted">No monthly data in this range.</td>
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
                        <strong class="text-ink">Gross Sale</strong> = online payments (orders paid at checkout) + <strong class="text-ink">Net COD</strong>
                        (cash collected on delivered COD parcels minus the courier cost on those parcels).
                        <strong class="text-ink">Returned products</strong> subtracts refunded / partially-refunded orders
                        (full order value — the app does not yet store per-line refund amounts).
                        <strong class="text-ink">CoGS (Vendor owed)</strong> is the total of vendor purchases recorded on the
                        <a href="{{ route('vendors.index') }}" class="text-ink underline-offset-2 hover:underline">Vendors</a> page inside the period.
                        <strong class="text-ink">Shipping costs received</strong> is the shipping fee charged to customers on manual shipments
                        (added — money the store receives). <strong class="text-ink">Courier service charges</strong> are what we pay courier
                        providers on non-COD parcels (COD courier costs are already netted inside Net COD). <strong class="text-ink">Expenses</strong>
                        and <strong class="text-ink">other income</strong> come from posted
                        <a href="{{ route('journal.index') }}" class="text-ink underline-offset-2 hover:underline">journal entries</a>, and a flat
                        <strong class="text-ink">{{ number_format($taxRate * 100, 0) }}% tax</strong> ({{ number_format($taxRate * 100, 0) }}% of
                        profit before tax) is applied last.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection

