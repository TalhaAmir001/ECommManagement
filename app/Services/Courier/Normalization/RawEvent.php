<?php

namespace App\Services\Courier\Normalization;

use App\Enums\Courier\ShipmentStatus;
use Illuminate\Support\Carbon;

/**
 * Provider-agnostic tracking event. Multiple of these are appended to a
 * shipment in chronological order. The unique constraint on
 * (shipment_id, occurred_at, location) makes the append idempotent.
 */
final class RawEvent
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?Carbon $occurredAt = null,
        public readonly ShipmentStatus $status = ShipmentStatus::Unknown,
        public readonly ?string $location = null,
        public readonly ?string $description = null,
        public readonly array $raw = [],
    ) {
    }
}
