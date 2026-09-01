<?php

namespace App\Services\Courier;

use App\Models\Shipment;
use App\Services\Courier\Exceptions\CourierException;
use App\Services\Courier\Normalization\RawEvent;
use App\Services\Courier\Normalization\RawShipment;
use App\Services\Courier\WebTracking\GenericWebTracker;
use App\Services\Courier\WebTracking\TrackingUrlProbe;
use Illuminate\Support\Facades\Log;

/**
 * Routes a tracking URL to the right code path so the app can refresh a
 * Shopify-originated shipment from whatever the courier actually reports.
 *
 * Resolution strategy (in order):
 *  1. If the URL hostname matches a courier we already have a real API
 *     integration for (Leopards, TCS) and that provider is enabled, hand
 *     off to its structured getShipment() / listEvents() — the result is
 *     the most accurate one we can produce.
 *  2. Otherwise, fall back to the GenericWebTracker which fetches the
 *     public tracking page and scrapes the status out of the HTML.
 *
 * The result is always a RawShipment (and the events can be fetched
 * separately via listEvents()). When the URL can't be resolved at all,
 * we throw CourierException so the caller can decide to skip.
 */
class TrackingLinkResolver
{
    /**
     * @param  array<string, string>  $knownHosts  host suffix → courier key
     * @param  array<int, string>  $providerKeysWithStructuredApi  courier keys with real APIs
     */
    public function __construct(
        private readonly TrackingUrlProbe $probe,
        private readonly GenericWebTracker $webTracker,
        private readonly CourierProviderRegistry $registry,
        private readonly array $knownHosts = [],
        private readonly array $providerKeysWithStructuredApi = ['leopards', 'tcs'],
    ) {}

    /**
     * Pull the freshest available view of a shipment. Returns null when the
     * shipment has no tracking URL or the URL is empty — that case is
     * normal for "created" fulfillments and shouldn't be treated as an
     * error.
     *
     * @throws CourierException
     */
    public function resolve(Shipment $shipment): ?RawShipment
    {
        $url = trim((string) $shipment->tracking_url);
        if ($url === '') {
            return null;
        }

        $probe = $this->probe->probe($url);
        $courierKey = $probe['courier'];

        // Step 1: try a structured API provider when we recognize the host.
        if ($courierKey !== null && in_array($courierKey, $this->providerKeysWithStructuredApi, true)) {
            try {
                $provider = $this->registry->findByKey($courierKey);
                if ($provider !== null) {
                    $raw = $provider->getShipment($shipment->tracking_number);
                    if ($raw !== null) {
                        $raw = $this->stampSource($raw, $url, 'api:'.$courierKey);

                        return $raw;
                    }
                }
            } catch (CourierException $e) {
                // Fall through to the web tracker — a broken API call
                // shouldn't block us from getting any update.
                Log::info('TrackingLinkResolver: structured API failed, falling back to web tracker', [
                    'shipment' => $shipment->tracking_number,
                    'courier' => $courierKey,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        // Step 2: generic web tracker for everything else.
        $raw = $this->webTracker->track(
            $url,
            $shipment->tracking_number !== '' ? $shipment->tracking_number : ($probe['tracking_number'] ?? null),
        );
        $raw = $this->stampSource($raw, $url, 'web:'.($courierKey ?? 'unknown'));

        return $raw;
    }

    /**
     * Fetch events only — useful for the bulk refresh path which already
     * knows the current status from resolve() and just wants the timeline.
     *
     * @return list<RawEvent>
     *
     * @throws CourierException
     */
    public function listEvents(Shipment $shipment): array
    {
        $url = trim((string) $shipment->tracking_url);
        if ($url === '') {
            return [];
        }

        $probe = $this->probe->probe($url);
        $courierKey = $probe['courier'];

        if ($courierKey !== null && in_array($courierKey, $this->providerKeysWithStructuredApi, true)) {
            try {
                $provider = $this->registry->findByKey($courierKey);
                if ($provider !== null) {
                    $events = [];
                    foreach ($provider->listEvents($shipment->tracking_number) as $event) {
                        $events[] = $event;
                    }

                    return $events;
                }
            } catch (CourierException) {
                // Fall through to the web tracker.
            }
        }

        return $this->webTracker->listEvents($url);
    }

    /**
     * Tell the caller which strategy was used (or would be used) for a
     * given shipment. Useful for the show page to display "API" vs "web"
     * next to the Refresh button.
     */
    public function strategyFor(Shipment $shipment): string
    {
        $url = trim((string) $shipment->tracking_url);
        if ($url === '') {
            return 'none';
        }

        $courierKey = $this->probe->probe($url)['courier'];
        if ($courierKey !== null && in_array($courierKey, $this->providerKeysWithStructuredApi, true)) {
            return 'api:'.$courierKey;
        }

        return 'web:'.($courierKey ?? 'unknown');
    }

    private function stampSource(RawShipment $raw, string $url, string $source): RawShipment
    {
        // The source isn't a column on RawShipment, so we record it in the
        // raw payload — useful for debugging "why does this shipment say
        // Delivered" questions later.
        $rawPayload = $raw->raw;
        $rawPayload['tracking_url'] = $url;
        $rawPayload['resolver_source'] = $source;

        return new RawShipment(
            externalId: $raw->externalId,
            trackingNumber: $raw->trackingNumber,
            reference: $raw->reference,
            status: $raw->status,
            statusDetail: $raw->statusDetail,
            shippedAt: $raw->shippedAt,
            deliveredAt: $raw->deliveredAt,
            lastEventAt: $raw->lastEventAt,
            consignor: $raw->consignor,
            consignee: $raw->consignee,
            weightKg: $raw->weightKg,
            pieces: $raw->pieces,
            codAmount: $raw->codAmount,
            cost: $raw->cost,
            currency: $raw->currency,
            raw: $rawPayload,
        );
    }
}
