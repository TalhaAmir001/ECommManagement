<?php

namespace App\Enums\Courier;

/**
 * The lifecycle of a shipment, in the vocabulary we use everywhere in the
 * app. Each provider maps its own statuses onto this enum in its normalizer;
 * unmappable statuses fall back to Unknown so nothing crashes on the way in.
 */
enum ShipmentStatus: string
{
    case Created = 'created';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Exception = 'exception';
    case Returned = 'returned';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    /**
     * Terminal statuses never transition further.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::Returned, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * A short, presentation-friendly label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::PickedUp => 'Picked up',
            self::InTransit => 'In transit',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Exception => 'Exception',
            self::Returned => 'Returned',
            self::Cancelled => 'Cancelled',
            self::Unknown => 'Unknown',
        };
    }
}
