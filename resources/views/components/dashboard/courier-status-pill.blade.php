@props(['status' => null])

@php
    $enum = $status instanceof \App\Enums\Courier\ShipmentStatus
        ? $status
        : \App\Enums\Courier\ShipmentStatus::tryFrom((string) $status);
    $value = $enum?->value ?? 'unknown';
    $label = $enum?->label() ?? 'Unknown';

    // Same 4-tier system as the rest of the dashboard: green for good,
    // orange for "watch it", red for problems, neutral for everything else.
    $class = match ($value) {
        'delivered', 'picked_up' => 'bg-positive-soft text-positive',
        'in_transit', 'out_for_delivery' => 'bg-accent-soft text-accent',
        'exception', 'returned', 'cancelled' => 'bg-negative-soft text-negative',
        default => 'bg-canvas text-muted',
    };
@endphp

<span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $class }}">
    <span class="h-1.5 w-1.5 rounded-full bg-current opacity-70"></span>
    {{ $label }}
</span>
