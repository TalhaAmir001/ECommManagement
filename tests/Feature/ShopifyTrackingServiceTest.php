<?php

namespace Tests\Feature;

use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Shopify\ShopifyTrackingService;
use Database\Seeders\CourierProvidersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyTrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CourierProvidersSeeder::class);

        config()->set('shopify.shop', 'test-store');
        config()->set('shopify.api_version', '2026-07');
        config()->set('shopify.client_id', 'client-id');
        config()->set('shopify.client_secret', 'client-secret');
    }

    /**
     * Fake the Shopify OAuth token exchange and a GraphQL admin API that
     * answers the fulfillment-state query and both mutations.
     */
    private function fakeShopifyGraphql(): void
    {
        Http::fake([
            '*/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_test', 'expires_in' => 3600], 200),
            '*/graphql.json' => function (Request $request) {
                $query = (string) ($request['query'] ?? '');

                if (str_contains($query, 'ShopifyOrderFulfillmentState')) {
                    return Http::response(['data' => [
                        'order' => [
                            'id' => 'gid://shopify/Order/123',
                            'fulfillments' => [],
                            'fulfillmentOrders' => [
                                'edges' => [[
                                    'node' => ['id' => 'gid://shopify/FulfillmentOrder/55'],
                                ]],
                            ],
                        ],
                    ]], 200);
                }

                if (str_contains($query, 'fulfillmentCreate')) {
                    return Http::response(['data' => ['fulfillmentCreate' => ['userErrors' => []]]], 200);
                }

                if (str_contains($query, 'fulfillmentTrackingInfoUpdate')) {
                    return Http::response(['data' => ['fulfillmentTrackingInfoUpdate' => ['userErrors' => []]]], 200);
                }

                return Http::response(['data' => []], 200);
            },
        ]);
    }

    private function makeManualProvider(): CourierProvider
    {
        return CourierProvider::query()->where('key', 'manual')->firstOrFail();
    }

    private function makeOrder(?string $shopifyId = 'gid://shopify/Order/123'): Order
    {
        $customer = Customer::factory()->create();

        return Order::factory()->create([
            'customer_id' => $customer->id,
            'number' => '#2000',
            'shopify_id' => $shopifyId,
        ]);
    }

    private function makeShipment(Order $order, array $attributes = []): Shipment
    {
        return Shipment::create(array_merge([
            'courier_provider_id' => $this->makeManualProvider()->id,
            'external_id' => 'MANUAL-'.strtoupper(bin2hex(random_bytes(4))),
            'tracking_number' => 'LP-12345',
            'carrier_name' => 'Leopards',
            'order_id' => $order->id,
            'matched_method' => 'manual',
            'matched_at' => now(),
            'status' => 'created',
            'currency' => 'PKR',
            'shipped_at' => now(),
            'last_event_at' => now(),
        ], $attributes));
    }

    public function test_creates_a_fulfillment_when_the_order_has_none(): void
    {
        $this->fakeShopifyGraphql();

        $shipment = $this->makeShipment($this->makeOrder());

        app(ShopifyTrackingService::class)->pushTracking($shipment);

        Http::assertSent(function (Request $request) {
            $payload = json_decode((string) $request->body(), true);
            $fulfillment = $payload['variables']['fulfillment'] ?? [];

            return str_contains((string) $request->url(), '/graphql.json')
                && str_contains((string) ($payload['query'] ?? ''), 'fulfillmentCreate')
                && ($fulfillment['trackingInfo']['number'] ?? null) === 'LP-12345'
                && ($fulfillment['trackingInfo']['company'] ?? null) === 'Leopards'
                && ($fulfillment['notifyCustomer'] ?? null) === false
                && ($fulfillment['lineItemsByFulfillmentOrder'][0]['fulfillmentOrderId'] ?? null) === 'gid://shopify/FulfillmentOrder/55'
                && ! array_key_exists('fulfillmentOrderLineItems', $fulfillment['lineItemsByFulfillmentOrder'][0]);
        });
    }

    public function test_updates_an_existing_fulfillment_instead_of_duplicating_it(): void
    {
        Http::fake([
            '*/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_test', 'expires_in' => 3600], 200),
            '*/graphql.json' => function (Request $request) {
                $query = (string) ($request['query'] ?? '');

                if (str_contains($query, 'ShopifyOrderFulfillmentState')) {
                    return Http::response(['data' => [
                        'order' => [
                            'id' => 'gid://shopify/Order/123',
                            'fulfillments' => [['id' => 'gid://shopify/Fulfillment/9']],
                            'fulfillmentOrders' => ['edges' => []],
                        ],
                    ]], 200);
                }

                return Http::response(['data' => ['fulfillmentTrackingInfoUpdate' => ['userErrors' => []]]], 200);
            },
        ]);

        $shipment = $this->makeShipment($this->makeOrder());

        app(ShopifyTrackingService::class)->pushTracking($shipment);

        Http::assertSent(function (Request $request) {
            $payload = json_decode((string) $request->body(), true);
            $variables = $payload['variables'] ?? [];

            return str_contains((string) $request->url(), '/graphql.json')
                && str_contains((string) ($payload['query'] ?? ''), 'fulfillmentTrackingInfoUpdate')
                && ($variables['fulfillmentId'] ?? null) === 'gid://shopify/Fulfillment/9'
                && ($variables['trackingInfoInput']['number'] ?? null) === 'LP-12345';
        });

        // No fulfillment is created when one already exists.
        Http::assertNotSent(function (Request $request) {
            $payload = json_decode((string) $request->body(), true);

            return str_contains((string) ($payload['query'] ?? ''), 'fulfillmentCreate');
        });
    }

    public function test_noops_when_the_order_is_not_a_shopify_order(): void
    {
        $this->fakeShopifyGraphql();

        $shipment = $this->makeShipment($this->makeOrder(shopifyId: null));

        app(ShopifyTrackingService::class)->pushTracking($shipment);

        Http::assertNothingSent();
    }

    public function test_noops_when_the_shipment_has_no_tracking_number(): void
    {
        $this->fakeShopifyGraphql();

        $shipment = $this->makeShipment($this->makeOrder(), ['tracking_number' => '']);

        app(ShopifyTrackingService::class)->pushTracking($shipment);

        Http::assertNothingSent();
    }

    public function test_pushes_an_auto_generated_numeric_tracking_number(): void
    {
        $this->fakeShopifyGraphql();

        $shipment = $this->makeShipment($this->makeOrder(), ['tracking_number' => '78451230987126']);

        app(ShopifyTrackingService::class)->pushTracking($shipment);

        Http::assertSent(function (Request $request) {
            $payload = json_decode((string) $request->body(), true);
            $fulfillment = $payload['variables']['fulfillment'] ?? [];

            return str_contains((string) ($payload['query'] ?? ''), 'fulfillmentCreate')
                && ($fulfillment['trackingInfo']['number'] ?? null) === '78451230987126';
        });
    }

    public function test_logs_and_swallows_shopify_failures(): void
    {
        Http::fake([
            '*/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_test', 'expires_in' => 3600], 200),
            '*/graphql.json' => Http::response(null, 500),
        ]);

        $shipment = $this->makeShipment($this->makeOrder());

        // Must not throw — a Shopify outage never blocks the local row.
        app(ShopifyTrackingService::class)->pushTracking($shipment);

        // It attempted the write (and failed) instead of skipping silently.
        Http::assertSent(fn (Request $request) => str_contains((string) $request->url(), '/graphql.json'));
        $this->assertDatabaseHas('shipments', ['id' => $shipment->id]);
    }

    public function test_resolves_the_numeric_shopify_order_id_from_the_gid(): void
    {
        $order = $this->makeOrder('gid://shopify/Order/987654321');

        $this->assertSame('987654321', $order->shopifyNumericId());
    }
}
