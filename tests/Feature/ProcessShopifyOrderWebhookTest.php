<?php

namespace Tests\Feature;

use App\Jobs\ProcessShopifyOrderWebhook;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifySync;
use Database\Seeders\CourierProvidersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessShopifyOrderWebhookTest extends TestCase
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
     * orderById shares its field selection with the batch orders sync, so the
     * webhook path ingests fulfillments into shipments exactly like a manual
     * shopify:sync run does.
     */
    public function test_the_order_webhook_upserts_the_order_and_ingests_its_fulfillments(): void
    {
        $this->seed(CourierProvidersSeeder::class);

        Http::fake([
            'https://test-store.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_test',
                'expires_in' => 86399,
            ]),
            'https://test-store.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => [
                    'order' => [
                        'id' => 'gid://shopify/Order/400',
                        'name' => '#3001',
                        'createdAt' => '2026-08-01T10:00:00Z',
                        'displayFinancialStatus' => 'PAID',
                        'displayFulfillmentStatus' => 'FULFILLED',
                        'totalPriceSet' => ['shopMoney' => ['amount' => '120.00']],
                        'customer' => null,
                        'lineItems' => ['edges' => []],
                        'fulfillments' => [
                            [
                                'id' => 'gid://shopify/Fulfillment/9101',
                                'status' => 'SUCCESS',
                                'displayStatus' => 'IN_TRANSIT',
                                'createdAt' => '2026-08-02T10:00:00Z',
                                'updatedAt' => '2026-08-02T10:00:00Z',
                                'deliveredAt' => null,
                                'estimatedDeliveryAt' => null,
                                'trackingInfo' => [
                                    ['number' => 'WB-77', 'company' => 'TCS', 'url' => 'https://tcs.example/WB-77'],
                                ],
                                'originAddress' => null,
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        (new ProcessShopifyOrderWebhook('400', 'orders/fulfilled'))->handle(
            app(ShopifyClient::class),
            app(ShopifySync::class)
        );

        $order = Order::query()->where('shopify_id', 'gid://shopify/Order/400')->firstOrFail();
        $shipment = Shipment::query()->where('external_id', 'gid://shopify/Fulfillment/9101')->firstOrFail();

        $this->assertSame($order->id, $shipment->order_id);
        $this->assertSame('WB-77', $shipment->tracking_number);
        $this->assertSame('TCS', $shipment->carrier_name);
    }
}
