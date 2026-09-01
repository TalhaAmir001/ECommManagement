@props(['series' => []])

@php
    $n = count($series);
    $values = array_column($series, 'value');
    $max = $values ? max($values) : 0;
    $niceMax = $max > 0 ? $max * 1.2 : 100;

    $w = 800;
    $h = 260;
    $pad = 6;
    $innerW = $w - 2 * $pad;
    $innerH = $h - 2 * $pad;

    $pts = [];
    foreach ($series as $i => $s) {
        $x = $pad + ($n > 1 ? $i * $innerW / ($n - 1) : $innerW / 2);
        $y = $h - $pad - (($s['value'] / $niceMax) * $innerH);
        $pts[] = [$x, $y];
    }

    $line = '';
    if ($n > 0) {
        $line = implode(' ', array_map(fn ($p) => number_format($p[0], 1, '.', '') . ',' . number_format($p[1], 1, '.', ''), $pts));
        $area = $line
            . ' ' . number_format($pts[$n - 1][0], 1, '.', '') . ',' . ($h - $pad)
            . ' ' . number_format($pts[0][0], 1, '.', '') . ',' . ($h - $pad);
    }

    $money = fn ($v) => compact_money($v);

    $firstLabel = $n > 0 ? $series[0]['label'] : '';
    $midLabel = $n > 1 ? $series[intdiv($n - 1, 2)]['label'] : '';
    $lastLabel = $n > 1 ? $series[$n - 1]['label'] : '';
@endphp

<div class="relative">
    {{-- Y-axis labels --}}
    <div class="absolute inset-y-0 left-0 flex w-11 flex-col justify-between py-1 text-right">
        <span class="text-[11px] tabular-nums text-faint">{{ $money($niceMax) }}</span>
        <span class="text-[11px] tabular-nums text-faint">{{ $money($niceMax / 2) }}</span>
        <span class="text-[11px] tabular-nums text-faint">{{ currency_symbol() }}0</span>
    </div>

    {{-- Plot --}}
    <div class="pl-11">
        <svg viewBox="0 0 800 260" preserveAspectRatio="none" class="h-56 w-full" aria-hidden="true">
            <defs>
                <linearGradient id="area-fill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#ff750f" stop-opacity="0.16" />
                    <stop offset="100%" stop-color="#ff750f" stop-opacity="0" />
                </linearGradient>
            </defs>

            {{-- Gridlines --}}
            @foreach ([0, 0.25, 0.5, 0.75, 1] as $step)
                @php($gy = $h - $pad - $step * $innerH)
                <line x1="{{ $pad }}" y1="{{ $gy }}" x2="{{ $w - $pad }}" y2="{{ $gy }}"
                    stroke="#e9e7e1" stroke-width="1" vector-effect="non-scaling-stroke" />
            @endforeach

            @if ($n > 1)
                <polygon points="{{ $area }}" fill="url(#area-fill)" />
                <polyline points="{{ $line }}" fill="none" stroke="#ff750f" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
            @endif
        </svg>

        {{-- X-axis labels --}}
        <div class="mt-1.5 flex justify-between text-[11px] text-faint">
            <span>{{ $firstLabel }}</span>
            <span>{{ $midLabel }}</span>
            <span>{{ $lastLabel }}</span>
        </div>
    </div>
</div>
