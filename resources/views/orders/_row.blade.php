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
    <td class="py-3.5 pr-4 text-right">
        <span class="font-semibold tabular-nums text-ink">${{ number_format((float) $order->total, 2) }}</span>
    </td>
    <td class="py-3.5 pr-4 text-right tabular-nums text-muted">
        {{ $order->items_count }} <span class="text-xs text-faint">item{{ $order->items_count === 1 ? '' : 's' }}</span>
    </td>
</tr>
