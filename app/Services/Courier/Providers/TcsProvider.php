<?php

namespace App\Services\Courier\Providers;

use App\Enums\Courier\Capability;
use App\Models\CourierProvider as CourierProviderModel;
use App\Services\Courier\AbstractCourierProvider;
use App\Services\Courier\Exceptions\CourierException;

/**
 * TCS (Pakistan) integration skeleton. TCS's read API is gated behind an
 * enterprise account; this provider ships the shape so the registry, sync
 * engine, and UI all work end-to-end, but listShipments / getShipment throw
 * until credentials + a confirmed endpoint are in.
 *
 * Once an account is wired, fill in callTrack() with the actual TCS endpoint
 * (typically their REST Tracking Service) and the read flow lights up.
 */
class TcsProvider extends AbstractCourierProvider
{
    public function __construct(
        private readonly CourierProviderModel $config,
    ) {
    }

    public function key(): string
    {
        return 'tcs';
    }

    public function displayName(): string
    {
        return 'TCS';
    }

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return [
            Capability::ReadShipments,
            Capability::ReadEvents,
            // Capability::CreateLabel,            // phase 2 — TCS Booking API
            // Capability::CancelShipment,         // phase 2
            // Capability::SchedulePickup,         // phase 2
            // Capability::RateQuote,              // phase 2
            Capability::CodSupport,
        ];
    }

    public function getShipment(string $externalId): never
    {
        $this->requireCapability(Capability::ReadShipments);
        throw new CourierException(
            'TCS read API is not yet wired. Set up the TCS Tracking Service account, then implement callTrack() in TcsProvider.',
        );
    }
}
