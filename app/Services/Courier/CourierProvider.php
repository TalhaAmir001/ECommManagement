<?php

namespace App\Services\Courier;

use App\Enums\Courier\Capability;
use App\Services\Courier\Exceptions\CapabilityUnsupportedException;
use App\Services\Courier\Normalization\RawEvent;
use App\Services\Courier\Normalization\RawShipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;

/**
 * The contract every courier integration implements. The registry hands out
 * concrete instances bound to a courier_providers row, so the implementation
 * can use the row's credentials and settings.
 *
 * Phase 1 ships the read methods (listShipments, listEvents, getShipment).
 * Phase 2 will fill in the write methods. Until then, calling a write method
 * on a provider that doesn't support it throws CapabilityUnsupportedException.
 */
interface CourierProvider
{
    /**
     * Unique key matching the courier_providers.key column (e.g. "leopards").
     */
    public function key(): string;

    /**
     * Human-readable name for UI surfaces.
     */
    public function displayName(): string;

    /**
     * What this provider can do. The UI and the controllers both consult this
     * via supports() to decide which actions to expose.
     *
     * @return list<Capability>
     */
    public function capabilities(): array;

    /**
     * Convenience check.
     */
    public function supports(Capability $capability): bool;

    /**
     * Stream shipments updated since the given cursor. Return an empty
     * collection when the provider has nothing new. The default cursor (null)
     * means "everything this provider knows about" — used for the first sync.
     *
     * @return LazyCollection<int, RawShipment>
     */
    public function listShipments(?Carbon $since = null): LazyCollection;

    /**
     * Stream tracking events for a single shipment. Used to backfill the
     * event timeline for a single shipment, e.g. from the detail page.
     *
     * @return LazyCollection<int, RawEvent>
     */
    public function listEvents(string $externalId, ?Carbon $since = null): LazyCollection;

    /**
     * Fetch a single shipment on demand. Null when the provider doesn't know
     * about it (e.g. it was deleted upstream).
     */
    public function getShipment(string $externalId): ?RawShipment;

    /**
     * Phase 2: book a shipment with the courier. The implementation must
     * throw CapabilityUnsupportedException if it doesn't support CREATE_LABEL.
     */
    public function createLabel(CourierLabelRequest $request): RawShipment;

    /**
     * Phase 2: cancel a previously-booked shipment.
     */
    public function cancelShipment(string $externalId, ?string $reason = null): bool;

    /**
     * Phase 2: schedule a pickup at the courier.
     */
    public function schedulePickup(PickupRequest $request): RawPickupConfirmation;

    /**
     * Phase 2: ask the courier what they would charge to ship a parcel.
     *
     * @return list<RateQuote>
     */
    public function rateQuote(RateQuoteRequest $request): array;
}
