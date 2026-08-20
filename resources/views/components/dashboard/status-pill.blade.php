@props(['status' => 'pending'])

@php
    $styles = [
        'pending' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'processing' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        'shipped' => 'bg-violet-50 text-violet-700 ring-violet-600/20',
        'delivered' => 'bg-positive-soft text-positive ring-positive/20',
        'cancelled' => 'bg-negative-soft text-negative ring-negative/20',
    ];
    $dots = [
        'pending' => 'bg-amber-500',
        'processing' => 'bg-sky-500',
        'shipped' => 'bg-violet-500',
        'delivered' => 'bg-positive',
        'cancelled' => 'bg-negative',
    ];
    $class = $styles[$status] ?? $styles['pending'];
    $dot = $dots[$status] ?? $dots['pending'];
@endphp

<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $class }}">
    <span class="h-1.5 w-1.5 rounded-full {{ $dot }}"></span>
    {{ ucfirst($status) }}
</span>
