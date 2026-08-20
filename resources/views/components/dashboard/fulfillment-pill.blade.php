@props(['status' => null])

@php
    $styles = [
        'FULFILLED' => 'bg-positive-soft text-positive ring-positive/20',
        'UNFULFILLED' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'PARTIALLY_FULFILLED' => 'bg-violet-50 text-violet-700 ring-violet-600/20',
        'SCHEDULED' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        'ON_HOLD' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
    ];
    $dots = [
        'FULFILLED' => 'bg-positive',
        'UNFULFILLED' => 'bg-amber-500',
        'PARTIALLY_FULFILLED' => 'bg-violet-500',
        'SCHEDULED' => 'bg-sky-500',
        'ON_HOLD' => 'bg-amber-500',
    ];
    $label = $status ? ucwords(strtolower(str_replace('_', ' ', $status))) : '—';
@endphp

<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $styles[$status] ?? 'bg-canvas text-muted ring-line' }}">
    <span class="h-1.5 w-1.5 rounded-full {{ $dots[$status] ?? 'bg-line' }}"></span>
    {{ $label }}
</span>
