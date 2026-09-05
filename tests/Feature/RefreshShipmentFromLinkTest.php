<?php

namespace Tests\Feature;

use App\Enums\Courier\ShipmentStatus;
use App\Jobs\RefreshShipmentFromLinkJob;
use App\Models\CourierProvider;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Courier\TrackingLinkResolver;
use Database\Seeders\CourierProvidersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RefreshShipmentFromLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CourierProvidersSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_job_updates_shipment_status_from_a_delivered_tracking_page(): void
    {
        Http::fake([
            'example.com/*' => Http::response(
                '<html><head><title>Delivered</title></head></html>',
                200,
            ),
        ]);

        $provider = CourierProvider::query()->where('key', 'shopify')->firstOrFail();
        $shipment = Shipment::create([
            'courier_provider_id' => $provider->id,
            'external_id' => 'gid://shopify/Fulfillment/9001',
            'tracking_number' => 'DHL-ABC1',
            'status' => ShipmentStatus::InTransit->value,
            'tracking_url' => 'https://example.com/track?id=DHL-ABC1',
        ]);

        (new RefreshShipmentFromLinkJob($shipment->id))->handle(app(TrackingLinkResolver::class));

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Delivered, $shipment->status);
        $this->assertNotNull($shipment->delivered_at);
        $this->assertSame('web:unknown', $shipment->raw_payload['resolver_source'] ?? null);
        // The page only had a status — no event table — so no timeline
        // entries are appended. The status on the shipment row carries the
        // updated state on its own.
        $this->assertSame(0, $shipment->events()->count());
    }

    public function test_job_appends_events_when_the_page_has_an_event_table(): void
    {
        $html = <<<'HTML'
            <html><body>
                <table>
                    <tr><td>2026-08-26 10:00</td><td>Delivered</td><td>Karachi</td></tr>
                    <tr><td>2026-08-25 08:00</td><td>In transit</td><td>Hyderabad</td></tr>
                </table>
            </body></html>
            HTML;

        Http::fake([
            'example.com/*' => Http::response($html, 200),
        ]);

        $provider = CourierProvider::query()->where('key', 'shopify')->firstOrFail();
        $shipment = Shipment::create([
            'courier_provider_id' => $provider->id,
            'external_id' => 'gid://shopify/Fulfillment/9004',
            'tracking_number' => 'DHL-EVT1',
            'status' => ShipmentStatus::InTransit->value,
            'tracking_url' => 'https://example.com/track?id=DHL-EVT1',
        ]);

        (new RefreshShipmentFromLinkJob($shipment->id))->handle(app(TrackingLinkResolver::class));

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Delivered, $shipment->status);
        $this->assertSame(2, $shipment->events()->count());
    }

    public function test_job_skips_shipments_already_in_a_terminal_state(): void
    {
        Http::fake();

        $provider = CourierProvider::query()->where('key', 'shopify')->firstOrFail();
        $shipment = Shipment::create([
            'courier_provider_id' => $provider->id,
            'external_id' => 'gid://shopify/Fulfillment/9002',
            'tracking_number' => 'LP-DONE',
            'status' => ShipmentStatus::Delivered->value,
            'delivered_at' => now()->subDay(),
            'tracking_url' => 'https://example.com/track?id=LP-DONE',
        ]);

        (new RefreshShipmentFromLinkJob($shipment->id))->handle(app(TrackingLinkResolver::class));

        $shipment->refresh();
        Http::assertNothingSent();
        $this->assertSame(ShipmentStatus::Delivered, $shipment->status);
    }

    public function test_job_skips_shipments_that_just_got_an_event(): void
    {
        Http::fake();

        $provider = CourierProvider::query()->where('key', 'shopify')->firstOrFail();
        $shipment = Shipment::create([
            'courier_provider_id' => $provider->id,
            'external_id' => 'gid://shopify/Fulfillment/9003',
            'tracking_number' => 'LP-FRESH',
            'status' => ShipmentStatus::InTransit->value,
            'last_event_at' => now()->subSeconds(30),
            'tracking_url' => 'https://example.com/track?id=LP-FRESH',
        ]);

        (new RefreshShipmentFromLinkJob($shipment->id))->handle(app(TrackingLinkResolver::class));

        $shipment->refresh();
        Http::assertNothingSent();
    }

    public function test_controller_refresh_uses_the_resolver_for_shopify_shipments(): void
    {
        Http::fake([
            'example.com/*' => Http::response(
                '<html><head><title>Out for delivery</title></head></html>',
                200,
            ),
        ]);

        $provider = CourierProvider::query()->where('key', 'shopify')->firstOrFail();
        $shipment = Shipment::create([
            'courier_provider_id' => $provider->id,
            'external_id' => 'gid://shopify/Fulfillment/9100',
            'tracking_number' => 'DHL-OUT1',
            'status' => ShipmentStatus::InTransit->value,
            'tracking_url' => 'https://example.com/track?id=DHL-OUT1',
        ]);

        $response = $this->post(route('shipments.refresh', $shipment));

        $response->assertRedirect(route('shipments.show', $shipment));
        $shipment->refresh();
        $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->status);
        $this->assertStringContainsString('web:unknown', (string) session('status'));
    }

    public function test_controller_refresh_shows_the_strategy_on_the_show_page(): void
    {
        $provider = CourierProvider::query()->where('key', 'shopify')->firstOrFail();
        $shipment = Shipment::create([
            'courier_provider_id' => $provider->id,
            'external_id' => 'gid://shopify/Fulfillment/9200',
            'tracking_number' => 'LP-STR1',
            'status' => ShipmentStatus::InTransit->value,
            'tracking_url' => 'https://leopardscourier.com/?cn=LP-STR1',
        ]);

        $this->get(route('shipments.show', $shipment))
            ->assertOk()
            ->assertSee('refresh via')
            ->assertSee('api:leopards');
    }

    public function test_command_refreshes_pending_shipments(): void
    {
        Http::fake([
            'example.com/*' => Http::response(
                '<html><head><title>Delivered</title></head></html>',
                200,
            ),
        ]);

        $provider = CourierProvider::query()->where('key', 'shopify')->firstOrFail();
        Shipment::create([
            'courier_provider_id' => $provider->id,
            'external_id' => 'gid://shopify/Fulfillment/A1',
            'tracking_number' => 'CMD-001',
            'status' => ShipmentStatus::InTransit->value,
            'tracking_url' => 'https://example.com/track?id=CMD-001',
            'last_event_at' => now()->subHour(),
        ]);

        $this->artisan('couriers:refresh-links')
            ->expectsOutputToContain('Refreshed')
            ->assertExitCode(0);

        $this->assertDatabaseHas('shipments', [
            'tracking_number' => 'CMD-001',
            'status' => ShipmentStatus::Delivered->value,
        ]);
    }

    public function test_command_dry_run_does_not_call_courier_pages(): void
    {
        Http::fake();

        $provider = CourierProvider::query()->where('key', 'shopify')->firstOrFail();
        Shipment::create([
            'courier_provider_id' => $provider->id,
            'external_id' => 'gid://shopify/Fulfillment/A2',
            'tracking_number' => 'CMD-002',
            'status' => ShipmentStatus::InTransit->value,
            'tracking_url' => 'https://example.com/track?id=CMD-002',
            'last_event_at' => now()->subHour(),
        ]);

        $this->artisan('couriers:refresh-links', ['--dry-run' => true])
            ->expectsOutputToContain('would refresh')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }
}
