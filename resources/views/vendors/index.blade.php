@extends('layouts.dashboard')

@section('title', 'Vendors')

@section('content')
    @php
        $q = (string) ($filters['q'] ?? '');
        $netPositive = $summary['balance'] >= 0;
        $rowMoney = function ($vendor) {
            return [
                'purchased' => (float) ($vendor->purchases_sum_total_cost ?? 0),
                'paid' => (float) ($vendor->payments_sum_amount ?? 0),
            ];
        };
        $balanceChip = function (float $balance) {
            if ($balance > 0.005) {
                return ['Owed', 'bg-negative-soft text-negative'];
            }
            if ($balance < -0.005) {
                return ['Credit', 'bg-positive-soft text-positive'];
            }

            return ['Settled', 'bg-canvas text-muted'];
        };
    @endphp

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- Page header --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-[22px] font-semibold tracking-tight text-ink">Vendors</h1>
                <p class="mt-1 text-sm text-muted">Track goods coming in and how much every supplier is owed.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('vendors.create') }}"
                    class="inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-sm font-medium text-surface transition-colors hover:bg-ink/90">
                    <x-dashboard.icon name="plus" class="h-4 w-4" />
                    Add vendor
                </a>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-4 rounded-lg border border-line bg-positive-soft px-4 py-3 text-sm text-positive">
                {{ session('status') }}
            </div>
        @endif

        {{-- Summary strip --}}
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-line bg-surface p-5">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-canvas text-muted">
                        <x-dashboard.icon name="package" class="h-4.5 w-4.5" />
                    </span>
                    <p class="text-sm font-medium text-muted">Purchased from vendors</p>
                </div>
                <p class="mt-4 text-2xl font-semibold tracking-tight text-ink tabular-nums">{{ format_money($summary['purchased'], 2) }}</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-canvas text-muted">
                        <x-dashboard.icon name="dollar-sign" class="h-4.5 w-4.5" />
                    </span>
                    <p class="text-sm font-medium text-muted">Paid to vendors</p>
                </div>
                <p class="mt-4 text-2xl font-semibold tracking-tight text-ink tabular-nums">{{ format_money($summary['paid'], 2) }}</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-canvas text-muted">
                        <x-dashboard.icon name="receipt" class="h-4.5 w-4.5" />
                    </span>
                    <p class="text-sm font-medium text-muted">Net balance</p>
                </div>
                <p class="mt-4 text-2xl font-semibold tracking-tight tabular-nums {{ $netPositive ? 'text-negative' : 'text-positive' }}">
                    {{ format_money($summary['balance'], 2) }}
                </p>
                <p class="mt-1 text-xs text-muted">
                    {{ $netPositive ? 'Overall amount still owed to vendors.' : 'Overall credit in your favour.' }}
                </p>
            </div>
        </div>

        {{-- Search + list --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-center sm:justify-between">
                <form method="GET" action="{{ route('vendors.index') }}" class="flex flex-1 items-center gap-2">
                    <div class="relative flex-1 sm:max-w-xs">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-faint">
                            <x-dashboard.icon name="search" class="h-4 w-4" />
                        </span>
                        <input type="search" name="q" value="{{ $q }}" placeholder="Search vendors…"
                            class="w-full rounded-lg border border-line bg-canvas py-2 pl-9 pr-3 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink" />
                    </div>
                    <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2 text-sm font-medium text-ink transition-colors hover:bg-canvas">
                        Search
                    </button>
                    @if ($q !== '')
                        <a href="{{ route('vendors.index') }}" class="text-xs font-medium text-muted hover:text-ink">Clear</a>
                    @endif
                </form>
                <p class="text-xs text-muted sm:text-right">{{ $vendors->total() }} vendor{{ $vendors->total() === 1 ? '' : 's' }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-line text-xs uppercase tracking-[0.08em] text-faint">
                            <th class="px-5 py-3 font-medium">Vendor</th>
                            <th class="px-5 py-3 font-medium text-right">Purchased</th>
                            <th class="px-5 py-3 font-medium text-right">Paid</th>
                            <th class="px-5 py-3 font-medium text-right">Balance</th>
                            <th class="px-5 py-3 font-medium text-right">Status</th>
                            <th class="px-5 py-3 font-medium text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($vendors as $vendor)
                            @php
                                $money = $rowMoney($vendor);
                                $balance = round($money['purchased'] - $money['paid'], 2);
                                [$chipLabel, $chipClass] = $balanceChip($balance);
                            @endphp
                            <tr class="group transition-colors hover:bg-canvas/60">
                                <td class="px-5 py-3.5">
                                    <a href="{{ route('vendors.show', $vendor) }}" class="font-medium text-ink hover:text-accent">
                                        {{ $vendor->name }}
                                    </a>
                                    @if ($vendor->contact_name || $vendor->email || $vendor->phone)
                                        <p class="mt-0.5 text-xs text-muted">
                                            {{ collect([$vendor->contact_name, $vendor->email, $vendor->phone])->filter()->implode(' · ') }}
                                        </p>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5 text-right tabular-nums text-ink">{{ format_money($money['purchased'], 2) }}</td>
                                <td class="px-5 py-3.5 text-right tabular-nums text-ink">{{ format_money($money['paid'], 2) }}</td>
                                <td class="px-5 py-3.5 text-right tabular-nums {{ $balance > 0.005 ? 'font-semibold text-negative' : ($balance < -0.005 ? 'font-semibold text-positive' : 'text-muted') }}">
                                    {{ format_money($balance, 2) }}
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $chipClass }}">
                                        {{ $chipLabel }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <div class="flex items-center justify-end gap-2 lg:opacity-0 lg:group-hover:opacity-100">
                                        <a href="{{ route('vendors.edit', $vendor) }}"
                                            class="rounded-md p-1.5 text-muted transition-colors hover:bg-canvas hover:text-ink" title="Edit vendor">
                                            <x-dashboard.icon name="edit" class="h-4 w-4" />
                                        </a>
                                        <form method="POST" action="{{ route('vendors.destroy', $vendor) }}" onsubmit="return confirm('Delete {{ addslashes($vendor->name) }} and all its purchases & payments?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                class="rounded-md p-1.5 text-muted transition-colors hover:bg-negative-soft hover:text-negative" title="Delete vendor">
                                                <x-dashboard.icon name="trash" class="h-4 w-4" />
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-14 text-center">
                                    <p class="text-sm font-medium text-ink">{{ $q !== '' ? 'No vendors match your search' : 'No vendors yet' }}</p>
                                    <p class="mt-1 text-xs text-muted">
                                        Add your first supplier to start tracking purchases and payables.
                                    </p>
                                    <a href="{{ route('vendors.create') }}"
                                        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-sm font-medium text-surface transition-colors hover:bg-ink/90">
                                        <x-dashboard.icon name="plus" class="h-4 w-4" />
                                        Add vendor
                                    </a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($vendors->hasPages())
                <div class="border-t border-line px-5 py-3">
                    {{ $vendors->links() }}
                </div>
            @endif

        </div>
    </div>
@endsection
