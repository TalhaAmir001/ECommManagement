<?php

namespace App\Services\Shopify;

use App\Models\Shipment;
use Illuminate\Support\Facades\Log;
use RuntimeException;
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
 * The write uses the Admin GraphQL API: the legacy REST create endpoint
 * (POST /orders/{id}/fulfillments.json) no longer exists, and modern
 * fulfillment creation requires a fulfillment-order access scope
 * (write_merchant_managed_fulfillment_orders for a self-fulfilled store).
 *
 * Failures are logged and swallowed on purpose: a Shopify outage or a
 * missing access scope must never block the local shipment from being
 * recorded. The next Shopify order sync stays the source of truth for the
 * store side.
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
     * ingested FROM Shopify are not re-pushed because they are only ever
     * saved by the ingest path, never through this service's callers).
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

        // Push any non-blank tracking number — typed or auto-generated. The
        // auto-generated value is a realistic numeric consignment number that
        // is safe to attach to the Shopify fulfillment.
        if ($trackingNumber === '') {
            return;
        }

        $state = $this->fetchFulfillmentState($this->client->orderIdToGid($order->shopify_id));
        if ($state === null) {
            return;
        }

        $tracking = ['number' => $trackingNumber];
        $carrier = $shipment->carrier_name !== null ? trim((string) $shipment->carrier_name) : '';
        if ($carrier !== '') {
            $tracking['company'] = $carrier;
        }

        if (isset($state['fulfillment_id'])) {
            // The order already has a fulfillment — attach the tracking to it
            // rather than creating a duplicate.
            $this->updateExistingFulfillment($state['fulfillment_id'], $tracking);

            return;
        }

        if (isset($state['fulfillment_order_id'])) {
            $this->createFulfillment($state['fulfillment_order_id'], $tracking);
        }
    }

    /**
     * Fetch the order's existing fulfillments and first fulfillment order in
     * a single GraphQL round-trip.
     *
     * @return array{fulfillment_id?: string, fulfillment_order_id?: string}|null
     */
    private function fetchFulfillmentState(string $orderGid): ?array
    {
        $data = $this->client->graphql(<<<'GRAPHQL'
            query ShopifyOrderFulfillmentState($id: ID!) {
                order(id: $id) {
                    id
                    fulfillments(first: 1) {
                        id
                    }
                    fulfillmentOrders(first: 1) {
                        edges {
                            node {
                                id
                            }
                        }
                    }
                }
            }
            GRAPHQL, ['id' => $orderGid]);

        $order = $data['order'] ?? null;
        if (! is_array($order)) {
            return null;
        }

        $state = [];

        $fulfillmentId = $order['fulfillments'][0]['id'] ?? null;
        if (is_string($fulfillmentId) && $fulfillmentId !== '') {
            $state['fulfillment_id'] = $fulfillmentId;
        }

        $fulfillmentOrderId = $order['fulfillmentOrders']['edges'][0]['node']['id'] ?? null;
        if (is_string($fulfillmentOrderId) && $fulfillmentOrderId !== '') {
            $state['fulfillment_order_id'] = $fulfillmentOrderId;
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $tracking
     */
    private function updateExistingFulfillment(string $fulfillmentId, array $tracking): void
    {
        $data = $this->client->graphql(<<<'GRAPHQL'
            mutation ShopifyTrackingInfoUpdate($fulfillmentId: ID!, $trackingInfoInput: FulfillmentTrackingInput!) {
                fulfillmentTrackingInfoUpdate(fulfillmentId: $fulfillmentId, trackingInfoInput: $trackingInfoInput) {
                    userErrors { field message }
                }
            }
            GRAPHQL, [
            'fulfillmentId' => $fulfillmentId,
            'trackingInfoInput' => $tracking,
        ]);

        $this->throwOnUserErrors($data['fulfillmentTrackingInfoUpdate'] ?? [], 'fulfillmentTrackingInfoUpdate');
    }

    /**
     * Create a fulfillment on the order's first fulfillment order. Line items
     * are intentionally omitted so Shopify fulfils every item on it.
     *
     * @param  array<string, mixed>  $tracking
     */
    private function createFulfillment(string $fulfillmentOrderId, array $tracking): void
    {
        $data = $this->client->graphql(<<<'GRAPHQL'
            mutation ShopifyFulfillmentCreate($fulfillment: FulfillmentInput!) {
                fulfillmentCreate(fulfillment: $fulfillment) {
                    userErrors { field message }
                }
            }
            GRAPHQL, [
            'fulfillment' => [
                'lineItemsByFulfillmentOrder' => [[
                    'fulfillmentOrderId' => $fulfillmentOrderId,
                ]],
                'notifyCustomer' => false,
                'trackingInfo' => $tracking,
            ],
        ]);

        $this->throwOnUserErrors($data['fulfillmentCreate'] ?? [], 'fulfillmentCreate');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function throwOnUserErrors(array $payload, string $mutation): void
    {
        $errors = $payload['userErrors'] ?? [];
        if ($errors === []) {
            return;
        }

        $messages = array_map(static function ($error) {
            $field = trim((string) ($error['field'] ?? ''));
            $message = trim((string) ($error['message'] ?? ''));

            return $field !== '' ? $field.': '.$message : $message;
        }, $errors);

        throw new RuntimeException($mutation.' failed: '.implode('; ', array_filter($messages)));
    }
}
