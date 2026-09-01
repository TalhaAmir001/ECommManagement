@props(['data' => []])

@php
    $max = $data ? max($data) : 1;
    $money = fn ($v) => compact_money($v);
@endphp

<div class="space-y-4">
    @forelse ($data as $category => $value)
        @php($pct = round(($value / $max) * 100))
        <div>
            <div class="mb-1.5 flex items-center justify-between text-sm">
                <span class="font-medium text-ink">{{ $category }}</span>
                <span class="tabular-nums text-muted">{{ $money($value) }}</span>
            </div>
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-canvas">
                <div class="h-full rounded-full bg-accent" style="width: {{ $pct }}%"></div>
            </div>
        </div>
    @empty
        <p class="text-sm text-muted">No sales in this period yet.</p>
    @endforelse
</div>
