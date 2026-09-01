<?php

namespace Tests\Feature;

use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shipment;
use Database\Seeders\CourierProvidersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end tests for the "associate a shipment with an order" flow.
 *
 * The intent is to prove the system is hard to misuse — typo, double-
 * link, accidental unlink, link-to-wrong-order. Every test name below
 * is a "logical error" we want to prevent.
 */
class ShipmentOrderAssociationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CourierProvidersSeeder::class);
    }

    private function makeOrder(array $attrs = []): Order
    {
        // Unique per call so multiple orders in one test don't collide
        // on the unique customer-email index.
        $uniq = bin2hex(random_bytes(3));

        $customer = Customer::create([
            'name' => 'Ali Khan',
            'email' => 'ali-'.$uniq.'@example.com',
            'phone' => '03001234567',
            'country' => 'Pakistan',
        ]);

        return Order::create(array_merge([
            'number' => '#1001',
            'customer_id' => $customer->id,
            'total' => 100.0,
            'financial_status' => 'PAID',
            'fulfillment_status' => 'UNFULFILLED',
        ], $attrs));
    }

    private function makeManualShipment(?int $orderId = null, ?string $tracking = 'MNP-1'): Shipment
    {
        $manual = CourierProvider::query()->where('key', 'manual')->firstOrFail();

        return Shipment::create([
            'courier_provider_id' => $manual->id,
            'external_id' => 'MANUAL-'.strtoupper(bin2hex(random_bytes(4))),
            'tracking_number' => $tracking,
            'order_id' => $orderId,
            'status' => ShipmentStatus::Created->value,
            'currency' => 'PKR',
            'shipped_at' => now(),
            'last_event_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------
    // Lookup endpoint
    // -------------------------------------------------------------------

    public function test_lookup_endpoint_returns_orders_matching_the_query(): void
    {
        $this->makeOrder(['number' => '#1234']);
        $this->makeOrder(['number' => '#5678']);

        $response = $this->getJson('/shipments/lookup-orders?q=1234');
        $response->assertOk();
        $response->assertJsonPath('results.0.number', '#1234');
    }

    public function test_lookup_endpoint_suggests_by_phone_when_only_phone_provided(): void
    {
        $this->makeOrder();

        $response = $this->getJson('/shipments/lookup-orders?consignee_phone=03001234567');
        $response->assertOk();
        $this->assertCount(1, $response->json('results'));
        $this->assertSame('#1001', $response->json('results.0.number'));
    }

    public function test_lookup_endpoint_returns_empty_when_nothing_provided(): void
    {
        $this->makeOrder();

        $response = $this->getJson('/shipments/lookup-orders');
        $response->assertOk();
        $this->assertSame([], $response->json('results'));
    }

    // -------------------------------------------------------------------
    // Link endpoint — by id, not by free-text number
    // -------------------------------------------------------------------

    public function test_link_uses_order_id_so_a_typo_cannot_silently_link_to_a_wrong_order(): void
    {
        $shipment = $this->makeManualShipment();

        $response = $this->post("/shipments/{$shipment->id}/link", [
            'order_id' => 99999, // doesn't exist
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('order_id');
        $this->assertNull($shipment->fresh()->order_id);
    }

    public function test_link_by_id_pins_the_shipment_to_the_order_with_manual_method(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->makeManualShipment();

        $response = $this->post("/shipments/{$shipment->id}/link", [
            'order_id' => $order->id,
        ]);

        $response->assertRedirect(route('shipments.show', $shipment));
        $shipment->refresh();
        $this->assertSame($order->id, $shipment->order_id);
        $this->assertSame('manual', $shipment->matched_method);
        $this->assertNotNull($shipment->matched_at);
    }

    public function test_linking_to_the_same_order_is_a_noop_not_an_error(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->makeManualShipment($order->id);

        $response = $this->post("/shipments/{$shipment->id}/link", [
            'order_id' => $order->id,
        ]);

        $response->assertSessionHas('status');
        $this->assertStringContainsString('already linked', (string) session('status'));
    }

    public function test_switching_a_linked_shipment_requires_explicit_confirmation(): void
    {
        $a = $this->makeOrder(['number' => '#A']);
        $b = $this->makeOrder(['number' => '#B']);
        $shipment = $this->makeManualShipment($a->id);

        // First attempt: no confirm — the controller must refuse.
        $this->post("/shipments/{$shipment->id}/link", [
            'order_id' => $b->id,
        ])->assertSessionHasErrors('order_id');
        $this->assertSame($a->id, $shipment->fresh()->order_id, 'must not switch without confirm');

        // Second attempt with confirm=1 — now the link is moved.
        $this->post("/shipments/{$shipment->id}/link", [
            'order_id' => $b->id,
            'confirm' => 1,
        ])->assertSessionHas('status');
        $this->assertSame($b->id, $shipment->fresh()->order_id);
    }

    // -------------------------------------------------------------------
    // Unlink endpoint
    // -------------------------------------------------------------------

    public function test_unlink_is_idempotent(): void
    {
        $shipment = $this->makeManualShipment(); // not linked

        $this->post("/shipments/{$shipment->id}/unlink")
            ->assertSessionHas('status');

        $this->assertNull($shipment->fresh()->order_id);
    }

    public function test_unlink_clears_matched_metadata(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->makeManualShipment($order->id);
        $shipment->forceFill(['matched_method' => 'manual', 'matched_at' => now()])->save();

        $this->post("/shipments/{$shipment->id}/unlink");

        $shipment->refresh();
        $this->assertNull($shipment->order_id);
        $this->assertNull($shipment->matched_method);
        $this->assertNull($shipment->matched_at);
    }

    // -------------------------------------------------------------------
    // Rematch
    // -------------------------------------------------------------------

    public function test_rematch_links_a_shipment_by_reference(): void
    {
        $order = $this->makeOrder(['number' => '#REF-7']);
        $shipment = $this->makeManualShipment();
        $shipment->forceFill(['reference' => '#REF-7'])->save();

        $this->post("/shipments/{$shipment->id}/rematch");

        $this->assertSame($order->id, $shipment->fresh()->order_id);
        $this->assertSame('reference', $shipment->fresh()->matched_method);
    }

    public function test_rematch_does_not_break_a_manual_link(): void
    {
        $manualOrder = $this->makeOrder(['number' => '#M']);
        $shipment = $this->makeManualShipment($manualOrder->id);
        $shipment->forceFill(['matched_method' => 'manual', 'matched_at' => now()])->save();

        $this->post("/shipments/{$shipment->id}/rematch");

        $this->assertSame($manualOrder->id, $shipment->fresh()->order_id, 'manual link must survive a re-match');
    }

    // -------------------------------------------------------------------
    // New-shipment form
    // -------------------------------------------------------------------

    public function test_new_shipment_form_persists_order_id_when_provided(): void
    {
        $order = $this->makeOrder();

        $this->post('/shipments', [
            'tracking_number' => 'NEW-1',
            'order_id' => $order->id,
        ])->assertRedirect();

        $shipment = Shipment::query()->where('tracking_number', 'NEW-1')->firstOrFail();
        $this->assertSame($order->id, $shipment->order_id);
        $this->assertSame('manual', $shipment->matched_method);
    }

    public function test_new_shipment_form_ignores_an_invalid_order_id(): void
    {
        // Validation: integer, min:1 — a tampered form posting
        // "abc" is rejected before we touch the DB.
        $this->post('/shipments', [
            'tracking_number' => 'NEW-2',
            'order_id' => 'not-an-int',
        ])->assertSessionHasErrors('order_id');

        $this->assertSame(0, Shipment::query()->where('tracking_number', 'NEW-2')->count());
    }

    public function test_new_shipment_form_silently_drops_a_nonexistent_order_id(): void
    {
        // The order_id passes validation (it's an integer > 0) but
        // doesn't match any row. The shipment is still created, just
        // unlinked — the auto-matcher gets a shot next.
        $this->post('/shipments', [
            'tracking_number' => 'NEW-3',
            'order_id' => 99999,
        ])->assertRedirect();

        $shipment = Shipment::query()->where('tracking_number', 'NEW-3')->firstOrFail();
        $this->assertNull($shipment->order_id);
    }

    public function test_new_shipment_form_falls_back_to_auto_matcher_when_no_order_id(): void
    {
        $order = $this->makeOrder(['number' => '#AUTO-9']);
        $this->post('/shipments', [
            'tracking_number' => 'NEW-4',
            'reference' => '#AUTO-9',
        ])->assertRedirect();

        $shipment = Shipment::query()->where('tracking_number', 'NEW-4')->firstOrFail();
        $this->assertSame($order->id, $shipment->order_id);
        $this->assertSame('reference', $shipment->matched_method);
    }

    // -------------------------------------------------------------------
    // Order quick actions
    // -------------------------------------------------------------------

    public function test_order_add_tracking_creates_a_linked_manual_shipment(): void
    {
        $order = $this->makeOrder();

        $this->post("/orders/{$order->id}/add-tracking", [
            'tracking_number' => 'QUICK-1',
            'carrier_name' => 'Leopards',
        ])->assertRedirect();

        $shipment = Shipment::query()->where('tracking_number', 'QUICK-1')->firstOrFail();
        $this->assertSame($order->id, $shipment->order_id);
        $this->assertSame('manual', $shipment->matched_method);
        $this->assertSame('Leopards', $shipment->carrier_name);
    }

    public function test_order_assign_provider_pins_an_early_courier(): void
    {
        $order = $this->makeOrder();
        $leopards = CourierProvider::query()->where('key', 'leopards')->firstOrFail();

        $this->post("/orders/{$order->id}/assign-provider", [
            'courier_provider_id' => $leopards->id,
        ])->assertSessionHas('status');

        $this->assertSame($leopards->id, $order->fresh()->courier_provider_id);
    }

    public function test_order_assign_provider_rejects_a_nonexistent_provider(): void
    {
        $order = $this->makeOrder();

        $this->post("/orders/{$order->id}/assign-provider", [
            'courier_provider_id' => 99999,
        ])->assertSessionHasErrors('courier_provider_id');

        $this->assertNull($order->fresh()->courier_provider_id);
    }

    public function test_order_assign_provider_clears_with_zero(): void
    {
        $leopards = CourierProvider::query()->where('key', 'leopards')->firstOrFail();
        $order = $this->makeOrder(['courier_provider_id' => $leopards->id]);

        $this->post("/orders/{$order->id}/assign-provider", [
            'courier_provider_id' => 0,
        ])->assertSessionHas('status');

        $this->assertNull($order->fresh()->courier_provider_id);
    }

    // -------------------------------------------------------------------
    // Observer: fulfillment propagation
    // -------------------------------------------------------------------

    public function test_delivering_a_shipment_marks_the_order_fulfilled(): void
    {
        $order = $this->makeOrder();
        $shipment = $this->makeManualShipment($order->id);

        $shipment->forceFill(['status' => ShipmentStatus::Delivered->value])->save();

        $this->assertSame('FULFILLED', $order->fresh()->fulfillment_status);
    }

    public function test_a_delivered_shipment_with_no_linked_order_does_not_crash(): void
    {
        $shipment = $this->makeManualShipment();
        $shipment->forceFill(['status' => ShipmentStatus::Delivered->value])->save();

        $this->assertSame(ShipmentStatus::Delivered, $shipment->fresh()->status);
    }

    public function test_all_shipments_cancelled_rolls_the_order_to_cancelled(): void
    {
        $order = $this->makeOrder();
        $a = $this->makeManualShipment($order->id, 'CXL-1');
        $b = $this->makeManualShipment($order->id, 'CXL-2');

        $a->forceFill(['status' => ShipmentStatus::Cancelled->value])->save();
        $b->forceFill(['status' => ShipmentStatus::Cancelled->value])->save();

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_mixed_terminal_shipments_set_status_to_returned_when_any_returned(): void
    {
        $order = $this->makeOrder();
        $a = $this->makeManualShipment($order->id, 'MIX-1');
        $b = $this->makeManualShipment($order->id, 'MIX-2');

        $a->forceFill(['status' => ShipmentStatus::Delivered->value])->save();
        $b->forceFill(['status' => ShipmentStatus::Returned->value])->save();

        $this->assertSame('returned', $order->fresh()->status);
    }

    public function test_shipment_still_in_transit_keeps_order_status_unchanged(): void
    {
        $order = $this->makeOrder();
        // Capture the actual stored value (the migration default is
        // "pending" — we don't want to assert against the Eloquent
        // model's lazy attribute which may differ from the DB value).
        $originalStatus = $order->fresh()->status;

        $this->makeManualShipment($order->id, 'INTR-1');
        $this->makeManualShipment($order->id, 'INTR-2')->forceFill([
            'status' => ShipmentStatus::Delivered->value,
        ])->save();

        $this->assertSame($originalStatus, $order->fresh()->status);
    }
}
