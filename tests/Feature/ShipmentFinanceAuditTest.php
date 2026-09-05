<?php

namespace Tests\Feature;

use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentFinanceAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CourierProvidersSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_manual_shipment_store_persists_courier_cost_and_cod(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->post('/shipments', [
            'tracking_number' => 'TST-MONEY-1',
            'consignee_name' => 'Ali Khan',
            'consignee_city' => 'Lahore',
            'cost' => 450.50,
            'cod_amount' => 2500,
            'currency' => 'PKR',
        ])->assertRedirect();

        $shipment = Shipment::query()->where('tracking_number', 'TST-MONEY-1')->firstOrFail();

        $this->assertSame('450.50', (string) $shipment->cost);
        $this->assertSame('2500.00', (string) $shipment->cod_amount);
        $this->assertSame('PKR', $shipment->currency);
    }

    public function test_order_add_tracking_captures_cost_and_cod(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $customer = Customer::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'number' => '#9100',
        ]);

        $this->post(route('orders.add-tracking', $order), [
            'tracking_number' => 'ORD-9100-TRK',
            'carrier_name' => 'Leopards',
            'cost' => 320,
            'cod_amount' => 4000,
        ])->assertRedirect();

        $shipment = $order->shipments()->firstOrFail();

        $this->assertSame($order->id, $shipment->order_id);
        $this->assertSame('manual', $shipment->matched_method);
        $this->assertSame('320.00', (string) $shipment->cost);
        $this->assertSame('4000.00', (string) $shipment->cod_amount);
    }

    public function test_update_money_backfills_and_clears_shipment_figures(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $shipment = $this->makeShipment([]);

        $this->post(route('shipments.money', $shipment), [
            'cost' => 200,
            'cod_amount' => 1500,
            'currency' => 'pkr',
        ])->assertRedirect();

        $shipment->refresh();
        $this->assertSame('200.00', (string) $shipment->cost);
        $this->assertSame('1500.00', (string) $shipment->cod_amount);
        $this->assertSame('PKR', $shipment->currency);

        // Blank fields clear the figures (empty strings are converted to
        // null) while currency keeps its previous value.
        $this->post(route('shipments.money', $shipment), [
            'cost' => '',
            'cod_amount' => '',
            'currency' => '',
        ])->assertRedirect();

        $shipment->refresh();
        $this->assertNull($shipment->cost);
        $this->assertNull($shipment->cod_amount);
        $this->assertSame('PKR', $shipment->currency);
    }

    public function test_audit_deducts_courier_cost_and_reports_delivered_cod(): void
    {
        $customer = Customer::factory()->create();
        Order::factory()->create([
            'customer_id' => $customer->id,
            'number' => '#9001',
            'financial_status' => 'PAID',
            'status' => 'delivered',
        ]);

        $provider = $this->manualProvider();

        // Delivered + COD collected → counts toward COD.
        $this->makeShipment([
            'courier_provider_id' => $provider->id,
            'tracking_number' => 'TST-AUD-1',
            'status' => ShipmentStatus::Delivered->value,
            'cost' => 450.00,
            'cod_amount' => 1000.00,
        ]);

        // In transit → courier cost counts, but its COD is not yet collected.
        $this->makeShipment([
            'courier_provider_id' => $provider->id,
            'tracking_number' => 'TST-AUD-2',
            'status' => ShipmentStatus::InTransit->value,
            'cost' => 120.00,
            'cod_amount' => 3000.00,
        ]);

        $this->get('/reports?preset=30d')
            ->assertOk()
            ->assertSee('Gross Sale')
            ->assertSee('Net COD')
            ->assertSee('Courier service charges')
            ->assertSee('Shipping costs received')
            // Delivered COD parcel: Net COD = 1000 − 450 courier cost.
            ->assertSee('₨550.00')
            // gross COD collected shows inside the Net COD breakdown
            ->assertSee('₨1,000.00')
            // the in-transit parcel's courier cost lands in Courier service charges
            ->assertSee('₨120.00');
    }

    public function test_shipments_list_hides_money_but_detail_page_shows_it(): void
    {
        $order = Order::factory()->create(['number' => '#INDEX-MONEY']);

        $shipment = $this->makeShipment([
            'tracking_number' => 'TST-INDEX-MONEY',
            'order_id' => $order->id,
            'matched_method' => 'manual',
            'status' => ShipmentStatus::Delivered->value,
            'cost' => 99.99,
            'cod_amount' => 1200,
        ]);

        // The list stays clean: no cost / COD figures, but every row links
        // to the detail page (tracking number + a "Details" action).
        $this->get('/shipments')
            ->assertOk()
            ->assertSee('TST-INDEX-MONEY')
            ->assertSee('Details')
            ->assertDontSee('₨99.99')
            ->assertDontSee('₨1,200.00');

        // The shipment detail page is where the money lives.
        $this->get('/shipments/'.$shipment->id)
            ->assertOk()
            ->assertSee('₨99.99')
            ->assertSee('₨1,200.00');
    }

    public function test_shipping_service_scopes_totals_to_the_given_window(): void
    {
        $provider = $this->manualProvider();

        // Inside the window.
        $this->makeShipment([
            'courier_provider_id' => $provider->id,
            'status' => ShipmentStatus::InTransit->value,
            'cost' => 100.00,
            'shipped_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
        ]);

        // Older than the window start → excluded from the 7-day report.
        $this->makeShipment([
            'courier_provider_id' => $provider->id,
            'status' => ShipmentStatus::InTransit->value,
            'cost' => 9000.00,
            'shipped_at' => now()->subDays(60),
            'created_at' => now()->subDays(60),
        ]);

        $totals = app(\App\Services\ShippingFinanceService::class)
            ->totals(now()->subDays(7), now());

        $this->assertSame(100.00, $totals['shipping_cost']);
        $this->assertSame(0.00, $totals['cod_collected']);
    }

    public function test_dashboard_renders_formula_pnl_strip(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Total Net Profit')
            ->assertSee('Gross Sale')
            ->assertSee('CoGS (Vendor owed)');
    }

    private function manualProvider(): CourierProvider
    {
        return CourierProvider::query()->where('key', 'manual')->firstOrFail();
    }

    private function makeShipment(array $overrides = []): Shipment
    {
        $manual = $this->manualProvider();

        return Shipment::query()->create(array_merge([
            'courier_provider_id' => $manual->id,
            'external_id' => 'TEST-'.strtoupper(bin2hex(random_bytes(3))),
            'tracking_number' => 'TST-'.strtoupper(bin2hex(random_bytes(2))),
            'status' => ShipmentStatus::Created->value,
            'shipped_at' => now(),
            'last_event_at' => now(),
            'currency' => 'PKR',
        ], $overrides));
    }
}
