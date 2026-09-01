@extends('layouts.dashboard')

@section('title', $entry->reference)

@section('content')
    @php
        $money = fn (float $v) => format_money($v, 2);
        $debitLine = $entry->lines->firstWhere('debit', '>', 0);
        $creditLine = $entry->lines->firstWhere('credit', '>', 0);
        $type = $entry->category?->type;
        $isExpense = $type === 'expense';
        $pnlEffect = $entry->pnlEffect();
    @endphp

    <div class="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- Header --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <a href="{{ route('journal.index') }}" class="inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-ink">
                    <x-dashboard.icon name="chevron-left" class="h-3.5 w-3.5" />
                    All journal entries
                </a>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <h1 class="font-mono text-[22px] font-semibold tracking-tight text-ink">{{ $entry->reference }}</h1>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $entry->status === 'posted' ? 'bg-positive-soft text-positive' : 'bg-canvas text-muted' }}">
                        {{ ucfirst($entry->status) }}
                    </span>
                    @if ($entry->category)
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-line bg-canvas px-2 py-0.5 text-xs font-medium text-muted">
                            <span class="h-1.5 w-1.5 rounded-full" style="background-color: {{ $entry->category->color ?? '#1b1b18' }}"></span>
                            {{ $entry->category->name }}
                        </span>
                    @endif
                </div>
                <p class="mt-1 text-sm text-muted">
                    {{ $entry->description ?: 'No description provided.' }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('journal.edit', $entry) }}"
                    class="inline-flex items-center gap-2 rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium text-ink transition-colors hover:bg-canvas">
                    <x-dashboard.icon name="edit" class="h-4 w-4" />
                    Edit
                </a>
                <form method="POST" action="{{ route('journal.destroy', $entry) }}" onsubmit="return confirm('Delete this journal entry? This cannot be undone.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg border border-negative/30 bg-surface px-3.5 py-2 text-sm font-medium text-negative transition-colors hover:bg-negative-soft">
                        <x-dashboard.icon name="trash" class="h-4 w-4" />
                        Delete
                    </button>
                </form>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-4 rounded-lg border border-line bg-positive-soft px-4 py-3 text-sm text-positive">
                {{ session('status') }}
            </div>
        @endif

        {{-- Summary cards --}}
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-sm font-medium text-muted">Date</p>
                <p class="mt-3 text-lg font-semibold tracking-tight text-ink">{{ $entry->entry_date->format('M j, Y') }}</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-sm font-medium text-muted">Amount</p>
                <p class="mt-3 text-2xl font-semibold tracking-tight tabular-nums {{ $isExpense ? 'text-negative' : 'text-positive' }}">
                    {{ $isExpense ? '−' : '+' }}{{ $money($entry->amount()) }}
                </p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <p class="text-sm font-medium text-muted">Effect on net profit</p>
                <p class="mt-3 text-2xl font-semibold tracking-tight tabular-nums {{ $pnlEffect >= 0 ? 'text-positive' : 'text-negative' }}">
                    {{ $pnlEffect >= 0 ? '+' : '' }}{{ $money($pnlEffect) }}
                </p>
                <p class="mt-1 text-xs text-muted">
                    {{ $isExpense ? 'Subtracted from net profit' : 'Added to net profit' }}.
                </p>
            </div>
        </div>

        {{-- Lines --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="border-b border-line p-5">
                <h2 class="text-sm font-semibold text-ink">Journal lines</h2>
                <p class="mt-0.5 text-xs text-muted">Every entry posts at least two balanced lines. Debits and credits must sum to the same value.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[560px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-line text-[11px] font-medium uppercase tracking-[0.1em] text-faint">
                            <th class="py-3 pl-5 pr-4 font-medium">Account</th>
                            <th class="py-3 pr-4 font-medium">Type</th>
                            <th class="py-3 pr-4 text-right font-medium">Debit</th>
                            <th class="py-3 pr-4 text-right font-medium">Credit</th>
                            <th class="py-3 pr-4 font-medium">Memo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($entry->lines as $line)
                            <tr class="group transition-colors hover:bg-canvas/60">
                                <td class="py-3.5 pl-5 pr-4">
                                    <p class="font-medium text-ink">{{ $line->account?->name ?? '—' }}</p>
                                </td>
                                <td class="py-3.5 pr-4">
                                    <span class="inline-flex items-center rounded-full border border-line bg-canvas px-2 py-0.5 text-xs font-medium text-muted">
                                        {{ ucfirst($line->account?->type ?? '—') }}
                                    </span>
                                </td>
                                <td class="py-3.5 pr-4 text-right tabular-nums text-ink">
                                    {{ $line->debit > 0 ? $money((float) $line->debit) : '—' }}
                                </td>
                                <td class="py-3.5 pr-4 text-right tabular-nums text-ink">
                                    {{ $line->credit > 0 ? $money((float) $line->credit) : '—' }}
                                </td>
                                <td class="py-3.5 pr-4 text-muted">{{ $line->memo ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-line bg-canvas/40 text-sm font-semibold">
                            <td colspan="2" class="py-3 pl-5 pr-4 text-ink">Totals</td>
                            <td class="py-3 pr-4 text-right tabular-nums text-ink">{{ $money($entry->totalDebit()) }}</td>
                            <td class="py-3 pr-4 text-right tabular-nums text-ink">{{ $money($entry->totalCredit()) }}</td>
                            <td class="py-3 pr-4 text-xs text-muted">Balanced ✓</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <p class="mt-4 text-xs text-muted">
            Created {{ $entry->created_at?->format('M j, Y g:i A') ?? '—' }}
            @if ($entry->updated_at && $entry->updated_at->ne($entry->created_at))
                · Updated {{ $entry->updated_at->format('M j, Y g:i A') }}
            @endif
        </p>
    </div>
@endsection
