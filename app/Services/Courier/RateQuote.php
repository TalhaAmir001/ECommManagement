<?php

namespace App\Services\Courier;

/**
 * A single price from a rate-shopping call. Currency comes from the
 * provider; amount is the total charge (no extra fees).
 */
final class RateQuote
{
    public function __construct(
        public readonly string $serviceCode,
        public readonly string $serviceName,
        public readonly float $amount,
        public readonly string $currency = 'PKR',
        public readonly ?int $estimatedDays = null,
    ) {
    }
}
