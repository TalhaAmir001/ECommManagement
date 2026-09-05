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
     * Fake the Shopify OAuth token exchange plus a fulfillment-list response.
     */
    private function fakeShopifyHttp(array $fulfillments = []): void
    {
        Http::fake([
            '*/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_test', 'expires_in' => 3600], 200),
            '*/fulfillments*' => Http::response(['fulfillments' => $fulfillments]),
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
        $this->fakeShopifyHttp(); // empty list -> the service creates one

        $shipment = $this->makeShipment($this->makeOrder());

        app(ShopifyTrackingService::class)->pushTracking($shipment);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/admin/api/2026-07/orders/123/fulfillments.json')
                && $request['fulfillment']['tracking_number'] === 'LP-12345'
                && $request['fulfillment']['tracking_company'] === 'Leopards';
        });
    }

    public function test_updates_an_existing_fulfillment_instead_of_duplicating_it(): void
    {
        Http::fake([
            '*/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_test', 'expires_in' => 3600], 200),
            '*/fulfillments*' => function (Request $request) {
                if ($request->method() === 'GET') {
                    return Http::response(['fulfillments' => [['id' => 55]]]);
                }

                return Http::response(['fulfillment' => ['id' => 55]]);
            },
        ]);

        $shipment = $this->makeShipment($this->makeOrder());

        app(ShopifyTrackingService::class)->pushTracking($shipment);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT'
                && str_ends_with($request->url(), '/admin/api/2026-07/orders/123/fulfillments/55.json')
                && $request['fulfillment']['tracking_number'] === 'LP-12345';
        });

        // No fulfillment creation happens when one already exists (the only
        // POST allowed is the OAuth token exchange, which has no fulfillment
        // path).
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/fulfillments'));
    }

    public function test_noops_when_the_order_is_not_a_shopify_order(): void
    {
        $this->fakeShopifyHttp();

        $shipment = $this->makeShipment($this->makeOrder(shopifyId: null));

        app(ShopifyTrackingService::class)->pushTracking($shipment);

        Http::assertNothingSent();
    }

    public function test_noops_when_the_shipment_has_no_tracking_number(): void
    {
        $this->fakeShopifyHttp();

        $shipment = $this->makeShipment($this->makeOrder(), ['tracking_number' => '']);

        app(ShopifyTrackingService::class)->pushTracking($shipment);

        Http::assertNothingSent();
    }

    public function test_noops_for_auto_generated_manual_placeholder_numbers(): void
    {
        $this->fakeShopifyHttp();

        $shipment = $this->makeShipment($this->makeOrder(), ['tracking_number' => 'MNL-ABC12345']);

        app(ShopifyTrackingService::class)->pushTracking($shipment);

        Http::assertNothingSent();
    }

    public function test_logs_and_swallows_shopify_failures(): void
    {
        Http::fake([
            '*/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_test', 'expires_in' => 3600], 200),
            '*/fulfillments*' => Http::response(null, 500),
        ]);

        $shipment = $this->makeShipment($this->makeOrder());

        // Must not throw — a Shopify outage never blocks the local row.
        app(ShopifyTrackingService::class)->pushTracking($shipment);

        // It attempted the write (and failed) instead of skipping silently.
        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
        $this->assertDatabaseHas('shipments', ['id' => $shipment->id]);
    }

    public function test_resolves_the_numeric_shopify_order_id_from_the_gid(): void
    {
        $order = $this->makeOrder('gid://shopify/Order/987654321');

        $this->assertSame('987654321', $order->shopifyNumericId());
    }
}
