<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Shopify\ShopifySync;
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
}
