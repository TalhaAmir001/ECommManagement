<?php

namespace App\Services\Shopify;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ShopifySync
{
    /**
     * Number of records requested per GraphQL page.
     */
    public const PAGE_SIZE = 250;

    public function __construct(private readonly ShopifyClient $client)
    {
    }

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
                                defaultAddress { country }
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

            $data = $this->client->graphql(<<<'GRAPHQL'
                query ShopifyOrders($first: Int!, $after: String) {
                    orders(first: $first, after: $after) {
                        pageInfo { hasNextPage endCursor }
                        edges {
                            node {
                                id
                                name
                                createdAt
                                displayFinancialStatus
                                displayFulfillmentStatus
                                totalPriceSet {
                                    shopMoney { amount }
                                }
                                customer {
                                    id
                                    displayName
                                    email
                                    createdAt
                                    defaultAddress { country }
                                }
                                lineItems(first: 250) {
                                    edges {
                                        node {
                                            id
                                            quantity
                                            title
                                            originalUnitPriceSet {
                                                shopMoney { amount }
                                            }
                                            variant {
                                                id
                                                sku
                                                title
                                                price
                                                product { id title productType }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                GRAPHQL, $variables);

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
        ];
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

        return Customer::updateOrCreate(
            ['shopify_id' => $customer['id'] ?? null],
            [
                'name' => ! empty($customer['displayName']) ? $customer['displayName'] : 'Unknown',
                'email' => $email,
                'country' => ! empty($defaultAddress['country']) ? $defaultAddress['country'] : 'Unknown',
                'created_at' => $customer['createdAt'] ?? null,
            ],
        );
    }

    /**
     * Upsert a Shopify order along with its line items.
     */
    public function upsertOrder(array $order): void
    {
        DB::transaction(function () use ($order): void {
            $customerId = null;

            if (! empty($order['customer']['id'])) {
                $customerId = $this->upsertCustomer($order['customer'])->id;
            }

            $totalPriceSet = $order['totalPriceSet'] ?? [];

            $localOrder = Order::updateOrCreate(
                ['shopify_id' => $order['id'] ?? null],
                [
                    'customer_id' => $customerId,
                    'number' => $order['name'] ?: ('Order '.($order['id'] ?? '')),
                    'status' => $this->mapStatus($order),
                    'financial_status' => $order['displayFinancialStatus'] ?? null,
                    'fulfillment_status' => $order['displayFulfillmentStatus'] ?? null,
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
        });
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
