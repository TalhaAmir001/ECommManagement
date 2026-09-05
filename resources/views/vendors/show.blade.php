@extends('layouts.dashboard')

@section('title', $vendor->name)

@section('content')
    @php
        $positive = $balance > 0.005;
        $settled = ! $positive && $balance >= -0.005;
        $statusLabel = $positive ? 'Owed' : ($settled ? 'Settled' : 'Credit');
        $statusClass = $positive ? 'bg-negative-soft text-negative' : ($settled ? 'bg-canvas text-muted' : 'bg-positive-soft text-positive');
    @endphp

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        @if (session('status'))
            <div class="mb-4 rounded-lg border border-line bg-positive-soft px-4 py-3 text-sm text-positive">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-lg border border-negative/30 bg-negative-soft px-4 py-3 text-sm text-negative">
                <p class="font-semibold">Please fix the following:</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Page header --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex flex-col gap-2">
                <a href="{{ route('vendors.index') }}" class="inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-ink">
                    <x-dashboard.icon name="chevron-left" class="h-3.5 w-3.5" />
                    Back to vendors
                </a>
                <div class="flex items-center gap-2.5">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent-soft text-accent">
                        <x-dashboard.icon name="building" class="h-5 w-5" />
                    </span>
                    <div>
                        <h1 class="text-[22px] font-semibold tracking-tight text-ink">{{ $vendor->name }}</h1>
                        @if ($vendor->contact_name || $vendor->email || $vendor->phone || $vendor->address)
                            <p class="mt-0.5 max-w-xl truncate text-sm text-muted">
                                {{ collect([$vendor->contact_name, $vendor->email, $vendor->phone])->filter()->implode(' · ') }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('vendors.edit', $vendor) }}"
                    class="inline-flex items-center gap-2 rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium text-ink transition-colors hover:bg-canvas">
                    <x-dashboard.icon name="edit" class="h-4 w-4" />
                    Edit
                </a>
                <form method="POST" action="{{ route('vendors.destroy', $vendor) }}" onsubmit="return confirm('Delete {{ addslashes($vendor->name) }} and all its purchases & payments?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium text-ink transition-colors hover:border-negative/30 hover:bg-negative-soft hover:text-negative">
                        <x-dashboard.icon name="trash" class="h-4 w-4" />
                        Delete
                    </button>
                </form>
            </div>
        </div>

        {{-- Summary strip --}}
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-line bg-surface p-5">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-canvas text-muted">
                        <x-dashboard.icon name="package" class="h-4.5 w-4.5" />
                    </span>
                    <p class="text-sm font-medium text-muted">Purchased</p>
                </div>
                <p class="mt-4 text-2xl font-semibold tracking-tight text-ink tabular-nums">{{ format_money($purchased, 2) }}</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-canvas text-muted">
                        <x-dashboard.icon name="dollar-sign" class="h-4.5 w-4.5" />
                    </span>
                    <p class="text-sm font-medium text-muted">Paid</p>
                </div>
                <p class="mt-4 text-2xl font-semibold tracking-tight text-ink tabular-nums">{{ format_money($paid, 2) }}</p>
            </div>
            <div class="rounded-xl border border-line bg-surface p-5">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-canvas text-muted">
                        <x-dashboard.icon name="receipt" class="h-4.5 w-4.5" />
                    </span>
                    <p class="text-sm font-medium text-muted">Balance</p>
                    <span class="ml-auto inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusClass }}">
                        {{ $statusLabel }}
                    </span>
                </div>
                <p class="mt-4 text-2xl font-semibold tracking-tight tabular-nums {{ $positive ? 'text-negative' : ($settled ? 'text-ink' : 'text-positive') }}">
                    {{ format_money($balance, 2) }}
                </p>
                <p class="mt-1 text-xs text-muted">
                    {{ $positive ? 'Money still owed to this vendor.' : ($settled ? 'Everything is settled.' : 'Vendor is in credit — we have overpaid.') }}
                </p>
            </div>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
            {{-- Purchases --}}
            <div class="rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
                <details class="group border-b border-line" data-purchase-details>
                    <summary
                        class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 transition-colors hover:bg-canvas/40 [&::-webkit-details-marker]:hidden">
                        <span>
                            <span class="block text-sm font-semibold text-ink">Purchases</span>
                            <span class="mt-0.5 block text-xs text-muted">Goods &amp; raw material received.</span>
                        </span>
                        <span
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink transition-colors hover:bg-canvas">
                            <x-dashboard.icon name="plus" class="h-3.5 w-3.5" />
                            Record purchase
                        </span>
                    </summary>
                    <form method="POST" action="{{ route('vendors.purchases.store', $vendor) }}" class="space-y-3 border-t border-line bg-canvas/40 px-5 py-4">
                        @csrf
                        <div>
                            <label for="purchase-item" class="text-xs font-medium text-muted">Item description</label>
                            <input id="purchase-item" type="text" name="item_description" value="{{ old('item_description') }}" required maxlength="255"
                                placeholder="e.g. Cotton fabric — 240gsm"
                                class="mt-1.5 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="purchase-ref" class="text-xs font-medium text-muted">Reference (invoice / PO)</label>
                                <input id="purchase-ref" type="text" name="reference" value="{{ old('reference') }}" maxlength="100"
                                    placeholder="INV-1024"
                                    class="mt-1.5 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink" />
                            </div>
                            <div>
                                <label for="purchase-date" class="text-xs font-medium text-muted">Date</label>
                                <input id="purchase-date" type="date" name="purchase_date"
                                    value="{{ old('purchase_date', now()->toDateString()) }}" required
                                    class="mt-1.5 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="purchase-qty" class="text-xs font-medium text-muted">Quantity</label>
                                <input id="purchase-qty" type="number" step="0.001" min="0.001" name="quantity" value="{{ old('quantity', 1) }}" required
                                    data-purchase-qty
                                    class="mt-1.5 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink" />
                            </div>
                            <div>
                                <label for="purchase-unit" class="text-xs font-medium text-muted">Unit</label>
                                <input id="purchase-unit" type="text" name="unit" value="{{ old('unit') }}" maxlength="20"
                                    placeholder="kg / pcs / roll"
                                    class="mt-1.5 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink" />
                            </div>
                            <div class="col-span-2">
                                <label for="purchase-cost" class="text-xs font-medium text-muted">Unit cost</label>
                                <div class="mt-1.5 flex rounded-lg border border-line bg-surface focus-within:ring-1 focus-within:ring-ink">
                                    <span class="inline-flex items-center px-3 text-sm text-muted">{{ currency_symbol() }}</span>
                                    <input id="purchase-cost" type="number" step="0.01" min="0.01" name="unit_cost" value="{{ old('unit_cost') }}" required
                                        data-purchase-cost
                                        class="w-full border-0 bg-transparent px-0 py-2 pr-3 text-sm text-ink placeholder:text-faint focus:outline-none focus:ring-0"
                                        placeholder="0.00" />
                                </div>
                            </div>
                            <div class="col-span-2">
                                <label for="purchase-notes" class="text-xs font-medium text-muted">Notes</label>
                                <textarea id="purchase-notes" name="notes" rows="2" maxlength="2000"
                                    class="mt-1.5 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink">{{ old('notes') }}</textarea>
                            </div>
                        </div>
                        <div class="flex items-center justify-between gap-3 pt-1">
                            <p class="text-xs text-muted">
                                Total <span class="font-semibold text-ink tabular-nums" data-purchase-total>{{ currency_symbol() }}0.00</span>
                            </p>
                            <button type="submit"
                                class="inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-sm font-medium text-surface transition-colors hover:bg-ink/90">
                                <x-dashboard.icon name="plus" class="h-4 w-4" />
                                Record purchase
                            </button>
                        </div>

                    </form>
                </details>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-line text-xs uppercase tracking-[0.08em] text-faint">
                                <th class="px-5 py-3 font-medium">Date</th>
                                <th class="px-5 py-3 font-medium">Item</th>
                                <th class="px-5 py-3 font-medium text-right">Qty</th>
                                <th class="px-5 py-3 font-medium text-right">Unit cost</th>
                                <th class="px-5 py-3 font-medium text-right">Total</th>
                                <th class="px-5 py-3 font-medium text-right"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($purchases as $purchase)
                                <tr class="transition-colors hover:bg-canvas/60">
                                    <td class="px-5 py-3.5 tabular-nums text-muted">{{ $purchase->purchase_date->format('M j, Y') }}</td>
                                    <td class="px-5 py-3.5">
                                        <p class="text-ink">{{ $purchase->item_description }}</p>
                                        @if ($purchase->reference)
                                            <p class="mt-0.5 text-xs text-muted">Ref: {{ $purchase->reference }}</p>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 text-right tabular-nums text-ink">
                                        {{ rtrim(rtrim(number_format((float) $purchase->quantity, 3), '0'), '.') }}
                                        @if ($purchase->unit)
                                            <span class="text-xs text-muted">{{ $purchase->unit }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 text-right tabular-nums text-muted">{{ format_money((float) $purchase->unit_cost, 2) }}</td>
                                    <td class="px-5 py-3.5 text-right tabular-nums font-semibold text-ink">{{ format_money((float) $purchase->total_cost, 2) }}</td>
                                    <td class="px-5 py-3.5 text-right">
                                        <form method="POST" action="{{ route('vendors.purchases.destroy', $purchase) }}" onsubmit="return confirm('Remove this purchase from the record?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                class="rounded-md p-1.5 text-muted transition-colors hover:bg-negative-soft hover:text-negative" title="Remove purchase">
                                                <x-dashboard.icon name="trash" class="h-4 w-4" />
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-10 text-center">
                                        <p class="text-sm font-medium text-ink">No purchases recorded</p>
                                        <p class="mt-1 text-xs text-muted">Use “Record purchase” above to add the first delivery from this vendor.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

            </div>

            {{-- Payments --}}
            <div class="rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
                <details class="group border-b border-line" data-payment-details>
                    <summary
                        class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 transition-colors hover:bg-canvas/40 [&::-webkit-details-marker]:hidden">
                        <span>
                            <span class="block text-sm font-semibold text-ink">Payments</span>
                            <span class="mt-0.5 block text-xs text-muted">Money sent to this vendor.</span>
                        </span>
                        <span
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink transition-colors hover:bg-canvas">
                            <x-dashboard.icon name="plus" class="h-3.5 w-3.5" />
                            Record payment
                        </span>
                    </summary>
                    <form method="POST" action="{{ route('vendors.payments.store', $vendor) }}" class="space-y-3 border-t border-line bg-canvas/40 px-5 py-4">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="payment-amount" class="text-xs font-medium text-muted">Amount</label>
                                <div class="mt-1.5 flex rounded-lg border border-line bg-surface focus-within:ring-1 focus-within:ring-ink">
                                    <span class="inline-flex items-center px-3 text-sm text-muted">{{ currency_symbol() }}</span>
                                    <input id="payment-amount" type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" required
                                        class="w-full border-0 bg-transparent px-0 py-2 pr-3 text-sm text-ink placeholder:text-faint focus:outline-none focus:ring-0"
                                        placeholder="0.00" />
                                </div>
                            </div>
                            <div>
                                <label for="payment-date" class="text-xs font-medium text-muted">Date</label>
                                <input id="payment-date" type="date" name="payment_date"
                                    value="{{ old('payment_date', now()->toDateString()) }}" required
                                    class="mt-1.5 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink" />
                            </div>
                            <div>
                                <label for="payment-method" class="text-xs font-medium text-muted">Method</label>
                                <input id="payment-method" type="text" name="method" value="{{ old('method') }}" maxlength="100"
                                    placeholder="Bank Transfer / Cash / Cheque"
                                    class="mt-1.5 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink" />
                            </div>
                            <div>
                                <label for="payment-ref" class="text-xs font-medium text-muted">Reference</label>
                                <input id="payment-ref" type="text" name="reference" value="{{ old('reference') }}" maxlength="100"
                                    placeholder="TXN / cheque no."
                                    class="mt-1.5 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink" />
                            </div>
                            <div class="col-span-2">
                                <label for="payment-notes" class="text-xs font-medium text-muted">Notes</label>
                                <textarea id="payment-notes" name="notes" rows="2" maxlength="2000"
                                    class="mt-1.5 w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink">{{ old('notes') }}</textarea>
                            </div>
                        </div>
                        <div class="flex items-center justify-end gap-3 pt-1">
                            <button type="submit"
                                class="inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-sm font-medium text-surface transition-colors hover:bg-ink/90">
                                <x-dashboard.icon name="plus" class="h-4 w-4" />
                                Record payment
                            </button>
                        </div>
                    </form>
                </details>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-line text-xs uppercase tracking-[0.08em] text-faint">
                                <th class="px-5 py-3 font-medium">Date</th>
                                <th class="px-5 py-3 font-medium">Method</th>
                                <th class="px-5 py-3 font-medium">Reference</th>
                                <th class="px-5 py-3 font-medium text-right">Amount</th>
                                <th class="px-5 py-3 font-medium text-right"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($payments as $payment)
                                <tr class="transition-colors hover:bg-canvas/60">
                                    <td class="px-5 py-3.5 tabular-nums text-muted">{{ $payment->payment_date->format('M j, Y') }}</td>
                                    <td class="px-5 py-3.5 text-ink">{{ $payment->method ?? '—' }}</td>
                                    <td class="px-5 py-3.5">
                                        <p class="text-muted">{{ $payment->reference ?? '—' }}</p>
                                        @if ($payment->notes)
                                            <p class="mt-0.5 text-xs text-faint">{{ \Illuminate\Support\Str::limit($payment->notes, 60) }}</p>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 text-right tabular-nums font-semibold text-positive">{{ format_money((float) $payment->amount, 2) }}</td>
                                    <td class="px-5 py-3.5 text-right">
                                        <form method="POST" action="{{ route('vendors.payments.destroy', $payment) }}" onsubmit="return confirm('Remove this payment from the record?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                class="rounded-md p-1.5 text-muted transition-colors hover:bg-negative-soft hover:text-negative" title="Remove payment">
                                                <x-dashboard.icon name="trash" class="h-4 w-4" />
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-10 text-center">
                                        <p class="text-sm font-medium text-ink">No payments recorded</p>
                                        <p class="mt-1 text-xs text-muted">Use “Record payment” above when money is sent to this vendor.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

            </div>

        </div>
    </div>

    {{-- Live “Total” preview for the record-purchase form. --}}
    <script>
        (function () {
            const qty = document.querySelector('[data-purchase-qty]');
            const cost = document.querySelector('[data-purchase-cost]');
            const total = document.querySelector('[data-purchase-total]');
            const symbol = '{{ currency_symbol() }}';
            const money = new Intl.NumberFormat(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });

            const update = () => {
                const q = parseFloat(qty?.value) || 0;
                const c = parseFloat(cost?.value) || 0;
                if (total) {
                    total.textContent = symbol + money.format(q * c);
                }
            };

            qty?.addEventListener('input', update);
            cost?.addEventListener('input', update);
        })();
    </script>
@endsection
