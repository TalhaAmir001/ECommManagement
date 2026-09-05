<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shipment;
use App\Services\Shopify\ShopifySync;
use Database\Seeders\CourierProvidersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('shopify.shop', 'test-store');
        config()->set('shopify.client_id', 'client-id');
        config()->set('shopify.client_secret', 'client-secret');
        config()->set('shopify.api_version', '2026-07');

        Cache::flush();
    }

    /**
     * Fake both the token endpoint and the versioned GraphQL endpoint.
     */
    private function fakeShopify(array $graphqlData): void
    {
        Http::fake([
            'https://test-store.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_test',
                'expires_in' => 86399,
            ]),
            'https://test-store.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => $graphqlData,
            ]),
        ]);
    }

    public function test_it_syncs_products_with_inventory_and_cost(): void
    {
        $this->fakeShopify([
            'products' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Product/1',
                        'title' => 'T-Shirt',
                        'productType' => 'Apparel',
                        'variants' => [
                            'edges' => [
                                ['node' => [
                                    'id' => 'gid://shopify/ProductVariant/11',
                                    'title' => 'Small',
                                    'sku' => 'TS-S',
                                    'price' => '19.99',
                                    'inventoryQuantity' => 25,
                                    'inventoryItem' => ['unitCost' => ['amount' => '9.50']],
                                ]],
                                ['node' => [
                                    'id' => 'gid://shopify/ProductVariant/12',
                                    'title' => 'Default Title',
                                    'sku' => '',
                                    'price' => '24.99',
                                    'inventoryQuantity' => 0,
                                    'inventoryItem' => null,
                                ]],
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        $count = app(ShopifySync::class)->syncProducts();

        $this->assertSame(2, $count);

        $this->assertDatabaseHas('products', [
            'shopify_id' => 'gid://shopify/ProductVariant/11',
            'name' => 'T-Shirt — Small',
            'sku' => 'TS-S',
            'category' => 'Apparel',
            'price' => 19.99,
            'cost' => 9.5,
            'stock' => 25,
        ]);

        $this->assertDatabaseHas('products', [
            'shopify_id' => 'gid://shopify/ProductVariant/12',
            'name' => 'T-Shirt',
            'sku' => null,
            'price' => 24.99,
            'cost' => 0,
            'stock' => 0,
        ]);
    }

    public function test_it_syncs_customers_and_preserves_their_signup_date(): void
    {
        $this->fakeShopify([
            'customers' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Customer/1',
                        'displayName' => 'Jane Doe',
                        'email' => 'jane@example.com',
                        'createdAt' => '2026-07-01T10:00:00Z',
                        'defaultAddress' => ['country' => 'United States'],
                    ]],
                ],
            ],
        ]);

        app(ShopifySync::class)->syncCustomers();

        $this->assertDatabaseHas('customers', [
            'shopify_id' => 'gid://shopify/Customer/1',
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'country' => 'United States',
            'created_at' => '2026-07-01 10:00:00',
        ]);
    }

    public function test_it_handles_customers_without_a_default_address(): void
    {
        $this->fakeShopify([
            'customers' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Customer/2',
                        'displayName' => null,
                        'email' => 'guest@example.com',
                        'createdAt' => '2026-07-05T12:00:00Z',
                        'defaultAddress' => null,
                    ]],
                ],
            ],
        ]);

        app(ShopifySync::class)->syncCustomers();

        $this->assertDatabaseHas('customers', [
            'shopify_id' => 'gid://shopify/Customer/2',
            'name' => 'Unknown',
            'email' => 'guest@example.com',
            'country' => 'Unknown',
        ]);
    }

    public function test_it_syncs_orders_with_customers_and_line_items(): void
    {
        $this->fakeShopify([
            'orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Order/100',
                        'name' => '#1001',
                        'createdAt' => '2026-07-15T08:30:00Z',
                        'displayFinancialStatus' => 'PAID',
                        'displayFulfillmentStatus' => 'FULFILLED',
                        'totalPriceSet' => ['shopMoney' => ['amount' => '59.97']],
                        'customer' => [
                            'id' => 'gid://shopify/Customer/1',
                            'displayName' => 'Jane Doe',
                            'email' => 'jane@example.com',
                            'createdAt' => '2026-07-01T10:00:00Z',
                            'defaultAddress' => ['country' => 'United States'],
                        ],
                        'lineItems' => [
                            'edges' => [
                                ['node' => [
                                    'id' => 'gid://shopify/LineItem/1000',
                                    'quantity' => 3,
                                    'title' => 'T-Shirt Small',
                                    'originalUnitPriceSet' => null,
                                    'variant' => [
                                        'id' => 'gid://shopify/ProductVariant/11',
                                        'sku' => 'TS-S',
                                        'title' => 'Small',
                                        'price' => '19.99',
                                        'product' => [
                                            'id' => 'gid://shopify/Product/1',
                                            'title' => 'T-Shirt',
                                            'productType' => 'Apparel',
                                        ],
                                    ],
                                ]],
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        app(ShopifySync::class)->syncOrders();

        $this->assertDatabaseHas('customers', [
            'shopify_id' => 'gid://shopify/Customer/1',
            'name' => 'Jane Doe',
        ]);

        $this->assertDatabaseHas('products', [
            'shopify_id' => 'gid://shopify/ProductVariant/11',
            'name' => 'T-Shirt — Small',
        ]);

        $customerId = Customer::where('shopify_id', 'gid://shopify/Customer/1')->value('id');

        $this->assertDatabaseHas('orders', [
            'shopify_id' => 'gid://shopify/Order/100',
            'number' => '#1001',
            'status' => 'delivered',
            'total' => 59.97,
            'customer_id' => $customerId,
            'created_at' => '2026-07-15 08:30:00',
        ]);

        $this->assertDatabaseHas('order_items', [
            'shopify_id' => 'gid://shopify/LineItem/1000',
            'order_id' => Order::where('shopify_id', 'gid://shopify/Order/100')->value('id'),
            'product_id' => Product::where('shopify_id', 'gid://shopify/ProductVariant/11')->value('id'),
            'quantity' => 3,
            'unit_price' => 19.99,
        ]);
    }

    public function test_it_persists_the_orders_shipping_address(): void
    {
        $this->fakeShopify([
            'orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Order/101',
                        'name' => '#1002',
                        'createdAt' => '2026-07-16T08:30:00Z',
                        'displayFinancialStatus' => 'PAID',
                        'displayFulfillmentStatus' => 'UNFULFILLED',
                        'totalPriceSet' => ['shopMoney' => ['amount' => '25.00']],
                        'customer' => [
                            'id' => 'gid://shopify/Customer/2',
                            'displayName' => 'John Smith',
                            'email' => 'john@example.com',
                            'createdAt' => '2026-07-01T10:00:00Z',
                            'defaultAddress' => ['country' => 'Pakistan'],
                        ],
                        'shippingAddress' => [
                            'name' => 'John Smith',
                            'address1' => 'House 12, Street 5',
                            'address2' => 'F-8',
                            'city' => 'Islamabad',
                            'province' => 'Islamabad Capital Territory',
                            'zip' => '44000',
                            'country' => 'Pakistan',
                            'phone' => '+92 300 1234567',
                        ],
                        'lineItems' => ['edges' => []],
                    ]],
                ],
            ],
        ]);

        app(ShopifySync::class)->syncOrders();

        $this->assertDatabaseHas('orders', [
            'shopify_id' => 'gid://shopify/Order/101',
            'shipping_name' => 'John Smith',
            'shipping_address1' => 'House 12, Street 5',
            'shipping_address2' => 'F-8',
            'shipping_city' => 'Islamabad',
            'shipping_province' => 'Islamabad Capital Territory',
            'shipping_zip' => '44000',
            'shipping_country' => 'Pakistan',
            'shipping_phone' => '+92 300 1234567',
        ]);
    }

    public function test_it_maps_refunded_orders_to_cancelled_and_handles_guests(): void
    {
        $this->fakeShopify([
            'orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Order/200',
                        'name' => '#1002',
                        'createdAt' => '2026-07-20T10:00:00Z',
                        'displayFinancialStatus' => 'REFUNDED',
                        'displayFulfillmentStatus' => 'FULFILLED',
                        'totalPriceSet' => ['shopMoney' => ['amount' => '10.00']],
                        'customer' => null,
                        'lineItems' => ['edges' => []],
                    ]],
                ],
            ],
        ]);

        app(ShopifySync::class)->syncOrders();

        $this->assertDatabaseHas('orders', [
            'shopify_id' => 'gid://shopify/Order/200',
            'status' => 'cancelled',
            'customer_id' => null,
        ]);
    }

    public function test_resyncing_orders_does_not_duplicate_them(): void
    {
        $this->fakeShopify([
            'orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Order/100',
                        'name' => '#1001',
                        'createdAt' => '2026-07-15T08:30:00Z',
                        'displayFinancialStatus' => 'PAID',
                        'displayFulfillmentStatus' => 'FULFILLED',
                        'totalPriceSet' => ['shopMoney' => ['amount' => '19.99']],
                        'customer' => null,
                        'lineItems' => [
                            'edges' => [
                                ['node' => [
                                    'id' => 'gid://shopify/LineItem/1000',
                                    'quantity' => 1,
                                    'title' => 'T-Shirt',
                                    'originalUnitPriceSet' => ['shopMoney' => ['amount' => '19.99']],
                                    'variant' => [
                                        'id' => 'gid://shopify/ProductVariant/11',
                                        'sku' => 'TS-S',
                                        'title' => 'Default Title',
                                        'price' => '19.99',
                                        'product' => [
                                            'id' => 'gid://shopify/Product/1',
                                            'title' => 'T-Shirt',
                                            'productType' => 'Apparel',
                                        ],
                                    ],
                                ]],
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        $sync = app(ShopifySync::class);

        $sync->syncOrders();
        $sync->syncOrders();

        $this->assertSame(1, Order::count());
        $this->assertSame(1, OrderItem::count());
        $this->assertSame(1, Product::count());
    }

    public function test_shopify_sync_command_syncs_customers(): void
    {
        $this->fakeShopify([
            'customers' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Customer/1',
                        'displayName' => 'Jane Doe',
                        'email' => 'jane@example.com',
                        'createdAt' => '2026-07-01T10:00:00Z',
                        'defaultAddress' => ['country' => 'United States'],
                    ]],
                ],
            ],
        ]);

        $this->artisan('shopify:sync', ['--type' => 'customers'])
            ->expectsOutput('Syncing customers...')
            ->expectsOutput('Synced 1 customers.')
            ->expectsOutput('Shopify sync complete.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('customers', [
            'shopify_id' => 'gid://shopify/Customer/1',
        ]);
    }

    public function test_shopify_sync_command_rejects_unknown_types(): void
    {
        $this->artisan('shopify:sync', ['--type' => 'bogus'])
            ->expectsOutput('Invalid type [bogus]. Expected products, customers, orders or all.')
            ->assertExitCode(1);
    }

    public function test_shopify_sync_reset_flag_clears_existing_data(): void
    {
        Product::create(['name' => 'Old Product', 'sku' => 'OLD-1', 'category' => 'X', 'price' => 1, 'cost' => 0, 'stock' => 1]);
        Customer::create(['name' => 'Old Customer', 'email' => 'old@example.com', 'country' => 'US']);

        $this->fakeShopify([
            'products' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [],
            ],
        ]);

        $this->artisan('shopify:sync', ['--type' => 'products', '--reset' => true])
            ->expectsOutput('Removed existing local data for: products.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('products', ['sku' => 'OLD-1']);
    }

    public function test_it_ingests_fulfillments_with_tracking_into_shipments_table(): void
    {
        $this->seed(CourierProvidersSeeder::class);

        $this->fakeShopify([
            'orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Order/300',
                        'name' => '#2001',
                        'createdAt' => '2026-08-01T10:00:00Z',
                        'displayFinancialStatus' => 'PAID',
                        'displayFulfillmentStatus' => 'FULFILLED',
                        'totalPriceSet' => ['shopMoney' => ['amount' => '50.00']],
                        'customer' => [
                            'id' => 'gid://shopify/Customer/1',
                            'displayName' => 'Jane Doe',
                            'email' => 'jane@example.com',
                            'createdAt' => '2026-07-01T10:00:00Z',
                            'defaultAddress' => ['country' => 'Pakistan'],
                        ],
                        'lineItems' => ['edges' => []],
                        'shippingAddress' => [
                            'name' => 'Jane Doe',
                            'address1' => 'House 1, Gulberg',
                            'address2' => null,
                            'city' => 'Lahore',
                            'province' => null,
                            'zip' => '54000',
                            'country' => 'Pakistan',
                        ],
                        'fulfillments' => [
                            [
                                'id' => 'gid://shopify/Fulfillment/9001',
                                'status' => 'SUCCESS',
                                'displayStatus' => 'FULFILLED',
                                'createdAt' => '2026-08-02T10:00:00Z',
                                'updatedAt' => '2026-08-02T10:00:00Z',
                                'deliveredAt' => null,
                                'estimatedDeliveryAt' => null,
                                'trackingInfo' => [
                                    ['number' => 'LP123456789', 'company' => 'Leopards', 'url' => 'https://track.leopardscourier.com/?cn=LP123456789'],
                                ],
                                'originAddress' => [
                                    'address1' => 'Warehouse 1',
                                    'address2' => null,
                                    'city' => 'Karachi',
                                    'provinceCode' => null,
                                    'zip' => '75000',
                                    'countryCode' => 'PK',
                                ],
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        app(ShopifySync::class)->syncOrders();

        $order = Order::query()->where('shopify_id', 'gid://shopify/Order/300')->firstOrFail();
        $shipment = Shipment::query()->where('external_id', 'gid://shopify/Fulfillment/9001')->firstOrFail();

        $this->assertSame($order->id, $shipment->order_id);
        $this->assertSame('shopify', $shipment->matched_method);
        $this->assertSame('LP123456789', $shipment->tracking_number);
        $this->assertSame('Leopards', $shipment->carrier_name);
        $this->assertSame('https://track.leopardscourier.com/?cn=LP123456789', $shipment->tracking_url);
        $this->assertSame('in_transit', $shipment->status->value);
        $this->assertSame(1, $shipment->events()->count(), 'an event should be recorded on ingest');
        $this->assertSame('Warehouse 1', $shipment->consignor_address);
        $this->assertSame('Karachi', $shipment->consignor_city);
        $this->assertNull($shipment->consignor_name, 'FulfillmentOriginAddress has no name field');

        // Consignee details now come from the order's stored shipping address.
        $this->assertSame('Jane Doe', $shipment->consignee_name);
        $this->assertSame('House 1, Gulberg', $shipment->consignee_address);
        $this->assertSame('Lahore', $shipment->consignee_city);
    }

    public function test_it_marks_a_fulfillment_as_delivered_when_shopify_has_a_delivered_at(): void
    {
        $this->seed(CourierProvidersSeeder::class);

        $this->fakeShopify([
            'orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Order/301',
                        'name' => '#2002',
                        'createdAt' => '2026-08-01T10:00:00Z',
                        'displayFinancialStatus' => 'PAID',
                        'displayFulfillmentStatus' => 'FULFILLED',
                        'totalPriceSet' => ['shopMoney' => ['amount' => '50.00']],
                        'customer' => null,
                        'lineItems' => ['edges' => []],
                        'fulfillments' => [
                            [
                                'id' => 'gid://shopify/Fulfillment/9002',
                                'status' => 'SUCCESS',
                                'displayStatus' => 'DELIVERED',
                                'createdAt' => '2026-08-02T10:00:00Z',
                                'updatedAt' => '2026-08-04T10:00:00Z',
                                'deliveredAt' => '2026-08-04T09:30:00Z',
                                'estimatedDeliveryAt' => null,
                                'trackingInfo' => [
                                    ['number' => 'DLV-001', 'company' => 'DHL', 'url' => 'https://www.dhl.com/track?id=DLV-001'],
                                ],
                                'originAddress' => null,
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        app(ShopifySync::class)->syncOrders();

        $shipment = Shipment::query()->where('external_id', 'gid://shopify/Fulfillment/9002')->firstOrFail();
        $this->assertSame('delivered', $shipment->status->value);
        $this->assertNotNull($shipment->delivered_at);
        $this->assertSame('DHL', $shipment->carrier_name);
    }

    public function test_fulfillments_without_tracking_numbers_are_skipped(): void
    {
        $this->seed(CourierProvidersSeeder::class);

        $this->fakeShopify([
            'orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Order/302',
                        'name' => '#2003',
                        'createdAt' => '2026-08-01T10:00:00Z',
                        'displayFinancialStatus' => 'PAID',
                        'displayFulfillmentStatus' => 'UNFULFILLED',
                        'totalPriceSet' => ['shopMoney' => ['amount' => '0']],
                        'customer' => null,
                        'lineItems' => ['edges' => []],
                        'fulfillments' => [
                            [
                                'id' => 'gid://shopify/Fulfillment/9003',
                                'status' => 'OPEN',
                                'displayStatus' => null,
                                'createdAt' => '2026-08-02T10:00:00Z',
                                'updatedAt' => '2026-08-02T10:00:00Z',
                                'deliveredAt' => null,
                                'estimatedDeliveryAt' => null,
                                'trackingInfo' => [['number' => null, 'company' => null, 'url' => null]],
                                'originAddress' => null,
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        app(ShopifySync::class)->syncOrders();

        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_resyncing_the_same_fulfillment_does_not_duplicate_the_shipment(): void
    {
        $this->seed(CourierProvidersSeeder::class);

        $fulfillment = [
            'id' => 'gid://shopify/Fulfillment/9004',
            'status' => 'SUCCESS',
            'displayStatus' => 'IN_TRANSIT',
            'createdAt' => '2026-08-02T10:00:00Z',
            'updatedAt' => '2026-08-02T10:00:00Z',
            'deliveredAt' => null,
            'estimatedDeliveryAt' => null,
            'trackingInfo' => [['number' => 'RE-001', 'company' => 'TCS', 'url' => 'https://tcs.example/RE-001']],
            'originAddress' => null,
        ];

        $this->fakeShopify([
            'orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Order/304',
                        'name' => '#2004',
                        'createdAt' => '2026-08-01T10:00:00Z',
                        'displayFinancialStatus' => 'PAID',
                        'displayFulfillmentStatus' => 'FULFILLED',
                        'totalPriceSet' => ['shopMoney' => ['amount' => '0']],
                        'customer' => null,
                        'lineItems' => ['edges' => []],
                        'fulfillments' => [$fulfillment],
                    ]],
                ],
            ],
        ]);

        $sync = app(ShopifySync::class);
        $sync->syncOrders();
        $sync->syncOrders();

        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_fulfillment_tracking_update_overrides_the_local_status_on_resync(): void
    {
        $this->seed(CourierProvidersSeeder::class);

        $make = function (string $displayStatus, ?string $deliveredAt): array {
            return [
                'orders' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges' => [
                        ['node' => [
                            'id' => 'gid://shopify/Order/305',
                            'name' => '#2005',
                            'createdAt' => '2026-08-01T10:00:00Z',
                            'displayFinancialStatus' => 'PAID',
                            'displayFulfillmentStatus' => 'FULFILLED',
                            'totalPriceSet' => ['shopMoney' => ['amount' => '0']],
                            'customer' => null,
                            'lineItems' => ['edges' => []],
                            'fulfillments' => [
                                [
                                    'id' => 'gid://shopify/Fulfillment/9005',
                                    'status' => 'SUCCESS',
                                    'displayStatus' => $displayStatus,
                                    'createdAt' => '2026-08-02T10:00:00Z',
                                    'updatedAt' => '2026-08-02T10:00:00Z',
                                    'deliveredAt' => $deliveredAt,
                                    'estimatedDeliveryAt' => null,
                                    'trackingInfo' => [['number' => 'UP-001', 'company' => 'TCS', 'url' => null]],
                                    'originAddress' => null,
                                ],
                            ],
                        ]],
                    ],
                ],
            ];
        };

        Http::fake([
            'https://test-store.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_test',
                'expires_in' => 86399,
            ]),
            'https://test-store.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
                ->push(['data' => $make('IN_TRANSIT', null)])
                ->push(['data' => $make('DELIVERED', '2026-08-04T09:00:00Z')]),
        ]);

        $sync = app(ShopifySync::class);
        $sync->syncOrders();
        $shipment = Shipment::query()->firstOrFail();
        $this->assertSame('in_transit', $shipment->status->value);

        $sync->syncOrders();
        $shipment->refresh();
        $this->assertSame('delivered', $shipment->status->value);
        $this->assertNotNull($shipment->delivered_at);
    }

    public function test_the_orders_query_uses_the_plain_fulfillments_list_shape(): void
    {
        $this->fakeShopify([
            'orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'edges' => []],
        ]);

        app(ShopifySync::class)->syncOrders();

        // This guards the exact regression that broke the live sync: the query
        // must treat Order.fulfillments as a plain list ([Fulfillment!]!), not
        // a connection, and must not request FulfillmentOriginAddress.name.
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://test-store.myshopify.com/admin/api/2026-07/graphql.json') {
                return false;
            }

            $query = (string) $request['query'];

            return str_contains($query, 'fragment OrderFields on Order')
                && str_contains($query, 'fulfillments(first: 50)')
                && str_contains($query, 'trackingInfo(first: 10)')
                && preg_match('/fulfillments\([^)]*\)\s*\{\s*edges/', $query) !== 1
                && preg_match('/originAddress\s*\{\s*name/', $query) !== 1;
        });
    }

    public function test_it_uses_the_first_tracking_entry_when_a_fulfillment_has_multiple_packages(): void
    {
        $this->seed(CourierProvidersSeeder::class);

        $this->fakeShopify([
            'orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Order/306',
                        'name' => '#2006',
                        'createdAt' => '2026-08-01T10:00:00Z',
                        'displayFinancialStatus' => 'PAID',
                        'displayFulfillmentStatus' => 'FULFILLED',
                        'totalPriceSet' => ['shopMoney' => ['amount' => '0']],
                        'customer' => null,
                        'lineItems' => ['edges' => []],
                        'fulfillments' => [
                            [
                                'id' => 'gid://shopify/Fulfillment/9006',
                                'status' => 'SUCCESS',
                                'displayStatus' => 'IN_TRANSIT',
                                'createdAt' => '2026-08-02T10:00:00Z',
                                'updatedAt' => '2026-08-02T10:00:00Z',
                                'deliveredAt' => null,
                                'estimatedDeliveryAt' => null,
                                'trackingInfo' => [
                                    ['number' => 'PKG-01', 'company' => 'TCS', 'url' => 'https://tcs.example/PKG-01'],
                                    ['number' => 'PKG-02', 'company' => 'TCS', 'url' => 'https://tcs.example/PKG-02'],
                                ],
                                'originAddress' => null,
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        app(ShopifySync::class)->syncOrders();

        $shipment = Shipment::query()->where('external_id', 'gid://shopify/Fulfillment/9006')->firstOrFail();
        $this->assertSame('PKG-01', $shipment->tracking_number);
        $this->assertSame(1, Shipment::query()->count());
    }

    public function test_it_still_accepts_legacy_connection_shaped_fulfillments(): void
    {
        $this->seed(CourierProvidersSeeder::class);

        $this->fakeShopify([
            'orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Order/307',
                        'name' => '#2007',
                        'createdAt' => '2026-08-01T10:00:00Z',
                        'displayFinancialStatus' => 'PAID',
                        'displayFulfillmentStatus' => 'FULFILLED',
                        'totalPriceSet' => ['shopMoney' => ['amount' => '0']],
                        'customer' => null,
                        'lineItems' => ['edges' => []],
                        // Old connection shape with a single-map trackingInfo,
                        // e.g. payloads produced by queued webhook jobs that
                        // started before this fix was deployed.
                        'fulfillments' => [
                            'edges' => [
                                ['node' => [
                                    'id' => 'gid://shopify/Fulfillment/9007',
                                    'status' => 'SUCCESS',
                                    'displayStatus' => 'IN_TRANSIT',
                                    'createdAt' => '2026-08-02T10:00:00Z',
                                    'updatedAt' => '2026-08-02T10:00:00Z',
                                    'deliveredAt' => null,
                                    'estimatedDeliveryAt' => null,
                                    'trackingInfo' => ['number' => 'LEG-001', 'company' => 'TCS', 'url' => null],
                                    'originAddress' => null,
                                ]],
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        app(ShopifySync::class)->syncOrders();

        $shipment = Shipment::query()->where('external_id', 'gid://shopify/Fulfillment/9007')->firstOrFail();
        $this->assertSame('LEG-001', $shipment->tracking_number);
    }

    public function test_it_syncs_variant_weight_normalised_to_kilograms(): void
    {
        $this->fakeShopify([
            'products' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Product/2',
                        'title' => 'Candle',
                        'productType' => 'Home & Living',
                        'variants' => [
                            'edges' => [
                                ['node' => [
                                    'id' => 'gid://shopify/ProductVariant/21',
                                    'title' => 'Default Title',
                                    'sku' => 'CDL-S',
                                    'price' => '10.00',
                                    'inventoryQuantity' => 5,
                                    'inventoryItem' => [
                                        'unitCost' => null,
                                        'measurement' => [
                                            'weight' => ['value' => 250, 'unit' => 'GRAMS'],
                                        ],
                                    ],
                                ]],
                                ['node' => [
                                    'id' => 'gid://shopify/ProductVariant/22',
                                    'title' => 'Large',
                                    'sku' => 'CDL-L',
                                    'price' => '15.00',
                                    'inventoryQuantity' => 3,
                                    'inventoryItem' => [
                                        'unitCost' => null,
                                        'measurement' => [
                                            'weight' => ['value' => 2.5, 'unit' => 'POUNDS'],
                                        ],
                                    ],
                                ]],
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        app(ShopifySync::class)->syncProducts();

        // 250 g → 0.25 kg.
        $this->assertDatabaseHas('products', [
            'shopify_id' => 'gid://shopify/ProductVariant/21',
            'weight_kg' => 0.25,
        ]);

        // 2.5 lb ≈ 1.134 kg.
        $this->assertDatabaseHas('products', [
            'shopify_id' => 'gid://shopify/ProductVariant/22',
            'weight_kg' => 1.134,
        ]);
    }

    public function test_shopify_fulfillment_shipment_inherits_the_order_product_weight(): void
    {
        $this->seed(CourierProvidersSeeder::class);

        $this->fakeShopify([
            'orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'edges' => [
                    ['node' => [
                        'id' => 'gid://shopify/Order/400',
                        'name' => '#3001',
                        'createdAt' => '2026-08-05T10:00:00Z',
                        'displayFinancialStatus' => 'PAID',
                        'displayFulfillmentStatus' => 'FULFILLED',
                        'totalPriceSet' => ['shopMoney' => ['amount' => '39.98']],
                        'customer' => null,
                        'lineItems' => [
                            'edges' => [
                                ['node' => [
                                    'id' => 'gid://shopify/LineItem/4000',
                                    'quantity' => 2,
                                    'title' => 'Ceramic Mug',
                                    'originalUnitPriceSet' => null,
                                    'variant' => [
                                        'id' => 'gid://shopify/ProductVariant/41',
                                        'sku' => 'MUG-500',
                                        'title' => 'Default Title',
                                        'price' => '19.99',
                                        'inventoryItem' => [
                                            'measurement' => [
                                                'weight' => ['value' => 0.5, 'unit' => 'KILOGRAMS'],
                                            ],
                                        ],
                                        'product' => [
                                            'id' => 'gid://shopify/Product/4',
                                            'title' => 'Ceramic Mug',
                                            'productType' => 'Home & Living',
                                        ],
                                    ],
                                ]],
                            ],
                        ],
                        'fulfillments' => [
                            [
                                'id' => 'gid://shopify/Fulfillment/9400',
                                'status' => 'SUCCESS',
                                'displayStatus' => 'IN_TRANSIT',
                                'createdAt' => '2026-08-05T12:00:00Z',
                                'updatedAt' => '2026-08-05T12:00:00Z',
                                'deliveredAt' => null,
                                'estimatedDeliveryAt' => null,
                                'trackingInfo' => [
                                    ['number' => 'SFY-WT-001', 'company' => 'TCS', 'url' => null],
                                ],
                                'originAddress' => null,
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        app(ShopifySync::class)->syncOrders();

        // The product was created from the line item with its weight, the
        // order item quantity is 2, so the linked shipment weighs 1.0 kg.
        $this->assertDatabaseHas('products', [
            'shopify_id' => 'gid://shopify/ProductVariant/41',
            'weight_kg' => 0.5,
        ]);

        $shipment = Shipment::query()->where('tracking_number', 'SFY-WT-001')->firstOrFail();
        $this->assertSame('1.000', (string) $shipment->weight_kg);
    }

}
