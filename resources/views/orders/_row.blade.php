@php
    $customerName = $order->customer?->name ?? 'Guest';
    $initials = strtoupper(collect(explode(' ', $customerName))->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->join(''));
@endphp
<tr
    class="group transition-colors hover:bg-canvas/60"
    data-order-id="{{ $order->id }}"
    data-shopify-id="{{ $order->shopify_id ?? '' }}"
    data-updated-at="{{ $order->updated_at?->toIso8601String() }}"
>
    <td class="py-3.5 pl-5 pr-2">
        <input type="checkbox" class="h-4 w-4 rounded border-line-strong accent-ink" aria-label="Select {{ $order->number }}" />
    </td>
    <td class="py-3.5 pr-4">
        <p class="font-semibold tabular-nums tracking-tight text-ink">{{ $order->number }}</p>
    </td>
    <td class="whitespace-nowrap py-3.5 pr-4 tabular-nums text-muted">{{ $order->created_at->format('M j, Y · g:i A') }}</td>
    <td class="py-3.5 pr-4">
        <div class="flex items-center gap-2.5">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-soft text-[11px] font-semibold text-accent">{{ $initials }}</span>
            <div class="min-w-0">
                <p class="truncate font-medium text-ink">{{ $customerName }}</p>
                <p class="truncate text-xs text-muted">{{ $order->customer?->email }}</p>
            </div>
        </div>
    </td>
    <td class="py-3.5 pr-4">
        <x-dashboard.payment-pill :status="$order->financial_status" />
    </td>
    <td class="py-3.5 pr-4">
        <x-dashboard.fulfillment-pill :status="$order->fulfillment_status" />
    </td>
    <td class="py-3.5 pr-4">
        @php
            $latestShipment = $order->shipments->first();
            $shipmentCount = (int) ($order->shipments_count ?? $order->shipments->count());
            $assignedProvider = $order->assignedProvider;
        @endphp
        @if ($latestShipment)
            <a href="{{ route('shipments.show', $latestShipment) }}" class="font-medium text-ink hover:text-accent tabular-nums">
                {{ $latestShipment->tracking_number }}
            </a>
            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                <x-dashboard.courier-status-pill :status="$latestShipment->status" />
                @if ($shipmentCount > 1)
                    <span class="text-[11px] text-muted">+{{ $shipmentCount - 1 }} more</span>
                @endif
            </div>
            @if ($latestShipment->matched_method)
                <p class="mt-1 text-[11px] text-muted">
                    @if ($latestShipment->matched_method === 'manual')
                        <span class="font-medium text-ink">Linked</span> · manual
                    @else
                        <span class="font-medium text-ink">Linked</span> · {{ $latestShipment->matched_method }}
                    @endif
                </p>
            @endif
        @elseif ($assignedProvider)
            <span class="inline-flex items-center gap-1 rounded-md border border-line bg-canvas px-2 py-0.5 text-xs font-medium text-muted">
                <x-dashboard.icon name="truck" class="h-3 w-3" />
                Will ship via {{ $assignedProvider->display_name }}
            </span>
            <p class="mt-1 text-[11px] text-muted">No tracking # yet</p>
        @else
            <span class="text-xs text-faint">Not shipped</span>
        @endif

        {{-- Quick actions. A <details> keeps the row tight when collapsed
             and reveals the action form in-place when needed. Each form
             posts to its own dedicated endpoint so the row can stay
             focused on display, not editing. --}}
        <details class="mt-2 text-xs" data-order-quick-actions>
            <summary class="inline-flex cursor-pointer list-none items-center gap-1 rounded-md border border-line bg-canvas px-2 py-0.5 font-medium text-muted transition-colors hover:text-ink">
                <x-dashboard.icon name="plus" class="h-3 w-3" />
                Add tracking / assign
            </summary>
            <div class="mt-2 space-y-2 rounded-lg border border-line bg-canvas/40 p-2">
                <form method="POST" action="{{ route('orders.add-tracking', $order) }}" class="flex flex-wrap items-center gap-1.5">
                    @csrf
                    <input name="tracking_number" required placeholder="Tracking #" class="flex-1 min-w-[8rem] rounded-md border border-line bg-surface px-2 py-1 text-xs text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                    <input name="carrier_name" placeholder="Carrier (optional)" class="w-28 rounded-md border border-line bg-surface px-2 py-1 text-xs text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                    <input name="cost" type="number" step="0.01" min="0" placeholder="Cost" title="Courier cost (optional)" class="w-16 rounded-md border border-line bg-surface px-2 py-1 text-xs text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                    <input name="cod_amount" type="number" step="0.01" min="0" placeholder="COD" title="COD amount (optional)" class="w-16 rounded-md border border-line bg-surface px-2 py-1 text-xs text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
                    <button type="submit" class="rounded-md bg-ink px-2.5 py-1 text-[11px] font-medium text-surface transition-colors hover:bg-ink/90">Add</button>
                </form>
                <form method="POST" action="{{ route('orders.assign-provider', $order) }}" class="flex flex-wrap items-center gap-1.5">
                    @csrf
                    <label class="text-[11px] text-muted">Will ship via</label>
                    <select name="courier_provider_id" class="rounded-md border border-line bg-surface px-2 py-1 text-xs text-ink focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10">
                        <option value="0" @selected($assignedProvider === null)>(none)</option>
                        @isset($allProviders)
                            @foreach ($allProviders as $provider)
                                <option value="{{ $provider->id }}" @selected($assignedProvider?->id === $provider->id)>{{ $provider->display_name }}</option>
                            @endforeach
                        @endisset
                    </select>
                    <button type="submit" class="rounded-md border border-line bg-surface px-2.5 py-1 text-[11px] font-medium text-ink transition-colors hover:bg-canvas">Save</button>
                </form>
            </div>
        </details>
    </td>
    <td class="py-3.5 pr-4 text-right">
        <span class="font-semibold tabular-nums text-ink">{{ format_money((float) $order->total, 2) }}</span>
    </td>
    <td class="py-3.5 pr-4 text-right tabular-nums text-muted">
        {{ $order->items_count }} <span class="text-xs text-faint">item{{ $order->items_count === 1 ? '' : 's' }}</span>
    </td>
</tr>
