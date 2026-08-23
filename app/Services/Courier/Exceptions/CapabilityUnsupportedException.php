<?php

namespace App\Services\Courier\Exceptions;

use App\Enums\Courier\Capability;
use RuntimeException;

/**
 * Thrown when a caller asks a provider for an action that provider does not
 * support. Both the registry and the controllers should catch this and return
 * a 4xx response; the UI should already have hidden the action because the
 * capability flag is missing.
 */
class CapabilityUnsupportedException extends RuntimeException
{
    public function __construct(
        public readonly string $providerKey,
        public readonly Capability $capability,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? sprintf(
                'Courier provider "%s" does not support capability "%s".',
                $providerKey,
                $capability->label(),
            ),
        );
    }
}
