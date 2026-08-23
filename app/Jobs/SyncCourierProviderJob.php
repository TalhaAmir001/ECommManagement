<?php

namespace App\Jobs;

use App\Models\CourierProvider as CourierProviderModel;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\Courier\CourierProvider;
use App\Services\Courier\CourierProviderRegistry;
use App\Services\Courier\Exceptions\CourierException;
use App\Services\Courier\OrderAutoMatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls the latest shipments + events from one courier provider and upserts
 * them into the local DB. Designed to be safe to retry and to run on a
 * schedule without overlapping itself.
 */
class SyncCourierProviderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Cap a single run at this many shipments to keep the job bounded.
     */
    public const MAX_SHIPMENTS_PER_RUN = 500;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $providerId,
    ) {
        $this->onQueue('couriers');
    }

    public function handle(CourierProviderRegistry $registry): void
    {
        $row = CourierProviderModel::query()->find($this->providerId);
        if ($row === null) {
            return;
        }
        if (! $row->enabled) {
            return;
        }
        if (! $row->isDueForSync()) {
            return;
        }

        $provider = $registry->resolve($row);
        $matcher = $this->makeMatcher();

        $cursor = $this->resolveCursor($row);
        $processed = 0;
        $startedAt = now();

        try {
            foreach ($provider->listShipments($cursor) as $raw) {
                if ($processed >= self::MAX_SHIPMENTS_PER_RUN) {
                    break;
                }
                $shipment = $this->upsertShipment($row, $raw);
                $this->appendEvents($shipment, $raw);
                $matcher->match($shipment);
                $processed++;
            }

            $row->forceFill([
                'last_synced_at' => $startedAt,
                'last_sync_status' => 'success',
                'last_sync_error' => null,
            ])->save();
        } catch (CourierException $e) {
            $row->forceFill([
                'last_synced_at' => $startedAt,
                'last_sync_status' => 'failed',
                'last_sync_error' => $e->getMessage(),
            ])->save();
            Log::warning('Courier sync failed', [
                'provider' => $row->key,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (Throwable $e) {
            $row->forceFill([
                'last_synced_at' => $startedAt,
                'last_sync_status' => 'failed',
                'last_sync_error' => $e->getMessage(),
            ])->save();
            throw $e;
        }
    }

    private function resolveCursor(CourierProviderModel $row): ?Carbon
    {
        if ($row->last_synced_at === null) {
            return null;
        }

        // Look back 10 minutes so a slightly-delayed event still shows up.
        return $row->last_synced_at->copy()->subMinutes(10);
    }

    private function upsertShipment(CourierProviderModel $row, \App\Services\Courier\Normalization\RawShipment $raw): Shipment
    {
        return DB::transaction(function () use ($row, $raw) {
            $payload = [
                'external_id' => $raw->externalId,
                'tracking_number' => $raw->trackingNumber,
                'reference' => $raw->reference,
                'status' => $raw->status->value,
                'status_detail' => $raw->statusDetail,
                'shipped_at' => $raw->shippedAt,
                'delivered_at' => $raw->deliveredAt,
                'last_event_at' => $raw->lastEventAt ?? $raw->shippedAt,
                'consignor_name' => $raw->consignor?->name,
                'consignor_phone' => $raw->consignor?->phone,
                'consignor_address' => $raw->consignor?->address,
                'consignor_city' => $raw->consignor?->city,
                'consignee_name' => $raw->consignee?->name,
                'consignee_phone' => $raw->consignee?->phone,
                'consignee_address' => $raw->consignee?->address,
                'consignee_city' => $raw->consignee?->city,
                'weight_kg' => $raw->weightKg,
                'pieces' => $raw->pieces,
                'cod_amount' => $raw->codAmount,
                'cost' => $raw->cost,
                'currency' => $raw->currency,
                'raw_payload' => $raw->raw,
            ];

            $shipment = Shipment::query()
                ->where('courier_provider_id', $row->id)
                ->where('external_id', $raw->externalId)
                ->first();

            if ($shipment === null) {
                $shipment = new Shipment();
                $shipment->courier_provider_id = $row->id;
                $shipment->fill($payload);
                $shipment->save();
            } else {
                $shipment->fill($payload);
                $shipment->save();
            }

            return $shipment;
        });
    }

    private function appendEvents(Shipment $shipment, \App\Services\Courier\Normalization\RawShipment $raw): void
    {
        $events = [];
        // Reuse the provider's own listEvents when it can give us a per-shipment
        // history — Leopards exposes that path.
        $provider = app(CourierProviderRegistry::class)->resolve($shipment->provider);
        if ($provider instanceof CourierProvider) {
            foreach ($provider->listEvents($raw->externalId) as $event) {
                $events[] = $event;
            }
        }
        if ($events === []) {
            return;
        }

        DB::transaction(function () use ($shipment, $events) {
            foreach ($events as $event) {
                ShipmentEvent::query()->firstOrCreate(
                    [
                        'shipment_id' => $shipment->id,
                        'occurred_at' => $event->occurredAt ?? now(),
                        'location' => $event->location,
                    ],
                    [
                        'status' => $event->status->value,
                        'description' => $event->description,
                        'raw_payload' => $event->raw,
                    ],
                );
            }
        });
    }

    private function makeMatcher(): OrderAutoMatcher
    {
        $cfg = config('couriers.auto_match', []);

        return new OrderAutoMatcher(
            strategies: (array) ($cfg['strategies'] ?? ['reference', 'phone']),
            overwriteManual: (bool) ($cfg['overwrite_manual'] ?? false),
        );
    }
}
