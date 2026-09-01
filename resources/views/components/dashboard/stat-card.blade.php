@props([
    'label' => 'Metric',
    'value' => 0,
    'format' => 'currency',
    'delta' => 0,
    'spark' => [],
    'icon' => 'dashboard',
])

@php
    $display = $format === 'currency'
        ? format_money((float) $value, 2)
        : number_format((float) $value, 0);

    $positive = $delta > 0;
    $negative = $delta < 0;

    $trendClass = $positive ? 'bg-positive-soft text-positive' : ($negative ? 'bg-negative-soft text-negative' : 'bg-canvas text-muted');
    $trendIcon = $positive ? 'trending-up' : ($negative ? 'trending-down' : 'trending-up');
    $trendText = ($delta > 0 ? '+' : '') . number_format(abs($delta), 1) . '%';

    $points = '';
    $count = count($spark);
    if ($count > 1) {
        $max = max($spark) ?: 1;
        $min = min($spark);
        $range = ($max - $min) ?: 1;
        $w = 100;
        $h = 34;
        $pad = 2;
        foreach ($spark as $i => $v) {
            $x = $pad + ($i * ($w - 2 * $pad) / ($count - 1));
            $y = $h - $pad - (($v - $min) / $range) * ($h - 2 * $pad);
            $points .= ($i === 0 ? '' : ' ') . number_format($x, 1, '.', '') . ',' . number_format($y, 1, '.', '');
        }
    }
    $sparkClass = $positive ? 'text-positive' : ($negative ? 'text-negative' : 'text-accent');
@endphp

<div class="rounded-xl border border-line bg-surface p-5">
    <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-2.5">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-canvas text-muted">
                <x-dashboard.icon name="{{ $icon }}" class="h-4.5 w-4.5" />
            </span>
            <span class="text-sm font-medium text-muted">{{ $label }}</span>
        </div>
        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $trendClass }}">
            <x-dashboard.icon name="{{ $trendIcon }}" class="h-3 w-3" />
            {{ $trendText }}
        </span>
    </div>

    <div class="mt-4 flex items-end justify-between gap-3">
        <p class="text-[26px] font-semibold leading-none tracking-tight text-ink">{{ $display }}</p>
        @if ($count > 1)
            <svg viewBox="0 0 100 34" preserveAspectRatio="none" class="h-8 w-24 shrink-0 {{ $sparkClass }}" aria-hidden="true">
                <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="1.5"
                    stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
            </svg>
        @endif
    </div>
</div>
