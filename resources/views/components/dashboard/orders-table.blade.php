@props(['orders' => []])

<div class="overflow-x-auto">
    <table class="w-full min-w-[620px] text-left text-sm">
        <thead>
            <tr class="border-b border-line text-[11px] uppercase tracking-[0.1em] text-faint">
                <th class="pb-3 pr-4 font-medium">Order</th>
                <th class="pb-3 pr-4 font-medium">Customer</th>
                <th class="pb-3 pr-4 font-medium">Status</th>
                <th class="pb-3 pr-4 font-medium">Date</th>
                <th class="pb-3 text-right font-medium">Total</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-line">
            @forelse ($orders as $order)
                @php
                    $name = $order->customer?->name ?? 'Guest';
                    $initials = strtoupper(
                        collect(explode(' ', $name))
                            ->take(2)
                            ->map(fn ($part) => mb_substr($part, 0, 1))
                            ->join(''),
                    );
                @endphp
                <tr class="group">
                    <td class="py-3.5 pr-4 font-medium text-ink">{{ $order->number }}</td>
                    <td class="py-3.5 pr-4">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-canvas text-[11px] font-semibold text-muted">{{ $initials }}</span>
                            <div class="min-w-0">
                                <p class="truncate font-medium text-ink">{{ $name }}</p>
                                <p class="truncate text-xs text-muted">{{ $order->customer?->email }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="py-3.5 pr-4">
                        <x-dashboard.status-pill :status="$order->status" />
                    </td>
                    <td class="py-3.5 pr-4 tabular-nums text-muted">{{ $order->created_at->format('M j, Y') }}</td>
                    <td class="py-3.5 text-right font-semibold tabular-nums text-ink">${{ number_format((float) $order->total, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="py-10 text-center text-sm text-muted">No orders yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
