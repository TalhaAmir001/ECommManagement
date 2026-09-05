@extends('layouts.dashboard')

@section('title', $shipment->tracking_number)

@section('content')
    @php
        $isManual = $shipment->provider->key === 'manual';
    @endphp

    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- Header --}}
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <a href="{{ route('shipments.index') }}" class="inline-flex items-center gap-1 text-xs font-medium text-muted transition-colors hover:text-ink">
                    <x-dashboard.icon name="chevron-left" class="h-3.5 w-3.5" />
                    Back to shipments
                </a>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <h1 class="text-[22px] font-semibold tracking-tight text-ink">{{ $shipment->tracking_number }}</h1>
                    <x-dashboard.courier-status-pill :status="$shipment->status" />
                </div>
                <p class="mt-1 text-sm text-muted">
                    {{ $shipment->provider->display_name }}
                    @if ($shipment->carrier_name)
                        · {{ $shipment->carrier_name }}
                    @endif
                    @if ($shipment->reference)
                        · Ref {{ $shipment->reference }}
                    @endif
                    @if ($shipment->tracking_url)
                        · <a href="{{ $shipment->tracking_url }}" target="_blank" rel="noopener" class="text-accent hover:underline">Open tracking ↗</a>
                    @endif
                    @if (! empty($refreshStrategy) && $refreshStrategy !== 'none')
                        · refresh via <span class="font-medium text-ink">{{ $refreshStrategy }}</span>
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                @if (! $isManual)
                    <form method="POST" action="{{ route('shipments.refresh', $shipment) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium text-ink transition-colors hover:bg-canvas">
                            <x-dashboard.icon name="refresh" class="h-4 w-4" />
                            Refresh
                        </button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Flash --}}
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

        <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
            {{-- Left column: timeline --}}
            <div class="rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02] lg:col-span-2">
                <div class="border-b border-line p-5">
                    <h2 class="text-sm font-semibold text-ink">Tracking timeline</h2>
                    <p class="mt-0.5 text-xs text-muted">{{ $shipment->events->count() }} event{{ $shipment->events->count() === 1 ? '' : 's' }} on record.</p>
                </div>

                <ol class="divide-y divide-line">
                    @forelse ($shipment->events as $event)
                        <li class="flex gap-4 p-5">
                            <div class="flex flex-col items-center pt-1">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-canvas">
                                    <span class="h-2 w-2 rounded-full bg-ink"></span>
                                </span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-dashboard.courier-status-pill :status="$event->status" />
                                    <span class="text-xs tabular-nums text-muted">{{ $event->occurred_at->format('M j, Y · g:i A') }}</span>
                                </div>
                                @if ($event->location)
                                    <p class="mt-1 text-sm text-muted">{{ $event->location }}</p>
                                @endif
                                @if ($event->description)
                                    <p class="mt-1 text-sm text-ink">{{ $event->description }}</p>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="p-10 text-center">
                            <p class="text-sm font-medium text-ink">No events yet</p>
                            <p class="mt-1 text-xs text-muted">Refresh the shipment to pull the latest events from the courier.</p>
                        </li>
                    @endforelse
                </ol>

                {{-- Manual event form --}}
                @if ($isManual)
                    <div class="border-t border-line bg-canvas/30 p-5">
                        <h3 class="text-sm font-semibold text-ink">Record a new event</h3>
                        <form method="POST" action="{{ route('shipments.events.store', $shipment) }}" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            @csrf
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-muted">Status</label>
                                <select name="status" class="w-full rounded-lg border border-line bg-surface px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-muted">Occurred at</label>
                                <input type="datetime-local" name="occurred_at" class="w-full rounded-lg border border-line bg-surface px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-muted">Location</label>
                                <input name="location" class="w-full rounded-lg border border-line bg-surface px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-muted">Description</label>
                                <input name="description" class="w-full rounded-lg border border-line bg-surface px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                            </div>
                            <div class="sm:col-span-2">
                                <button type="submit" class="rounded-lg bg-ink px-3.5 py-2 text-xs font-medium text-surface transition-colors hover:bg-ink/90">Record event</button>
                            </div>
                        </form>
                    </div>
                @endif
            </div>

            {{-- Right column: details + order link --}}
            <div class="space-y-4">
                <div class="rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
                    <h2 class="text-sm font-semibold text-ink">Order</h2>
                    @if ($shipment->order)
                        <p class="mt-3 text-sm">
                            <a href="{{ route('orders.index', ['q' => $shipment->order->number]) }}" class="font-semibold text-ink hover:text-accent">
                                {{ $shipment->order->number }}
                            </a>
                        </p>
                        <p class="text-xs text-muted">{{ $shipment->order->customer?->name ?? '—' }}</p>
                        @if ($shipment->matched_method)
                            <p class="mt-2 text-xs text-muted">Matched via <span class="font-medium text-ink">{{ $shipment->matched_method }}</span> on {{ $shipment->matched_at?->format('M j, Y') }}.</p>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('shipments.rematch', $shipment) }}">
                                @csrf
                                <button type="submit" class="rounded-lg border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink transition-colors hover:bg-canvas">Re-match</button>
                            </form>
                            <form method="POST" action="{{ route('shipments.unlink', $shipment) }}">
                                @csrf
                                <button type="submit" class="rounded-lg border border-line bg-surface px-3 py-1.5 text-xs font-medium text-ink transition-colors hover:bg-canvas" onclick="return confirm('Unlink this shipment from order {{ $shipment->order->number }}?\n\nThe shipment will stay in the Shipments list but will no longer be associated with any order.');">Unlink</button>
                            </form>
                        </div>

                        {{-- Switch-order subsection. Clicking "Switch" reveals
                             the same typeahead the new-shipment form uses,
                             plus a confirmation checkbox that's only shown
                             when the picked order differs from the current
                             one. --}}
                        <details class="mt-4 rounded-lg border border-line bg-canvas/40 px-3 py-2 text-xs">
                            <summary class="cursor-pointer font-medium text-ink">Switch to a different order</summary>
                            <form method="POST" action="{{ route('shipments.link', $shipment) }}" class="mt-3 space-y-2" data-shipment-link-form>
                                @csrf
                                <div data-order-picker
                                    data-lookup-url="{{ route('shipments.lookup-orders') }}">
                                    <input type="hidden" name="order_id" value="{{ $shipment->order_id }}" data-order-picker-id />
                                    <input
                                        type="text"
                                        name="order_search"
                                        placeholder="Search by order #, customer name, email, or phone…"
                                        autocomplete="off"
                                        data-order-picker-input
                                        class="w-full rounded-lg border border-line bg-surface px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                                    <ul class="mt-2 hidden divide-y divide-line rounded-lg border border-line bg-surface shadow-sm shadow-ink/[0.02]" data-order-picker-results role="listbox"></ul>
                                    <label class="mt-2 hidden items-start gap-2 rounded-lg border border-line bg-warning-soft px-3 py-2" data-switch-confirm data-current-order-id="{{ $shipment->order_id }}">
                                        <input type="checkbox" name="confirm" value="1" class="mt-0.5 h-4 w-4 rounded border-line-strong accent-ink" />
                                        <span>
                                            <span class="font-medium text-ink">Confirm switching</span>
                                            <span class="block text-muted">This will move the shipment away from <span class="font-medium text-ink">{{ $shipment->order->number }}</span> to the order you pick above. The auto-matcher won't undo it.</span>
                                        </span>
                                    </label>
                                </div>
                                <button type="submit" class="rounded-lg bg-ink px-3 py-1.5 text-xs font-medium text-surface transition-colors hover:bg-ink/90">Switch</button>
                            </form>
                        </details>
                    @else
                        <p class="mt-3 text-sm text-muted">This shipment is not linked to an order.</p>
                        <form method="POST" action="{{ route('shipments.link', $shipment) }}" class="mt-3 space-y-2" data-shipment-link-form>
                            @csrf
                            <div data-order-picker
                                data-lookup-url="{{ route('shipments.lookup-orders') }}"
                                data-current-order-id="">
                                <input type="hidden" name="order_id" value="" data-order-picker-id />
                                <input
                                    type="text"
                                    name="order_search"
                                    placeholder="Search by order #, customer name, email, or phone…"
                                    autocomplete="off"
                                    data-order-picker-input
                                    class="w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                                <ul class="mt-2 hidden divide-y divide-line rounded-lg border border-line bg-surface shadow-sm shadow-ink/[0.02]" data-order-picker-results role="listbox"></ul>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                                <button type="submit" class="rounded-lg bg-ink px-3 py-2 text-xs font-medium text-surface transition-colors hover:bg-ink/90">Link</button>
                                <form method="POST" action="{{ route('shipments.rematch', $shipment) }}" class="contents">
                                    @csrf
                                    <button type="submit" class="text-xs font-medium text-muted transition-colors hover:text-ink">Or try auto-match</button>
                                </form>
                            </div>
                        </form>
                    @endif
                </div>

                <div class="rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
                    <h2 class="text-sm font-semibold text-ink">Consignee</h2>
                    <div class="mt-3 space-y-1 text-sm">
                        <p class="font-medium text-ink">{{ $shipment->consignee_name ?? '—' }}</p>
                        <p class="text-muted">{{ $shipment->consignee_phone ?? '' }}</p>
                        <p class="text-muted">{{ $shipment->consignee_email ?? '' }}</p>
                        <p class="text-muted">{{ $shipment->consignee_address ?? '' }}</p>
                        <p class="text-muted">{{ $shipment->consignee_city ?? '' }}</p>
                    </div>
                </div>

                <div class="rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
                    <h2 class="text-sm font-semibold text-ink">Parcel</h2>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-muted">Weight</dt>
                            <dd class="mt-0.5 font-medium text-ink tabular-nums">
                                @if ($shipment->weight_kg !== null)
                                    {{ rtrim(rtrim(number_format((float) $shipment->weight_kg, 3), '0'), '.') }} kg
                                @elseif ($shipment->effectiveWeightKg() !== null)
                                    {{ rtrim(rtrim(number_format($shipment->effectiveWeightKg(), 3), '0'), '.') }} kg
                                    <span class="ml-1 inline-flex rounded-full bg-accent-soft px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-accent">from order</span>
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted">Pieces</dt>
                            <dd class="mt-0.5 font-medium text-ink tabular-nums">{{ $shipment->pieces ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted">COD</dt>
                            <dd class="mt-0.5 font-medium text-ink tabular-nums">
                                {{ $shipment->cod_amount !== null ? format_money((float) $shipment->cod_amount) : '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted">Shipping cost</dt>
                            <dd class="mt-0.5 font-medium text-ink tabular-nums">
                                @if ($shipment->effectiveCost() !== null)
                                    {{ format_money($shipment->effectiveCost()) }}
                                    @if ($shipment->costIsEstimated())
                                        <span class="ml-1 inline-flex rounded-full bg-accent-soft px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-accent">estimated</span>
                                    @endif
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted">Net (COD − cost)</dt>
                            <dd class="mt-0.5 font-medium tabular-nums {{ $shipment->cod_amount !== null && $shipment->effectiveCost() !== null && (float) $shipment->cod_amount - $shipment->effectiveCost() < 0 ? 'text-negative' : 'text-ink' }}">
                                @if ($shipment->cod_amount !== null && $shipment->effectiveCost() !== null)
                                    {{ format_money((float) $shipment->cod_amount - $shipment->effectiveCost()) }}
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted">Shipped at</dt>
                            <dd class="mt-0.5 font-medium text-ink tabular-nums">{{ $shipment->shipped_at?->format('M j, Y · g:i A') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted">Delivered at</dt>
                            <dd class="mt-0.5 font-medium text-ink tabular-nums">{{ $shipment->delivered_at?->format('M j, Y · g:i A') ?? '—' }}</dd>
                        </div>
                    </dl>

                    {{-- Money fields can arrive null from every source (the
                         courier hasn't billed, Shopify carries no cost, the
                         operator pasted a bare tracking number). This editor
                         lets the operator backfill cost / COD so the Audit
                         report always has the full picture. --}}
                    <details class="mt-4" data-shipment-money-editor>
                        <summary class="inline-flex cursor-pointer list-none items-center gap-1 rounded-md border border-line bg-canvas px-2 py-1 text-xs font-medium text-muted transition-colors hover:text-ink">
                            <x-dashboard.icon name="edit" class="h-3.5 w-3.5" />
                            Edit pricing &amp; costs
                        </summary>
                        <form method="POST" action="{{ route('shipments.money', $shipment) }}" class="mt-3 grid grid-cols-2 gap-3 rounded-lg border border-line bg-canvas/40 p-3">
                            @csrf
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-muted">Shipping cost</label>
                                <input name="cost" type="number" step="0.01" min="0"
                                    value="{{ $shipment->cost !== null ? (float) $shipment->cost : '' }}"
                                    placeholder="{{ $shipment->cost === null && $shipment->estimatedCost() !== null ? '~'.number_format($shipment->estimatedCost(), 2).' estimated' : '' }}"
                                    class="w-full rounded-lg border border-line bg-surface px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-muted">COD amount</label>
                                <input name="cod_amount" type="number" step="0.01" min="0"
                                    value="{{ $shipment->cod_amount !== null ? (float) $shipment->cod_amount : '' }}"
                                    class="w-full rounded-lg border border-line bg-surface px-2.5 py-2 text-sm text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-muted">Currency</label>
                                <input name="currency" maxlength="3"
                                    value="{{ $shipment->currency ?? 'PKR' }}"
                                    class="w-full rounded-lg border border-line bg-surface px-2.5 py-2 text-sm uppercase text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                            </div>
                            <div class="flex items-end">
                                <button type="submit" class="rounded-lg bg-ink px-3 py-2 text-xs font-medium text-surface transition-colors hover:bg-ink/90">Save</button>
                            </div>
                            <p class="col-span-2 text-[11px] text-muted">Leave a field blank to clear it. These figures feed the courier-cost and COD rows on the Audit report.</p>
                        </form>
                    </details>
                </div>
            </div>
        </div>
    </div>
@endsection
