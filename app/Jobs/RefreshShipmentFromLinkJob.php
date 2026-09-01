<?php

namespace App\Jobs;

use App\Enums\Courier\ShipmentStatus;
use App\Models\Shipment;
use App\Services\Courier\Exceptions\CourierException;
use App\Services\Courier\Normalization\RawShipment;
use App\Services\Courier\TrackingLinkResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pulls a fresh delivery status for a Shopify-originated shipment by
 * following its tracking_url. Idempotent — re-runs just update the same
 * row and only append events we haven't seen.
 *
 * The job is what the `couriers:refresh-links` command dispatches in
 * bulk, and what the ShipmentController's "Refresh" button kicks off
 * for a single shipment.
 */
class RefreshShipmentFromLinkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    /**
     * If set, the job will skip shipments whose last refresh happened
     * within this many minutes. Prevents tight-loop dispatches from
     * hammering courier sites.
     */
    public const MIN_INTERVAL_MINUTES = 5;

    public function __construct(
        public readonly int $shipmentId,
    ) {
        $this->onQueue('couriers');
    }

    public function handle(TrackingLinkResolver $resolver): void
    {
        $shipment = Shipment::query()->with('provider')->find($this->shipmentId);
        if ($shipment === null) {
            return;
        }

        // Don't waste a request on a shipment we already know is done.
        if ($shipment->status !== null && $shipment->status->isTerminal()) {
            return;
        }

        // Don't waste a request on a shipment we just refreshed.
        if (
            $shipment->last_event_at !== null
            && $shipment->last_event_at->gt(now()->subMinutes(self::MIN_INTERVAL_MINUTES))
        ) {
            return;
        }

        if (trim((string) $shipment->tracking_url) === '') {
            return;
        }

        try {
            $raw = $resolver->resolve($shipment);
        } catch (CourierException $e) {
            Log::info('RefreshShipmentFromLinkJob: resolver failed', [
                'shipment' => $shipment->id,
                'tracking_number' => $shipment->tracking_number,
                'url' => $shipment->tracking_url,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        if ($raw === null) {
            return;
        }

        $this->applyToShipment($shipment, $raw);

        foreach ($resolver->listEvents($shipment) as $event) {
            $shipment->events()->firstOrCreate(
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
    }

    private function applyToShipment(Shipment $shipment, RawShipment $raw): void
    {
        $shipment->forceFill([
            'status' => $raw->status->value,
            'status_detail' => $raw->statusDetail,
            'shipped_at' => $raw->shippedAt ?? $shipment->shipped_at,
            'delivered_at' => $raw->status === ShipmentStatus::Delivered
                ? ($raw->deliveredAt ?? $shipment->delivered_at ?? now())
                : $shipment->delivered_at,
            'last_event_at' => $raw->lastEventAt ?? $raw->shippedAt ?? $shipment->last_event_at ?? now(),
            'raw_payload' => $raw->raw,
        ])->save();
    }
}
