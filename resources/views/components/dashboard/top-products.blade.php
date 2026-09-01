@props(['products' => []])

<ul class="divide-y divide-line">
    @forelse ($products as $i => $product)
        <li class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
            <span class="w-5 shrink-0 text-sm font-medium text-faint">{{ $i + 1 }}</span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">{{ $product['name'] }}</p>
                <p class="truncate text-xs text-muted">{{ $product['category'] }} · {{ $product['units'] }} sold</p>
            </div>
            <span class="shrink-0 text-sm font-semibold tabular-nums text-ink">{{ format_money($product['revenue'], 2) }}</span>
        </li>
    @empty
        <li class="py-8 text-center text-sm text-muted">No sales in this period yet.</li>
    @endforelse
</ul>
