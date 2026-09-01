@extends('layouts.dashboard')

@section('title', 'Journal Entries')

@section('content')
    @php
        $money = fn (float $v) => format_money($v, 2);
        $activeDate = $filters['date'] ?? null;
        $activeDirection = $filters['direction'] ?? null;
        $activeStatus = $filters['status'] ?? null;
        $netPositive = $totals['net_adjustment'] >= 0;
    @endphp

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- Page header --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-[22px] font-semibold tracking-tight text-ink">Journal Entries</h1>
                <p class="mt-1 text-sm text-muted">
                    Manual expenses and other income that adjust the P&L on the Reports page.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('journal.categories') }}"
                    class="inline-flex items-center gap-2 rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium text-ink transition-colors hover:bg-canvas">
                    <x-dashboard.icon name="settings" class="h-4 w-4" />
                    Categories
                </a>
                <a href="{{ route('journal.create') }}"
                    class="inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-sm font-medium text-surface transition-colors hover:bg-ink/90">
                    <x-dashboard.icon name="plus" class="h-4 w-4" />
                    New entry
                </a>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-4 rounded-lg border border-line bg-positive-soft px-4 py-3 text-sm text-positive">
                {{ session('status') }}
            </div>
        @endif

        {{-- Filters --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <form method="GET" action="{{ route('journal.index') }}" class="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="mr-1 text-xs font-medium text-muted">Period</span>
                    <div class="inline-flex shrink-0 rounded-lg border border-line bg-canvas p-0.5">
                        @foreach (['today' => 'Today', '7d' => '7D', '30d' => '30D', '90d' => '90D', null => 'All'] as $key => $label)
                            <a href="{{ route('journal.index', array_filter(array_merge($filters, ['date' => $key, 'page' => null]))) }}"
                                class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors {{ ($activeDate ?? '') === ($key ?? '') ? 'bg-ink text-surface' : 'text-muted hover:text-ink' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <select name="direction" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-ink">
                        <option value="">All directions</option>
                        <option value="expense" {{ $activeDirection === 'expense' ? 'selected' : '' }}>Expense</option>
                        <option value="income" {{ $activeDirection === 'income' ? 'selected' : '' }}>Income</option>
                    </select>
                    <select name="category_id" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-ink">
                        <option value="">All categories</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}" {{ (int) ($filters['category_id'] ?? 0) === $cat->id ? 'selected' : '' }}>
                                {{ $cat->name }} ({{ ucfirst($cat->type) }})
                            </option>
                        @endforeach
                    </select>
                    <select name="status" class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-ink">
                        <option value="">All status</option>
                        <option value="posted" {{ $activeStatus === 'posted' ? 'selected' : '' }}>Posted</option>
                        <option value="draft" {{ $activeStatus === 'draft' ? 'selected' : '' }}>Draft</option>
                    </select>
                    <div class="flex items-center gap-2 rounded-lg border border-line bg-canvas px-2.5 py-1.5">
                        <x-dashboard.icon name="search" class="h-4 w-4 text-faint" />
                        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search reference or description"
                            class="w-44 border-0 bg-transparent text-sm text-ink placeholder:text-faint focus:outline-none focus:ring-0" />
                    </div>
                    <button type="submit"
                        class="rounded-lg bg-ink px-3.5 py-2 text-xs font-medium text-surface transition-colors hover:bg-ink/90">
                        Apply
                    </button>
                </div>
            </form>
        </div>

        {{-- Totals --}}
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-sm font-medium text-muted">Total expenses</p>
                <p class="mt-3 text-2xl font-semibold tracking-tight text-negative tabular-nums">{{ $money($totals['total_expense']) }}</p>
                <p class="mt-1 text-xs text-muted">Sum of posted expense entries in the active period.</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-sm font-medium text-muted">Other income</p>
                <p class="mt-3 text-2xl font-semibold tracking-tight text-positive tabular-nums">{{ $money($totals['total_income']) }}</p>
                <p class="mt-1 text-xs text-muted">Non-Shopify income recorded as journal entries.</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-muted">Net adjustment to profit</p>
                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $netPositive ? 'bg-positive-soft text-positive' : 'bg-negative-soft text-negative' }}">
                        {{ $netPositive ? '+' : '' }}{{ $money($totals['net_adjustment']) }}
                    </span>
                </div>
                <p class="mt-3 text-2xl font-semibold tracking-tight tabular-nums {{ $netPositive ? 'text-positive' : 'text-negative' }}">
                    {{ $money($totals['net_adjustment']) }}
                </p>
                <p class="mt-1 text-xs text-muted">Other income − expenses. Added to gross profit on Reports.</p>
            </div>
        </div>

        {{-- Table --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-line text-[11px] font-medium uppercase tracking-[0.1em] text-faint">
                            <th class="py-3 pl-5 pr-4 font-medium">Reference</th>
                            <th class="py-3 pr-4 font-medium">Date</th>
                            <th class="py-3 pr-4 font-medium">Category</th>
                            <th class="py-3 pr-4 font-medium">Description</th>
                            <th class="py-3 pr-4 text-right font-medium">Amount</th>
                            <th class="py-3 pr-4 font-medium">Status</th>
                            <th class="py-3 pr-4 text-right font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($entries as $entry)
                            @php
                                $amount = $entry->amount();
                                $type = $entry->category?->type;
                                $isExpense = $type === 'expense';
                            @endphp
                            <tr class="group transition-colors hover:bg-canvas/60">
                                <td class="py-3.5 pl-5 pr-4">
                                    <a href="{{ route('journal.show', $entry) }}" class="font-medium text-ink hover:text-accent">
                                        {{ $entry->reference }}
                                    </a>
                                </td>
                                <td class="py-3.5 pr-4 tabular-nums text-ink">{{ $entry->entry_date->format('M j, Y') }}</td>
                                <td class="py-3.5 pr-4">
                                    @if ($entry->category)
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-line bg-canvas px-2 py-0.5 text-xs font-medium text-muted">
                                            <span class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $entry->category->color ?? '#1b1b18' }}"></span>
                                            {{ $entry->category->name }}
                                        </span>
                                    @else
                                        <span class="text-xs text-faint">—</span>
                                    @endif
                                </td>
                                <td class="py-3.5 pr-4 text-muted">{{ \Illuminate\Support\Str::limit($entry->description ?? '—', 48) }}</td>
                                <td class="py-3.5 pr-4 text-right tabular-nums font-semibold {{ $isExpense ? 'text-negative' : 'text-positive' }}">
                                    {{ $isExpense ? '−' : '+' }}{{ $money($amount) }}
                                </td>
                                <td class="py-3.5 pr-4">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $entry->status === 'posted' ? 'bg-positive-soft text-positive' : 'bg-canvas text-muted' }}">
                                        {{ ucfirst($entry->status) }}
                                    </span>
                                </td>
                                <td class="py-3.5 pr-4 text-right">
                                    <a href="{{ route('journal.show', $entry) }}" class="text-xs font-medium text-muted hover:text-ink">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-14 text-center">
                                    <p class="text-sm font-medium text-ink">No journal entries yet</p>
                                    <p class="mt-1 text-xs text-muted">
                                        Record your first expense or income adjustment to start adjusting the P&L.
                                    </p>
                                    <a href="{{ route('journal.create') }}"
                                        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-sm font-medium text-surface transition-colors hover:bg-ink/90">
                                        <x-dashboard.icon name="plus" class="h-4 w-4" />
                                        New entry
                                    </a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($entries->hasPages())
                <div class="border-t border-line px-5 py-3">
                    {{ $entries->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
