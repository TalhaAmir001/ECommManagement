<?php

namespace App\Services\Shopify;

use App\Models\Shipment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes a locally-created shipment's tracking number up to the Shopify
 * order so the store reflects it.
 *
 * Shopify stores tracking numbers on fulfillments — an order with no
 * fulfillment cannot hold one — so this service either creates the order's
 * first fulfillment (which also marks the order fulfilled) or updates the
 * tracking on an existing fulfillment instead of duplicating it.
 *
 * Failures are logged and swallowed on purpose: a Shopify outage or a
 * missing write_fulfillments scope must never block the local shipment from
 * being recorded. The next Shopify order sync stays the source of truth for
 * the store side.
 */
class ShopifyTrackingService
{
    public function __construct(private readonly ShopifyClient $client)
    {
    }

    /**
     * Mirror the shipment's tracking number onto its Shopify order.
     *
     * Safe to call unconditionally: it silently no-ops for shipments with no
     * linked order, no Shopify order id, or no tracking number (e.g. rows
     * that were ingested FROM Shopify — pushing those back would be circular).
     */
    public function pushTracking(Shipment $shipment): void
    {
        try {
            $this->push($shipment);
        } catch (Throwable $e) {
            Log::warning('Could not push shipment tracking to Shopify', [
                'shipment' => $shipment->id,
                'order' => $shipment->order?->number,
                'tracking_number' => $shipment->tracking_number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function push(Shipment $shipment): void
    {
        $order = $shipment->order;
        if ($order === null || empty($order->shopify_id)) {
            return;
        }

        $trackingNumber = trim((string) $shipment->tracking_number);

        // Only real courier tracking numbers are pushed. Auto-generated
        // "MNL-XXXXXXXX" placeholders (the manual provider's stand-in
        // numbers, pre-filled by the create form) must never reach Shopify —
        // there is nothing real to attach yet.
        if ($trackingNumber === '' || str_starts_with($trackingNumber, 'MNL-')) {
            return;
        }

        $orderId = $order->shopifyNumericId();
        if ($orderId === null) {
            return;
        }

        $fulfillments = $this->client->rest('get', "orders/{$orderId}/fulfillments");

        $trackingCompany = $shipment->carrier_name ?? null;

        if (! empty($fulfillments['fulfillments'][0]['id'])) {
            // The order already has a fulfillment (e.g. a partial one) —
            // attach the tracking to it rather than creating a duplicate.
            $fulfillmentId = $fulfillments['fulfillments'][0]['id'];

            $this->client->rest('put', "orders/{$orderId}/fulfillments/{$fulfillmentId}", [
                'fulfillment' => $this->withoutNulls([
                    'tracking_number' => $trackingNumber,
                    'tracking_company' => $trackingCompany,
                ]),
            ]);

            return;
        }

        // No fulfillment yet: create one with the tracking info. This also
        // marks the order fulfilled in Shopify.
        $this->client->rest('post', "orders/{$orderId}/fulfillments", [
            'fulfillment' => $this->withoutNulls([
                'tracking_number' => $trackingNumber,
                'tracking_company' => $trackingCompany,
                'notify_customer' => false,
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withoutNulls(array $values): array
    {
        return array_filter($values, static fn ($value) => $value !== null);
    }
}