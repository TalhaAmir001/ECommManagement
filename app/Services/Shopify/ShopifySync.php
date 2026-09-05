<?php

namespace App\Services\Shopify;

use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Courier\Exceptions\CourierException;
use App\Services\Courier\Providers\ShopifyFulfillmentProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopifySync
{
    /**
     * Number of records requested per GraphQL page.
     */
    public const PAGE_SIZE = 250;

    public function __construct(private readonly ShopifyClient $client) {}

    /**
     * Sync products, customers and orders from Shopify into the local database.
     *
     * @return array{products: int, customers: int, orders: int}
     */
    public function syncAll(int $pageSize = self::PAGE_SIZE): array
    {
        return [
            'products' => $this->syncProducts($pageSize),
            'customers' => $this->syncCustomers($pageSize),
            'orders' => $this->syncOrders($pageSize),
        ];
    }

    /**
     * Sync the product catalog (one local Product per variant) including
     * inventory and unit cost.
     */
    public function syncProducts(int $pageSize = self::PAGE_SIZE): int
    {
        $count = 0;
        $after = null;

        do {
            $variables = ['first' => $pageSize];

            if ($after !== null) {
                $variables['after'] = $after;
            }

            $data = $this->client->graphql(<<<'GRAPHQL'
                query ShopifyProducts($first: Int!, $after: String) {
                    products(first: $first, after: $after) {
                        pageInfo { hasNextPage endCursor }
                        edges {
                            node {
                                id
                                title
                                productType
                                variants(first: 250) {
                                    edges {
                                        node {
                                            id
                                            title
                                            sku
                                            price
                                            inventoryQuantity
                                            inventoryItem {
                                                unitCost { amount }
                                                measurement {
                                                    weight {
                                                        value
                                                        unit
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                GRAPHQL, $variables);

            foreach ($data['products']['edges'] ?? [] as $edge) {
                $product = $edge['node'];

                foreach ($product['variants']['edges'] ?? [] as $variantEdge) {
                    $this->upsertVariant($product, $variantEdge['node']);
                    $count++;
                }
            }

            $hasNextPage = (bool) ($data['products']['pageInfo']['hasNextPage'] ?? false);
            $after = $data['products']['pageInfo']['endCursor'] ?? null;
        } while ($hasNextPage && $after !== null);

        return $count;
    }

    /**
     * Sync customers, preserving their original signup date.
     */
    public function syncCustomers(int $pageSize = self::PAGE_SIZE): int
    {
        $count = 0;
        $after = null;

        do {
            $variables = ['first' => $pageSize];

            if ($after !== null) {
                $variables['after'] = $after;
            }

            $data = $this->client->graphql(<<<'GRAPHQL'
                query ShopifyCustomers($first: Int!, $after: String) {
                    customers(first: $first, after: $after) {
                        pageInfo { hasNextPage endCursor }
                        edges {
                            node {
                                id
                                displayName
                                email
                                createdAt
                                defaultAddress {
                                    address1
                                    address2
                                    city
                                    province
                                    zip
                                    country
                                    phone
                                }
                            }
                        }
                    }
                }
                GRAPHQL, $variables);

            foreach ($data['customers']['edges'] ?? [] as $edge) {
                $this->upsertCustomer($edge['node']);
                $count++;
            }

            $hasNextPage = (bool) ($data['customers']['pageInfo']['hasNextPage'] ?? false);
            $after = $data['customers']['pageInfo']['endCursor'] ?? null;
        } while ($hasNextPage && $after !== null);

        return $count;
    }

    /**
     * Sync orders (with their line items, customers and products) from Shopify.
     */
    public function syncOrders(int $pageSize = self::PAGE_SIZE): int
    {
        $count = 0;
        $after = null;

        do {
            $variables = ['first' => $pageSize];

            if ($after !== null) {
                $variables['after'] = $after;
            }

            $data = $this->client->graphql(
                <<<'GRAPHQL'
                query ShopifyOrders($first: Int!, $after: String) {
                    orders(first: $first, after: $after) {
                        pageInfo { hasNextPage endCursor }
                        edges {
                            node {
                                ...OrderFields
                            }
                        }
                    }
                }
                GRAPHQL
                .ShopifyQueries::ORDER_FIELDS,
                $variables
            );

            foreach ($data['orders']['edges'] ?? [] as $edge) {
                $this->upsertOrder($edge['node']);
                $count++;
            }

            $hasNextPage = (bool) ($data['orders']['pageInfo']['hasNextPage'] ?? false);
            $after = $data['orders']['pageInfo']['endCursor'] ?? null;
        } while ($hasNextPage && $after !== null);

        return $count;
    }

    /**
     * Upsert a Shopify product variant as a local Product.
     */
    private function upsertVariant(array $product, array $variant): ?Product
    {
        if (empty($variant['id'])) {
            return null;
        }

        return Product::updateOrCreate(
            ['shopify_id' => $variant['id']],
            $this->variantAttributes($product, $variant),
        );
    }

    /**
     * Find an existing local Product for a variant, or create it from the
     * limited data available on an order's line item.
     */
    private function findOrCreateVariant(array $product, array $variant): ?Product
    {
        if (empty($variant['id'])) {
            return null;
        }

        return Product::firstOrCreate(
            ['shopify_id' => $variant['id']],
            $this->variantAttributes($product, $variant),
        );
    }

    /**
     * Map a Shopify product/variant pair onto local Product columns.
     *
     * @return array<string, mixed>
     */
    private function variantAttributes(array $product, array $variant): array
    {
        $productTitle = $product['title'] ?? '';
        $variantTitle = $variant['title'] ?? '';

        $name = ($variantTitle === '' || $variantTitle === 'Default Title')
            ? $productTitle
            : "{$productTitle} — {$variantTitle}";

        $inventoryItem = $variant['inventoryItem'] ?? [];

        return [
            'name' => $name,
            'sku' => ! empty($variant['sku']) ? $variant['sku'] : null,
            'category' => ! empty($product['productType']) ? $product['productType'] : 'Uncategorized',
            'price' => (float) ($variant['price'] ?? 0),
            'cost' => (float) ($inventoryItem['unitCost']['amount'] ?? 0),
            'stock' => (int) ($variant['inventoryQuantity'] ?? 0),
            'weight_kg' => $this->variantWeightKg($variant),
        ];
    }

    /**
     * Read a variant's weight regardless of which Shopify API generation
     * produced the payload.
     *
     * Current Admin API versions (2024-10+) expose weight on the
     * inventory item's measurement block:
     *
     *     inventoryItem { measurement { weight { value unit } } }
     *
     * Older versions exposed `weight` / `weightUnit` directly on the
     * variant. Both shapes are accepted here so old cached payloads and
     * webhook/order responses never silently drop a weight.
     *
     * @param  array<string, mixed>  $variant
     */
    private function variantWeightKg(array $variant): ?float
    {
        $weight = $variant['inventoryItem']['measurement']['weight'] ?? null;
        if (is_array($weight)) {
            return $this->normaliseWeightKg($weight['value'] ?? null, $weight['unit'] ?? null);
        }

        // Legacy shape: weight fields directly on the variant.
        return $this->normaliseWeightKg($variant['weight'] ?? null, $variant['weightUnit'] ?? null);
    }

    /**
     * Normalise a Shopify variant weight to kilograms.
     *
     * Shopify reports a variant's `weight` in whatever unit `weightUnit`
     * declares (grams by default when the merchant never set a unit). The
     * local schema — shipments.weight_kg and the courier rate bands — is
     * always kilograms, so every incoming unit is converted here. Returns
     * null when no weight has been configured for the variant.
     */
    private function normaliseWeightKg(mixed $weight, mixed $unit): ?float
    {
        if ($weight === null || $weight === '' || ! is_numeric($weight)) {
            return null;
        }

        $grams = match (strtoupper(trim((string) ($unit ?? 'GRAMS')))) {
            'KILOGRAMS' => (float) $weight * 1000,
            'POUNDS' => (float) $weight * 453.59237,
            'OUNCES' => (float) $weight * 28.349523125,
            default => (float) $weight, // GRAMS — Shopify's implicit unit.
        };

        return round($grams / 1000, 3);
    }

    /**
     * Upsert a Shopify customer as a local Customer.
     */
    private function upsertCustomer(array $customer): Customer
    {
        $email = ! empty($customer['email']) ? $customer['email'] : null;

        // Shopify allows multiple customers to share an email, while the local
        // schema treats it as unique. Blank out duplicates so they don't clash.
        if ($email !== null && Customer::where('email', $email)
            ->where('shopify_id', '!=', $customer['id'] ?? '')
            ->exists()) {
            $email = null;
        }

        $defaultAddress = $customer['defaultAddress'] ?? [];

        $payload = [
            'name' => ! empty($customer['displayName']) ? $customer['displayName'] : 'Unknown',
            'email' => $email,
            'country' => ! empty($defaultAddress['country']) ? $defaultAddress['country'] : 'Unknown',
            'created_at' => $customer['createdAt'] ?? null,
        ];

        // Only carry the phone over when Shopify actually has one, so an
        // update can never wipe a previously recorded number.
        if (! empty($defaultAddress['phone'])) {
            $payload['phone'] = $defaultAddress['phone'];
        }

        return Customer::updateOrCreate(
            ['shopify_id' => $customer['id'] ?? null],
            $payload,
        );
    }

    /**
     * Upsert a Shopify order along with its line items and fulfillments.
     */
    public function upsertOrder(array $order): void
    {
        DB::transaction(function () use ($order): void {
            $customerId = null;

            if (! empty($order['customer']['id'])) {
                $customerId = $this->upsertCustomer($order['customer'])->id;
            }

            $totalPriceSet = $order['totalPriceSet'] ?? [];

            // The address the courier actually delivers to. Fall back to the
            // customer's default address for orders without a shipping
            // address (rare — e.g. draft orders or digital-only orders).
            $shippingAddress = ! empty($order['shippingAddress'])
                ? $order['shippingAddress']
                : ($order['customer']['defaultAddress'] ?? []);

            $localOrder = Order::updateOrCreate(
                ['shopify_id' => $order['id'] ?? null],
                [
                    'customer_id' => $customerId,
                    'number' => $order['name'] ?: ('Order '.($order['id'] ?? '')),
                    'status' => $this->mapStatus($order),
                    'financial_status' => $order['displayFinancialStatus'] ?? null,
                    'fulfillment_status' => $order['displayFulfillmentStatus'] ?? null,
                    'shipping_name' => $shippingAddress['name'] ?? null,
                    'shipping_address1' => $shippingAddress['address1'] ?? null,
                    'shipping_address2' => $shippingAddress['address2'] ?? null,
                    'shipping_city' => $shippingAddress['city'] ?? null,
                    'shipping_province' => $shippingAddress['province'] ?? null,
                    'shipping_zip' => $shippingAddress['zip'] ?? null,
                    'shipping_country' => $shippingAddress['country'] ?? null,
                    'shipping_phone' => $shippingAddress['phone'] ?? null,
                    'total' => (float) ($totalPriceSet['shopMoney']['amount'] ?? 0),
                    'created_at' => $order['createdAt'] ?? null,
                ],
            );

            // Replace the order's line items to keep them in sync.
            $localOrder->items()->delete();

            foreach ($order['lineItems']['edges'] ?? [] as $edge) {
                $line = $edge['node'];
                $variant = is_array($line['variant'] ?? null) ? $line['variant'] : [];
                $product = is_array($variant['product'] ?? null) ? $variant['product'] : [];

                if (empty($variant['id'])) {
                    continue;
                }

                $productId = $this->findOrCreateVariant($product, $variant)?->id;

                if ($productId === null) {
                    continue;
                }

                $originalUnitPriceSet = $line['originalUnitPriceSet'] ?? [];
                $unitPrice = $originalUnitPriceSet['shopMoney']['amount'] ?? $variant['price'] ?? 0;

                $localOrder->items()->create([
                    'shopify_id' => $line['id'] ?? null,
                    'product_id' => $productId,
                    'quantity' => (int) ($line['quantity'] ?? 0),
                    'unit_price' => (float) $unitPrice,
                ]);
            }

            // Pull the customer's default address into the local customer so
            // the fulfillment consignee fields aren't blank.
            $this->hydrateCustomerAddress($customerId, $order['customer']['defaultAddress'] ?? null);

            // Ingest fulfillments as shipments. Done inside the same
            // transaction so a sync that partially fails rolls back cleanly.
            $this->ingestFulfillments($localOrder, $order);
        });
    }

    /**
     * Mirror a Shopify customer's default address onto the local customer
     * row. We only update fields the fulfillment page needs (name, country)
     * and we never clobber an already-populated phone.
     */
    private function hydrateCustomerAddress(?int $customerId, ?array $address): void
    {
        if ($customerId === null || empty($address)) {
            return;
        }

        $customer = Customer::query()->find($customerId);
        if ($customer === null) {
            return;
        }

        $updates = [];
        if (empty($customer->country) || $customer->country === 'Unknown') {
            $updates['country'] = $address['country'] ?? $customer->country;
        }
        if (! empty($address['phone']) && empty($customer->phone)) {
            $updates['phone'] = $address['phone'];
        }
        if ($updates !== []) {
            $customer->forceFill($updates)->save();
        }
    }

    /**
     * Ingest the fulfillments attached to a Shopify order as local shipments
     * under the "shopify" provider. Idempotent on (courier_provider_id,
     * external_id) so re-syncs update rather than duplicate.
     */
    private function ingestFulfillments(Order $localOrder, array $order): void
    {
        $provider = CourierProvider::query()->where('key', 'shopify')->first();
        if ($provider === null) {
            // No shopify provider configured (shouldn't happen since the
            // seeder runs, but guard anyway).
            return;
        }

        $ingestor = new ShopifyFulfillmentProvider($provider);

        // Use the order's shipping address as the consignee — that's where
        // the courier actually delivers. Falls back to the customer record
        // (and its default address) when the order has no address stored.
        $customer = $localOrder->customer;
        $consignee = $customer ? [
            'name' => $localOrder->shipping_name ?: ($customer->name ?? null),
            'phone' => $customer->phone,
            'address1' => $localOrder->shipping_address1,
            'address2' => $localOrder->shipping_address2,
            'city' => $localOrder->shipping_city,
        ] : null;

        foreach ($this->fulfillmentsFrom($order) as $fulfillment) {
            $tracking = $this->firstTrackingInfo($fulfillment['trackingInfo'] ?? null);
            $trackingNumber = $tracking['number'] ?? null;

            if ($trackingNumber === null || $trackingNumber === '') {
                continue;
            }

            try {
                $ingestor->ingestFulfillment(
                    orderId: $localOrder->id,
                    externalId: $fulfillment['id'] ?? ('shopify:'.bin2hex(random_bytes(6))),
                    trackingNumber: $trackingNumber,
                    carrierName: $tracking['company'] ?? null,
                    trackingUrl: $tracking['url'] ?? null,
                    displayStatus: $fulfillment['displayStatus'] ?? $fulfillment['status'] ?? null,
                    createdAt: $this->parseDate($fulfillment['createdAt'] ?? null),
                    deliveredAt: $this->parseDate($fulfillment['deliveredAt'] ?? null),
                    originAddress: is_array($fulfillment['originAddress'] ?? null) ? $fulfillment['originAddress'] : null,
                    destinationAddress: $consignee,
                    raw: $fulfillment,
                );
            } catch (CourierException $e) {
                // Skip this fulfillment but keep syncing the rest. We log
                // so a future admin can investigate.
                Log::warning('Skipped Shopify fulfillment ingest', [
                    'order' => $localOrder->number,
                    'fulfillment' => $fulfillment['id'] ?? null,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Normalize an order's fulfillments into a plain list of fulfillment
     * arrays. The Admin GraphQL API returns fulfillments as a plain list
     * (fulfillments(first: 50) → [Fulfillment!]!), but webhook jobs may still
     * be running against older payloads that used the connection shape, so
     * both forms are accepted here.
     *
     * @return list<array<string, mixed>>
     */
    private function fulfillmentsFrom(array $order): array
    {
        $fulfillments = $order['fulfillments'] ?? [];

        // Real API shape: a list of fulfillment objects.
        if (array_is_list($fulfillments)) {
            return array_values(array_filter($fulfillments, 'is_array'));
        }

        // Legacy connection shape (edges/node) for backward compatibility.
        if (is_array($fulfillments['edges'] ?? null)) {
            return array_values(array_filter(
                array_map(static fn ($edge) => $edge['node'] ?? null, $fulfillments['edges']),
                'is_array',
            ));
        }

        return [];
    }

    /**
     * Return the first tracking entry for a fulfillment. Shopify exposes
     * trackingInfo as a list ([FulfillmentTrackingInfo!]!) because a single
     * fulfillment can span multiple packages; the local shipments table keys
     * on the fulfillment id, so only the first package is mirrored.
     *
     * @return array<string, mixed>
     */
    private function firstTrackingInfo(mixed $trackingInfo): array
    {
        if (is_array($trackingInfo) && isset($trackingInfo['number'])) {
            // Single tracking map (legacy shape).
            return $trackingInfo;
        }

        $tracking = is_array($trackingInfo) ? $trackingInfo : [];

        foreach ($tracking as $entry) {
            if (is_array($entry)) {
                return $entry;
            }
        }

        return [];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Map a Shopify order's financial/fulfillment statuses onto the local
     * order status vocabulary (pending, processing, shipped, delivered,
     * cancelled).
     *
     * @param  array<string, mixed>  $order
     */
    private function mapStatus(array $order): string
    {
        $financial = $order['displayFinancialStatus'] ?? null;
        $fulfillment = $order['displayFulfillmentStatus'] ?? null;

        if (in_array($financial, ['REFUNDED', 'VOIDED'], true)) {
            return 'cancelled';
        }

        return match ($fulfillment) {
            'FULFILLED' => 'delivered',
            'PARTIALLY_FULFILLED' => 'processing',
            default => 'pending',
        };
    }
}
