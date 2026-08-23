<?php

namespace App\Services\Courier;

use App\Services\Courier\Normalization\Address;
use Illuminate\Support\Carbon;

/**
 * Phase-2: schedule a courier pickup at a given address.
 */
final class PickupRequest
{
    /**
     * @param  list<string>  $shipmentExternalIds  shipments to include in the pickup
     */
    public function __construct(
        public readonly Address $pickupAddress,
        public readonly Carbon $pickupAt,
        public readonly array $shipmentExternalIds = [],
    ) {
    }
}
