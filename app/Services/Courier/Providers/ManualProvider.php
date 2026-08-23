<?php

namespace App\Services\Courier\Providers;

use App\Enums\Courier\Capability;
use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider as CourierProviderModel;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\Courier\AbstractCourierProvider;
use App\Services\Courier\Normalization\RawEvent;
use App\Services\Courier\Normalization\RawShipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * The "no API" courier. Tracking numbers and events are pasted in by an
 * admin via the ShipmentController. Read methods read from the local DB so
 * the sync engine treats Manual exactly like a real provider.
 *
 * This makes the rest of the system (sync, match, UI) provider-agnostic
 * from day one — every courier we don't yet integrate just works.
 */
class ManualProvider extends AbstractCourierProvider
{
    public function __construct(
        private readonly CourierProviderModel $config,
    ) {
    }

    public function key(): string
    {
        return 'manual';
    }

    public function displayName(): string
    {
        return 'Manual Entry';
    }

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return [
            Capability::ReadShipments,
            Capability::ReadEvents,
            Capability::CodSupport,
        ];
    }

    public function listShipments(?Carbon $since = null): LazyCollection
    {
        $query = $this->config->shipments()->orderByDesc('last_event_at')->orderByDesc('id');

        if ($since !== null) {
            $query->where(function ($q) use ($since) {
                $q->where('last_event_at', '>=', $since)
                    ->orWhere('updated_at', '>=', $since);
            });
        }

        return LazyCollection::make($query->get())->map(fn (Shipment $s) => $this->fromModel($s));
    }

    public function listEvents(string $externalId, ?Carbon $since = null): LazyCollection
    {
        $shipment = $this->config->shipments()->where('external_id', $externalId)->first();
        if ($shipment === null) {
            return LazyCollection::make();
        }

        $query = $shipment->events()->orderBy('occurred_at');
        if ($since !== null) {
            $query->where('occurred_at', '>=', $since);
        }

        return LazyCollection::make($query->get())->map(fn (ShipmentEvent $e) => new RawEvent(
            occurredAt: $e->occurred_at,
            status: $e->status,
            location: $e->location,
            description: $e->description,
            raw: $e->raw_payload ?? [],
        ));
    }

    public function getShipment(string $externalId): ?RawShipment
    {
        $shipment = $this->config->shipments()->where('external_id', $externalId)->first();

        return $shipment ? $this->fromModel($shipment) : null;
    }

    /**
     * Append a new event to a manually-entered shipment. Returns the new
     * ShipmentEvent's id so the controller can redirect to it.
     */
    public function appendEvent(Shipment $shipment, ShipmentStatus|string $status, ?string $description = null, ?string $location = null, ?Carbon $occurredAt = null): ShipmentEvent
    {
        $statusValue = $status instanceof ShipmentStatus ? $status->value : $status;
        $occurredAt ??= now();

        return DB::transaction(function () use ($shipment, $statusValue, $description, $location, $occurredAt) {
            /** @var ShipmentEvent $event */
            $event = $shipment->events()->create([
                'status' => $statusValue,
                'occurred_at' => $occurredAt,
                'location' => $location,
                'description' => $description,
            ]);

            $shipment->forceFill([
                'status' => $statusValue,
                'status_detail' => $description,
                'last_event_at' => $occurredAt,
            ])->save();

            return $event;
        });
    }

    private function fromModel(Shipment $s): RawShipment
    {
        return new RawShipment(
            externalId: $s->external_id,
            trackingNumber: $s->tracking_number,
            reference: $s->reference,
            status: $s->status,
            statusDetail: $s->status_detail,
            shippedAt: $s->shipped_at,
            deliveredAt: $s->delivered_at,
            lastEventAt: $s->last_event_at,
            consignor: $this->address($s, 'consignor'),
            consignee: $this->address($s, 'consignee'),
            weightKg: $s->weight_kg !== null ? (float) $s->weight_kg : null,
            pieces: $s->pieces,
            codAmount: $s->cod_amount !== null ? (float) $s->cod_amount : null,
            cost: $s->cost !== null ? (float) $s->cost : null,
            currency: $s->currency ?? 'PKR',
            raw: $s->raw_payload ?? [],
        );
    }

    private function address(Shipment $s, string $prefix): \App\Services\Courier\Normalization\Address
    {
        $name = $s->{$prefix.'_name'};
        $phone = $s->{$prefix.'_phone'};
        $address = $s->{$prefix.'_address'};
        $city = $s->{$prefix.'_city'};

        if ($name === null && $phone === null && $address === null && $city === null) {
            return new \App\Services\Courier\Normalization\Address();
        }

        return new \App\Services\Courier\Normalization\Address(
            name: $name,
            phone: $phone,
            address: $address,
            city: $city,
        );
    }
}
