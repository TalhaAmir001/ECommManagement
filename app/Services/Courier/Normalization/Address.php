<?php

namespace App\Services\Courier\Normalization;

/**
 * Plain value object for a person + address. Used by RawShipment so providers
 * can hand us structured data without depending on Eloquent.
 */
final class Address
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $phone = null,
        public readonly ?string $address = null,
        public readonly ?string $city = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->name === null
            && $this->phone === null
            && $this->address === null
            && $this->city === null;
    }
}
