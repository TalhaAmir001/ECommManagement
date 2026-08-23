<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Courier\OrderAutoMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAutoMatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CourierProvidersSeeder::class);
    }

    public function test_it_matches_by_reference_when_reference_equals_order_number(): void
    {
        $order = $this->makeOrder('ORD-1001');
        $shipment = $this->makeShipment(['reference' => 'ORD-1001']);

        $matcher = $this->makeMatcher();
        $matchedId = $matcher->match($shipment);

        $this->assertSame($order->id, $matchedId);
        $shipment->refresh();
        $this->assertSame($order->id, $shipment->order_id);
        $this->assertSame('reference', $shipment->matched_method);
    }

    public function test_it_falls_back_to_phone_when_reference_doesnt_match(): void
    {
        $customer = Customer::factory()->create(['phone' => '03001234567']);
        $order = $this->makeOrder('ORD-2002', $customer->id);
        $shipment = $this->makeShipment([
            'reference' => 'WRONG-REF',
            'consignee_phone' => '03001234567',
        ]);

        $matcher = $this->makeMatcher();
        $matchedId = $matcher->match($shipment);

        $this->assertSame($order->id, $matchedId);
        $shipment->refresh();
        $this->assertSame('phone', $shipment->matched_method);
    }

    public function test_it_returns_null_when_no_strategy_matches(): void
    {
        $shipment = $this->makeShipment([
            'reference' => 'WRONG',
            'consignee_phone' => '0000000',
        ]);

        $matcher = $this->makeMatcher();
        $matchedId = $matcher->match($shipment);

        $this->assertNull($matchedId);
        $shipment->refresh();
        $this->assertNull($shipment->order_id);
    }

    public function test_manual_links_are_sticky_by_default(): void
    {
        $orderA = $this->makeOrder('ORD-AAAA');
        $orderB = $this->makeOrder('ORD-BBBB');

        $shipment = $this->makeShipment(['reference' => 'ORD-AAAA']);
        $shipment->forceFill(['order_id' => $orderA->id, 'matched_method' => 'manual', 'matched_at' => now()])->save();

        $matcher = $this->makeMatcher();   // overwriteManual = false
        $matchedId = $matcher->match($shipment);

        $this->assertSame($orderA->id, $matchedId);
    }

    public function test_overwrite_manual_lets_an_auto_strategy_take_over_a_manual_link(): void
    {
        $orderA = $this->makeOrder('ORD-CCCC');
        $orderB = $this->makeOrder('ORD-DDDD');

        // shipment starts manually linked to orderA, but the reference now
        // resolves to orderB. With overwriteManual=true the strategy runs
        // and re-links to orderB.
        $shipment = $this->makeShipment(['reference' => 'ORD-DDDD']);
        $shipment->forceFill(['order_id' => $orderA->id, 'matched_method' => 'manual', 'matched_at' => now()])->save();

        $matcher = new OrderAutoMatcher(strategies: ['reference'], overwriteManual: true);
        $matchedId = $matcher->match($shipment);

        $this->assertSame($orderB->id, $matchedId);
        $shipment->refresh();
        $this->assertSame('reference', $shipment->matched_method);
    }

    public function test_previous_auto_match_is_cleared_when_no_strategy_finds_a_match(): void
    {
        $order = $this->makeOrder('ORD-EEEE');
        $shipment = $this->makeShipment(['reference' => 'ORD-EEEE']);
        $shipment->forceFill(['order_id' => $order->id, 'matched_method' => 'reference', 'matched_at' => now()])->save();

        $shipment->reference = 'NOW-WRONG';
        $shipment->save();

        $matcher = $this->makeMatcher();
        $matchedId = $matcher->match($shipment);

        $this->assertNull($matchedId);
        $shipment->refresh();
        $this->assertNull($shipment->order_id);
        $this->assertNull($shipment->matched_method);
    }

    private function makeOrder(string $number, ?int $customerId = null): Order
    {
        $customerId ??= Customer::factory()->create()->id;

        return Order::factory()->create([
            'customer_id' => $customerId,
            'number' => $number,
            'status' => 'processing',
            'financial_status' => 'PAID',
            'fulfillment_status' => 'UNFULFILLED',
            'total' => 1000,
        ]);
    }

    private function makeShipment(array $overrides = []): Shipment
    {
        $manual = \App\Models\CourierProvider::query()->where('key', 'manual')->firstOrFail();

        return Shipment::query()->create(array_merge([
            'courier_provider_id' => $manual->id,
            'external_id' => 'TEST-'.strtoupper(bin2hex(random_bytes(3))),
            'tracking_number' => 'TST-'.strtoupper(bin2hex(random_bytes(2))),
            'status' => \App\Enums\Courier\ShipmentStatus::Created->value,
            'shipped_at' => now(),
            'last_event_at' => now(),
        ], $overrides));
    }

    private function makeMatcher(): OrderAutoMatcher
    {
        return new OrderAutoMatcher(
            strategies: ['reference', 'phone'],
            overwriteManual: false,
        );
    }
}
