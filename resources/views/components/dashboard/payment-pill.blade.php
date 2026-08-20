@props(['status' => null])

@php
    $styles = [
        'PAID' => 'bg-positive-soft text-positive ring-positive/20',
        'PENDING' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'AUTHORIZED' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        'PARTIALLY_PAID' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        'PARTIALLY_REFUNDED' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'REFUNDED' => 'bg-negative-soft text-negative ring-negative/20',
        'VOIDED' => 'bg-negative-soft text-negative ring-negative/20',
    ];
    $dots = [
        'PAID' => 'bg-positive',
        'PENDING' => 'bg-amber-500',
        'AUTHORIZED' => 'bg-sky-500',
        'PARTIALLY_PAID' => 'bg-sky-500',
        'PARTIALLY_REFUNDED' => 'bg-amber-500',
        'REFUNDED' => 'bg-negative',
        'VOIDED' => 'bg-negative',
    ];
    $label = $status ? ucwords(strtolower(str_replace('_', ' ', $status))) : '—';
@endphp

<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $styles[$status] ?? 'bg-canvas text-muted ring-line' }}">
    <span class="h-1.5 w-1.5 rounded-full {{ $dots[$status] ?? 'bg-line' }}"></span>
    {{ $label }}
</span>
