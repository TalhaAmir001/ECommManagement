<?php

namespace App\Services\Courier\Normalization;

use App\Enums\Courier\ShipmentStatus;
use Illuminate\Support\Carbon;

/**
 * Provider-agnostic shipment DTO. Each provider's normalizer produces one of
 * these; the sync engine upserts it into the shipments table. The original
 * raw payload is preserved in $raw for re-normalization and debugging.
 */
final class RawShipment
{
    /**
     * @param  array<string, mixed>  $raw  the original provider response for this shipment
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $trackingNumber,
        public readonly ?string $reference = null,
        public readonly ShipmentStatus $status = ShipmentStatus::Unknown,
        public readonly ?string $statusDetail = null,
        public readonly ?Carbon $shippedAt = null,
        public readonly ?Carbon $deliveredAt = null,
        public readonly ?Carbon $lastEventAt = null,
        public readonly ?Address $consignor = null,
        public readonly ?Address $consignee = null,
        public readonly ?float $weightKg = null,
        public readonly ?int $pieces = null,
        public readonly ?float $codAmount = null,
        public readonly ?float $cost = null,
        public readonly string $currency = 'PKR',
        public readonly array $raw = [],
    ) {
    }
}
