@props(['products' => []])

<ul class="space-y-3">
    @forelse ($products as $product)
        <li class="flex items-center gap-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-negative-soft text-negative">
                <x-dashboard.icon name="alert-triangle" class="h-4 w-4" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">{{ $product->name }}</p>
                <p class="truncate text-xs text-muted">{{ $product->sku }}</p>
            </div>
            <span class="shrink-0 rounded-full bg-negative-soft px-2 py-0.5 text-xs font-semibold text-negative">{{ $product->stock }} left</span>
        </li>
    @empty
        <li class="py-8 text-center text-sm text-muted">All products are well stocked.</li>
    @endforelse
</ul>
