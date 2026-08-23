<?php

namespace App\Services\Courier\Exceptions;

use RuntimeException;

/**
 * Thrown by provider implementations for transport / auth / response-shape
 * problems. The sync engine catches this and records last_sync_error on the
 * provider row so the admin UI can surface it.
 */
class CourierException extends RuntimeException
{
}
