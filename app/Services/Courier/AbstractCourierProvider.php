<?php

namespace App\Services\Courier;

use App\Enums\Courier\Capability;
use App\Services\Courier\Exceptions\CapabilityUnsupportedException;
use App\Services\Courier\Exceptions\CourierException;
use App\Services\Courier\Normalization\RawEvent;
use App\Services\Courier\Normalization\RawShipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;

/**
 * Common implementation of the read-only no-op fallbacks so concrete
 * providers only have to override what they actually support. The write
 * methods throw CapabilityUnsupportedException by default; a provider flips
 * a capability flag in its capabilities() list and the UI starts showing
 * the corresponding button — no schema or interface change required.
 */
abstract class AbstractCourierProvider implements CourierProvider
{
    public function supports(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function listShipments(?Carbon $since = null): LazyCollection
    {
        $this->requireCapability(Capability::ReadShipments);

        return LazyCollection::make();
    }

    public function listEvents(string $externalId, ?Carbon $since = null): LazyCollection
    {
        $this->requireCapability(Capability::ReadEvents);

        return LazyCollection::make();
    }

    public function getShipment(string $externalId): ?RawShipment
    {
        $this->requireCapability(Capability::ReadShipments);

        return null;
    }

    public function createLabel(CourierLabelRequest $request): RawShipment
    {
        $this->requireCapability(Capability::CreateLabel);
        throw new CourierException('createLabel() reached base implementation — provider should override.');
    }

    public function cancelShipment(string $externalId, ?string $reason = null): bool
    {
        $this->requireCapability(Capability::CancelShipment);
        throw new CourierException('cancelShipment() reached base implementation — provider should override.');
    }

    public function schedulePickup(PickupRequest $request): RawPickupConfirmation
    {
        $this->requireCapability(Capability::SchedulePickup);
        throw new CourierException('schedulePickup() reached base implementation — provider should override.');
    }

    public function rateQuote(RateQuoteRequest $request): array
    {
        $this->requireCapability(Capability::RateQuote);
        throw new CourierException('rateQuote() reached base implementation — provider should override.');
    }

    /**
     * Throw a CapabilityUnsupportedException unless the provider advertises
     * the capability. The Capability enum value is the canonical key the
     * UI also checks.
     */
    protected function requireCapability(Capability $capability): void
    {
        if (! $this->supports($capability)) {
            throw new CapabilityUnsupportedException($this->key(), $capability);
        }
    }
}
