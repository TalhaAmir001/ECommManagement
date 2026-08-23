<?php

namespace App\Services\Courier;

use Illuminate\Support\Carbon;

/**
 * Provider-agnostic confirmation that a pickup was scheduled.
 */
final class RawPickupConfirmation
{
    public function __construct(
        public readonly string $confirmationCode,
        public readonly Carbon $pickupAt,
        public readonly array $raw = [],
    ) {
    }
}
