<?php

namespace App\Services\Courier;

use App\Services\Courier\Normalization\Address;

/**
 * Phase-2: ask a courier what they would charge to ship a parcel between
 * two addresses. The result is a list of RateQuote instances the caller can
 * display side-by-side.
 */
final class RateQuoteRequest
{
    public function __construct(
        public readonly Address $origin,
        public readonly Address $destination,
        public readonly float $weightKg,
        public readonly int $pieces = 1,
        public readonly ?float $codAmount = null,
    ) {
    }
}
