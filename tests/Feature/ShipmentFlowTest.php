<?php

namespace Tests\Feature;

use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider;
use App\Models\Shipment;
use App\Services\Courier\CourierProviderRegistry;
use App\Services\Courier\Exceptions\CapabilityUnsupportedException;
use App\Services\Courier\Providers\ManualProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CourierProvidersSeeder::class);
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

    public function test_shipments_index_page_renders_with_manual_provider(): void
    {
        $this->makeShipment(['tracking_number' => 'MNP-INDEX-1']);
        $this->makeShipment(['tracking_number' => 'MNP-INDEX-2', 'consignee_name' => 'Ali Khan']);

        $this->get('/shipments')
            ->assertOk()
            ->assertSee('Shipments')
            ->assertSee('MNP-INDEX-1')
            ->assertSee('MNP-INDEX-2')
            ->assertSee('Ali Khan')
            ->assertSee('Manual Entry');
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
