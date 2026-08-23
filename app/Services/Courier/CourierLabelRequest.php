<?php

namespace App\Services\Courier;

use App\Models\Order;
use App\Services\Courier\Normalization\Address;

/**
 * What the caller hands to CourierProvider::createLabel() in phase 2.
 * Declared today so the interface stays stable.
 */
final class CourierLabelRequest
{
    public function __construct(
        public readonly Order $order,
        public readonly Address $consignor,
        public readonly Address $consignee,
        public readonly float $weightKg,
        public readonly int $pieces = 1,
        public readonly ?float $codAmount = null,
        public readonly ?string $description = null,
    ) {
    }
}
