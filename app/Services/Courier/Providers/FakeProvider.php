<?php

namespace App\Services\Courier\Providers;

use App\Enums\Courier\Capability;
use App\Services\Courier\AbstractCourierProvider;
use App\Services\Courier\Exceptions\CapabilityUnsupportedException;
use App\Services\Courier\Normalization\RawEvent;
use App\Services\Courier\Normalization\RawShipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;

/**
 * In-memory courier for tests. The constructor takes a list of RawShipment
 * fixtures and serves them back from listShipments / getShipment.
 */
class FakeProvider extends AbstractCourierProvider
{
    /**
     * @param  list<RawShipment>  $shipments
     * @param  list<Capability>  $capabilities
     */
    public function __construct(
        private readonly string $providerKey = 'fake',
        private readonly string $providerName = 'Fake Courier',
        private readonly array $shipments = [],
        private readonly array $capabilities = [
            Capability::ReadShipments,
            Capability::ReadEvents,
        ],
    ) {
    }

    public function key(): string
    {
        return $this->providerKey;
    }

    public function displayName(): string
    {
        return $this->providerName;
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function listShipments(?Carbon $since = null): LazyCollection
    {
        $items = $this->shipments;
        if ($since !== null) {
            $items = array_values(array_filter($items, function (RawShipment $s) use ($since) {
                $ts = $s->lastEventAt ?? $s->shippedAt;

                return $ts === null || $ts->gte($since);
            }));
        }

        return LazyCollection::make($items);
    }

    public function getShipment(string $externalId): ?RawShipment
    {
        foreach ($this->shipments as $s) {
            if ($s->externalId === $externalId) {
                return $s;
            }
        }

        return null;
    }

    public function createLabel(\App\Services\Courier\CourierLabelRequest $request): RawShipment
    {
        if (! $this->supports(Capability::CreateLabel)) {
            throw new CapabilityUnsupportedException($this->key(), Capability::CreateLabel);
        }

        return new RawShipment(
            externalId: 'FAKE-'.strtoupper(bin2hex(random_bytes(3))),
            trackingNumber: 'FAKE'.bin2hex(random_bytes(4)),
            reference: $request->order->number,
            status: \App\Enums\Courier\ShipmentStatus::Created,
            shippedAt: now(),
            consignee: $request->consignee,
            codAmount: $request->codAmount,
            raw: ['fake' => true],
        );
    }
}
