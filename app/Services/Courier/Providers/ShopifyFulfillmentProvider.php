<?php

namespace App\Services\Courier\Providers;

use App\Enums\Courier\Capability;
use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider as CourierProviderModel;
use App\Models\Shipment;
use App\Services\Courier\AbstractCourierProvider;
use App\Services\Courier\Exceptions\CourierException;
use App\Services\Courier\Normalization\Address;
use App\Services\Courier\Normalization\RawEvent;
use App\Services\Courier\Normalization\RawShipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Internal provider for tracking numbers that originate from Shopify
 * fulfillments. It does not call out to any courier API — the data is
 * already in our shipments table because ShopifySync wrote it there.
 *
 * Think of it as a "read mirror" of the Shopify fulfillment history:
 *  - listShipments()      → SELECT from the local shipments table
 *  - listEvents()         → SELECT from shipment_events
 *  - getShipment()        → SELECT one row
 *  - createLabel / etc.   → not supported; Shopify handles the label
 *
 * The ingest path (Shopify → local shipment) lives in ShopifyFulfillmentIngestor
 * and is called from ShopifySync after each order upsert.
 */
class ShopifyFulfillmentProvider extends AbstractCourierProvider
{
    public function __construct(
        private readonly CourierProviderModel $config,
    ) {}

    public function key(): string
    {
        return 'shopify';
    }

    public function displayName(): string
    {
        return 'Shopify Fulfillment';
    }

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return [
            Capability::ReadShipments,
            Capability::ReadEvents,
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

        return LazyCollection::make($query->get())->map(fn ($s) => $this->fromModel($s));
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

        return LazyCollection::make($query->get())->map(fn ($e) => new RawEvent(
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
     * Ingest (upsert) a Shopify fulfillment as a local shipment. Returns the
     * Shipment model. Idempotent on (courier_provider_id, external_id).
     *
     * @param  array<string, mixed>  $raw  the original fulfillment payload (for raw_payload)
     */
    public function ingestFulfillment(
        int $orderId,
        string $externalId,
        ?string $trackingNumber,
        ?string $carrierName,
        ?string $trackingUrl,
        ?string $displayStatus,
        ?Carbon $createdAt,
        ?Carbon $deliveredAt,
        ?array $originAddress = null,
        ?array $destinationAddress = null,
        array $raw = [],
    ): Shipment {
        if ($trackingNumber === null || $trackingNumber === '') {
            // Shopify sometimes creates fulfillments before tracking is assigned.
            // We skip those — once a tracking number exists, the next sync picks it up.
            throw new CourierException('Cannot ingest fulfillment without a tracking number.');
        }

        $status = $this->mapStatus($displayStatus, $deliveredAt);
        $lastEventAt = $deliveredAt ?? $createdAt ?? now();

        return DB::transaction(function () use (
            $orderId, $externalId, $trackingNumber, $carrierName, $trackingUrl,
            $status, $displayStatus, $createdAt, $deliveredAt, $lastEventAt,
            $originAddress, $destinationAddress, $raw
        ) {
            $shipment = Shipment::query()
                ->where('courier_provider_id', $this->config->id)
                ->where('external_id', $externalId)
                ->first();

            $payload = [
                'courier_provider_id' => $this->config->id,
                'external_id' => $externalId,
                'tracking_number' => $trackingNumber,
                'carrier_name' => $carrierName,
                'tracking_url' => $trackingUrl,
                'status' => $status,
                'status_detail' => $displayStatus,
                'shipped_at' => $createdAt,
                'delivered_at' => $status === ShipmentStatus::Delivered ? $deliveredAt : null,
                'last_event_at' => $lastEventAt,
                'consignor_name' => $originAddress['name'] ?? null,
                'consignor_address' => $originAddress['address1'] ?? null,
                'consignor_city' => $originAddress['city'] ?? null,
                'consignee_name' => $destinationAddress['name'] ?? null,
                'consignee_phone' => $destinationAddress['phone'] ?? null,
                'consignee_address' => implode(', ', array_filter([
                    $destinationAddress['address1'] ?? null,
                    $destinationAddress['address2'] ?? null,
                ])) ?: null,
                'consignee_city' => $destinationAddress['city'] ?? null,
                'order_id' => $orderId,
                'matched_method' => $orderId !== null ? 'shopify' : null,
                'matched_at' => $orderId !== null ? now() : null,
                'currency' => 'PKR',
                'raw_payload' => $raw,
            ];

            if ($shipment === null) {
                $shipment = Shipment::query()->create($payload);
            } else {
                $shipment->fill($payload)->save();
            }

            // Record a single timeline event so the detail page is never empty.
            $eventDescription = $displayStatus
                ? "Shopify fulfillment: {$displayStatus}"
                : 'Shopify fulfillment created';

            $shipment->events()->firstOrCreate(
                [
                    'shipment_id' => $shipment->id,
                    'occurred_at' => $createdAt ?? $lastEventAt,
                    'location' => $originAddress['city'] ?? null,
                ],
                [
                    'status' => $status,
                    'description' => $eventDescription,
                    'raw_payload' => $raw,
                ],
            );

            return $shipment;
        });
    }

    private function mapStatus(?string $displayStatus, ?Carbon $deliveredAt): ShipmentStatus
    {
        if ($deliveredAt !== null) {
            return ShipmentStatus::Delivered;
        }

        $needle = strtolower((string) $displayStatus);

        return match (true) {
            str_contains($needle, 'delivered') => ShipmentStatus::Delivered,
            str_contains($needle, 'in_transit') || str_contains($needle, 'in transit') => ShipmentStatus::InTransit,
            str_contains($needle, 'out_for_delivery') || str_contains($needle, 'out for delivery') => ShipmentStatus::OutForDelivery,
            str_contains($needle, 'attempted') => ShipmentStatus::Exception,
            str_contains($needle, 'fulfilled') => ShipmentStatus::InTransit,
            default => ShipmentStatus::Created,
        };
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
            consignor: new Address(
                name: $s->consignor_name,
                phone: $s->consignor_phone,
                address: $s->consignor_address,
                city: $s->consignor_city,
            ),
            consignee: new Address(
                name: $s->consignee_name,
                phone: $s->consignee_phone,
                address: $s->consignee_address,
                city: $s->consignee_city,
            ),
            weightKg: $s->weight_kg !== null ? (float) $s->weight_kg : null,
            pieces: $s->pieces,
            codAmount: $s->cod_amount !== null ? (float) $s->cod_amount : null,
            cost: $s->cost !== null ? (float) $s->cost : null,
            currency: $s->currency ?? 'PKR',
            raw: $s->raw_payload ?? [],
        );
    }
}
