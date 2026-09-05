<?php

namespace Tests\Feature;

use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Courier\CourierProviderRegistry;
use App\Services\Courier\Exceptions\CapabilityUnsupportedException;
use App\Services\Courier\Providers\ManualProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShipmentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CourierProvidersSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_manual_provider_throws_when_asked_to_create_a_label(): void
    {
        $provider = $this->resolveManual();

        $this->expectException(CapabilityUnsupportedException::class);
        $provider->createLabel(new \App\Services\Courier\CourierLabelRequest(
            order: \App\Models\Order::factory()->create(),
            consignor: new \App\Services\Courier\Normalization\Address(),
            consignee: new \App\Services\Courier\Normalization\Address(),
            weightKg: 1.0,
        ));
    }

    public function test_manual_provider_lists_shipments_from_the_database(): void
    {
        $provider = $this->resolveManual();
        $shipment = $this->makeShipment(['tracking_number' => 'MNP-LIST-1']);

        $listed = $provider->listShipments();
        $this->assertCount(1, $listed);
        $this->assertSame('MNP-LIST-1', $listed->first()->trackingNumber);
        $this->assertSame($shipment->id, Shipment::query()->where('tracking_number', 'MNP-LIST-1')->value('id'));
    }

    public function test_manual_provider_returns_a_single_shipment_by_external_id(): void
    {
        $provider = $this->resolveManual();
        $shipment = $this->makeShipment(['external_id' => 'MNP-XYZ-001']);

        $found = $provider->getShipment('MNP-XYZ-001');
        $this->assertNotNull($found);
        $this->assertSame('MNP-XYZ-001', $found->externalId);
    }

    public function test_append_event_updates_shipment_status_and_records_event(): void
    {
        $provider = $this->resolveManual();
        $shipment = $this->makeShipment([
            'status' => ShipmentStatus::Created->value,
            'last_event_at' => now()->subHour(),
        ]);

        $event = $provider->appendEvent(
            $shipment,
            ShipmentStatus::PickedUp,
            'Picked up from warehouse',
            'Karachi Hub',
            now(),
        );

        $this->assertSame(ShipmentStatus::PickedUp->value, $event->status->value);
        $this->assertSame(1, $shipment->events()->count());
        $shipment->refresh();
        $this->assertSame(ShipmentStatus::PickedUp->value, $shipment->status->value);
    }

    public function test_manual_provider_capabilities_match_the_config(): void
    {
        $provider = $this->resolveManual();
        $caps = $provider->capabilities();

        $this->assertTrue($provider->supports(\App\Enums\Courier\Capability::ReadShipments));
        $this->assertTrue($provider->supports(\App\Enums\Courier\Capability::ReadEvents));
        $this->assertTrue($provider->supports(\App\Enums\Courier\Capability::CodSupport));
        $this->assertFalse($provider->supports(\App\Enums\Courier\Capability::CreateLabel));
        $this->assertFalse($provider->supports(\App\Enums\Courier\Capability::CancelShipment));
    }

    public function test_registry_resolves_each_known_driver_class(): void
    {
        $registry = $this->app->make(CourierProviderRegistry::class);
        foreach (CourierProvider::query()->get() as $row) {
            $instance = $registry->resolve($row);
            $this->assertInstanceOf(\App\Services\Courier\CourierProvider::class, $instance);
            $this->assertSame($row->key, $instance->key());
        }
    }

    public function test_new_shipment_form_has_consignee_email_and_no_reference_field(): void
    {
        $this->get('/shipments?show_form=create')
            ->assertOk()
            ->assertSee('Consignee email')
            ->assertDontSee('Reference (order #)');
    }

    public function test_new_shipment_form_persists_consignee_email(): void
    {
        $this->post('/shipments', [
            'tracking_number' => 'NEW-EMAIL-1',
            'consignee_name' => 'Ali Khan',
            'consignee_phone' => '03001234567',
            'consignee_email' => 'ali@example.com',
        ])->assertRedirect();

        $shipment = Shipment::query()->where('tracking_number', 'NEW-EMAIL-1')->firstOrFail();
        $this->assertSame('ali@example.com', $shipment->consignee_email);
        $this->assertSame('Ali Khan', $shipment->consignee_name);
    }

    public function test_shipments_index_page_renders_linked_shipments_with_manual_provider(): void
    {
        $orderOne = \App\Models\Order::factory()->create(['number' => '#INDEX-1']);
        $orderTwo = \App\Models\Order::factory()->create(['number' => '#INDEX-2']);

        $this->makeShipment([
            'tracking_number' => 'MNP-INDEX-1',
            'order_id' => $orderOne->id,
            'matched_method' => 'manual',
        ]);
        $this->makeShipment([
            'tracking_number' => 'MNP-INDEX-2',
            'consignee_name' => 'Ali Khan',
            'order_id' => $orderTwo->id,
            'matched_method' => 'manual',
        ]);

        $this->get('/shipments')
            ->assertOk()
            ->assertSee('Shipments')
            ->assertSee('MNP-INDEX-1')
            ->assertSee('MNP-INDEX-2')
            ->assertSee('Ali Khan')
            ->assertSee('Manual Entry');
    }

    public function test_shipments_index_page_hides_unlinked_shipments(): void
    {
        // A shipment with no linked order is hidden from the list — it can
        // still be reached and linked from the shipment detail page.
        $this->makeShipment(['tracking_number' => 'MNP-ORPHAN-1', 'consignee_name' => 'Ali Khan']);

        $this->get('/shipments')
            ->assertOk()
            ->assertDontSee('MNP-ORPHAN-1');
    }

    public function test_shipments_show_page_renders_event_timeline(): void
    {
        $shipment = $this->makeShipment(['tracking_number' => 'MNP-SHOW-1']);
        $shipment->events()->create([
            'status' => ShipmentStatus::PickedUp->value,
            'occurred_at' => now()->subHour(),
            'description' => 'Picked up from warehouse',
            'location' => 'Karachi',
        ]);

        $this->get('/shipments/'.$shipment->id)
            ->assertOk()
            ->assertSee('MNP-SHOW-1')
            ->assertSee('Tracking timeline')
            ->assertSee('Picked up from warehouse')
            ->assertSee('Karachi');
    }

    public function test_orders_page_shows_tracking_cell_for_shipped_orders(): void
    {
        $customer = \App\Models\Customer::factory()->create();
        $order = \App\Models\Order::factory()->create([
            'customer_id' => $customer->id,
            'number' => '#3001',
        ]);
        $this->makeShipment([
            'tracking_number' => 'MNP-ORD-3001',
            'order_id' => $order->id,
            'status' => ShipmentStatus::InTransit->value,
        ]);

        $this->get('/orders')
            ->assertOk()
            ->assertSee('MNP-ORD-3001')
            ->assertSee('In transit');
    }

    public function test_orders_page_shows_not_shipped_for_unshipped_orders(): void
    {
        $customer = \App\Models\Customer::factory()->create();
        \App\Models\Order::factory()->create([
            'customer_id' => $customer->id,
            'number' => '#4001',
        ]);

        $this->get('/orders')
            ->assertOk()
            ->assertSee('#4001')
            ->assertSee('Not shipped');
    }

    public function test_cancel_link_hides_the_create_form_but_keeps_other_filters(): void
    {
        $this->get('/shipments?show_form=create&status=picked_up')
            ->assertOk()
            ->assertSee('New manual shipment')
            // Cancel keeps the other filters (status) but drops show_form,
            // so clicking it hides the form instead of reloading it.
            ->assertSee(route('shipments.index', ['status' => 'picked_up']), false)
            // The "New shipment" button still carries show_form=create (the
            // href's "&" is HTML-escaped in the response, so e() is used).
            ->assertSee(e(route('shipments.index', ['show_form' => 'create', 'status' => 'picked_up'])), false);
    }

    public function test_creating_a_shipment_pushes_a_real_tracking_number_to_shopify(): void
    {
        config()->set('shopify.shop', 'test-store');
        config()->set('shopify.client_id', 'client-id');
        config()->set('shopify.client_secret', 'client-secret');
        config()->set('shopify.api_version', '2026-07');

        Http::fake([
            '*/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_test', 'expires_in' => 3600], 200),
            '*/graphql.json' => function (Request $request) {
                $query = (string) ($request['query'] ?? '');

                if (str_contains($query, 'ShopifyOrderFulfillmentState')) {
                    return Http::response(['data' => [
                        'order' => [
                            'id' => 'gid://shopify/Order/9001',
                            'fulfillments' => [],
                            'fulfillmentOrders' => ['edges' => [['node' => [
                                'id' => 'gid://shopify/FulfillmentOrder/1',
                            ]]]],
                        ],
                    ]], 200);
                }

                return Http::response(['data' => ['fulfillmentCreate' => ['userErrors' => []]]], 200);
            },
        ]);

        $customer = \App\Models\Customer::factory()->create();
        $order = \App\Models\Order::factory()->create([
            'customer_id' => $customer->id,
            'number' => '#9001',
            'shopify_id' => 'gid://shopify/Order/9001',
        ]);

        $this->post('/shipments', [
            'tracking_number' => 'LP-TRACK-1',
            'order_id' => $order->id,
            'consignee_name' => 'Ali Khan',
        ])->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'tracking_number' => 'LP-TRACK-1',
            'order_id' => $order->id,
        ]);

        Http::assertSent(function (Request $request) {
            $payload = json_decode((string) $request->body(), true);
            $fulfillment = $payload['variables']['fulfillment'] ?? [];

            return str_contains((string) $request->url(), '/graphql.json')
                && str_contains((string) ($payload['query'] ?? ''), 'fulfillmentCreate')
                && ($fulfillment['trackingInfo']['number'] ?? null) === 'LP-TRACK-1';
        });
    }

    public function test_auto_generated_tracking_number_is_pushed_to_shopify(): void
    {
        config()->set('shopify.shop', 'test-store');
        config()->set('shopify.client_id', 'client-id');
        config()->set('shopify.client_secret', 'client-secret');
        config()->set('shopify.api_version', '2026-07');

        Http::fake([
            '*/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_test', 'expires_in' => 3600], 200),
            '*/graphql.json' => function (Request $request) {
                $query = (string) ($request['query'] ?? '');

                if (str_contains($query, 'ShopifyOrderFulfillmentState')) {
                    return Http::response(['data' => [
                        'order' => [
                            'id' => 'gid://shopify/Order/9002',
                            'fulfillments' => [],
                            'fulfillmentOrders' => ['edges' => [['node' => [
                                'id' => 'gid://shopify/FulfillmentOrder/1',
                            ]]]],
                        ],
                    ]], 200);
                }

                return Http::response(['data' => ['fulfillmentCreate' => ['userErrors' => []]]], 200);
            },
        ]);

        $customer = \App\Models\Customer::factory()->create();
        $order = \App\Models\Order::factory()->create([
            'customer_id' => $customer->id,
            'number' => '#9002',
            'shopify_id' => 'gid://shopify/Order/9002',
        ]);

        // No tracking number submitted → store() auto-generates a 14-digit
        // numeric one, which IS mirrored onto the Shopify order.
        $this->post('/shipments', [
            'order_id' => $order->id,
            'consignee_name' => 'Ali Khan',
        ])->assertRedirect();

        $shipment = Shipment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertMatchesRegularExpression('/^\d{14}$/', (string) $shipment->tracking_number);

        Http::assertSent(function (Request $request) use ($shipment) {
            $payload = json_decode((string) $request->body(), true);
            $fulfillment = $payload['variables']['fulfillment'] ?? [];

            return str_contains((string) $request->url(), '/graphql.json')
                && str_contains((string) ($payload['query'] ?? ''), 'fulfillmentCreate')
                && ($fulfillment['trackingInfo']['number'] ?? null) === $shipment->tracking_number;
        });
    }

    private function resolveManual(): ManualProvider
    {
        $row = CourierProvider::query()->where('key', 'manual')->firstOrFail();
        $provider = $this->app->make(CourierProviderRegistry::class)->resolve($row);

        $this->assertInstanceOf(ManualProvider::class, $provider);

        return $provider;
    }

    private function makeShipment(array $overrides = []): Shipment
    {
        $manual = CourierProvider::query()->where('key', 'manual')->firstOrFail();

        return Shipment::query()->create(array_merge([
            'courier_provider_id' => $manual->id,
            'external_id' => 'TEST-'.strtoupper(bin2hex(random_bytes(3))),
            'tracking_number' => 'TST-'.strtoupper(bin2hex(random_bytes(2))),
            'status' => ShipmentStatus::Created->value,
            'shipped_at' => now(),
            'last_event_at' => now(),
        ], $overrides));
    }
}
