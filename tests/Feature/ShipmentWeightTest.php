<?php

namespace Tests\Feature;

use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider;
use App\Models\CourierRate;
use App\Models\CourierZone;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use Database\Seeders\CourierProvidersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shipment weight is derived from the order it is linked to: the order's
 * total product weight (quantity × product.weight_kg) becomes the
 * shipment's weight when the courier never reported a real one. That weight
 * then drives the DeliveryRateCalculator against the provider's
 * zone/weight rate card.
 */
class ShipmentWeightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CourierProvidersSeeder::class);
    }

    public function test_order_total_weight_sums_quantity_times_product_weight(): void
    {
        $order = $this->orderWithItems([[0.5, 2], [0.25, 1]]);

        $this->assertSame(1.25, $order->totalWeightKg());
    }

    public function test_order_without_product_weights_has_no_total_weight(): void
    {
        $order = $this->orderWithItems([[null, 1]]);

        $this->assertNull($order->totalWeightKg());
    }

    public function test_shipment_created_linked_to_order_derives_its_weight(): void
    {
        $order = $this->orderWithItems([[0.5, 2]]); // 1.000 kg

        $shipment = $this->makeShipment(['order_id' => $order->id]);

        $this->assertSame('1.000', (string) $shipment->weight_kg);
    }

    public function test_recorded_courier_weight_wins_over_the_derived_weight(): void
    {
        $order = $this->orderWithItems([[0.5, 2]]); // derived 1.000 kg

        $shipment = $this->makeShipment(['order_id' => $order->id, 'weight_kg' => 3.75]);

        $this->assertSame('3.750', (string) $shipment->weight_kg);
    }

    public function test_linking_a_weightless_shipment_backfills_its_weight(): void
    {
        $order = $this->orderWithItems([[0.5, 2]]);
        $shipment = $this->makeShipment([]);

        $this->assertNull($shipment->weight_kg);

        $shipment->forceFill(['order_id' => $order->id])->save();
        $shipment->refresh();

        $this->assertSame('1.000', (string) $shipment->weight_kg);
    }

    public function test_shipment_without_an_order_keeps_a_null_weight(): void
    {
        $shipment = $this->makeShipment([]);

        $this->assertNull($shipment->weight_kg);
    }

    public function test_derived_weight_picks_the_right_band_on_the_rate_card(): void
    {
        $order = $this->orderWithItems([[0.5, 2]]); // 1.000 kg → heavier band
        $provider = $this->provider('manual');
        $zone = $this->makeZone($provider, 'Default', default: true);

        // Band 0 → <1 kg costs 150; 1 kg+ costs 250.
        CourierRate::query()->create($this->rate($provider, $zone, $zone, 0, 1, 150));
        CourierRate::query()->create($this->rate($provider, $zone, $zone, 1, null, 250));

        $shipment = $this->makeShipment(['order_id' => $order->id]);

        $this->assertSame('1.000', (string) $shipment->weight_kg);
        $this->assertSame(250.0, $shipment->estimatedCost());
        $this->assertSame(250.0, $shipment->effectiveCost());
    }

    public function test_derived_weight_is_a_fallback_for_unpersisted_rows(): void
    {
        $order = $this->orderWithItems([[0.5, 2]]);
        $provider = $this->provider('manual');
        $zone = $this->makeZone($provider, 'Default', default: true);
        CourierRate::query()->create($this->rate($provider, $zone, $zone, 0, null, 200));

        $shipment = $this->makeShipment(['order_id' => $order->id]);

        // Simulate a legacy row saved before weight derivation existed: the
        // observer must not run, or it would re-derive the weight on save.
        Shipment::withoutEvents(function () use ($shipment): void {
            $shipment->forceFill(['weight_kg' => null])->save();
        });
        $shipment->refresh();

        $this->assertNull($shipment->weight_kg);
        // estimateForShipment() still falls back to the order-derived weight.
        $this->assertSame(1.0, $shipment->effectiveWeightKg());
        $this->assertSame(200.0, $shipment->estimatedCost());
    }

    public function test_zero_weight_order_products_leave_shipment_weight_null(): void
    {
        $order = $this->orderWithItems([[0.0, 2]]);

        // Shopify reports unset product weights as 0; that is not a real
        // parcel weight, so the order has no derivable weight...
        $this->assertNull($order->totalWeightKg());

        // ...and the linked shipment stays weightless instead of locking in 0.
        $shipment = $this->makeShipment(['order_id' => $order->id]);
        $this->assertNull($shipment->weight_kg);
    }


    private function orderWithItems(array $items): Order
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);

        foreach ($items as [$weightKg, $quantity]) {
            $product = Product::factory()->create(['weight_kg' => $weightKg]);
            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => 10,
            ]);
        }

        return $order;
    }

    private function provider(string $key): CourierProvider
    {
        return CourierProvider::query()->where('key', $key)->firstOrFail();
    }

    private function makeZone(CourierProvider $provider, string $name, bool $default = false): CourierZone
    {
        return CourierZone::query()->create([
            'courier_provider_id' => $provider->id,
            'name' => $name,
            'cities' => [],
            'is_default' => $default,
        ]);
    }

    private function rate(CourierProvider $provider, CourierZone $origin, CourierZone $destination, float $from, ?float $to, float $price): array
    {
        return [
            'courier_provider_id' => $provider->id,
            'origin_zone_id' => $origin->id,
            'destination_zone_id' => $destination->id,
            'weight_from_kg' => $from,
            'weight_to_kg' => $to,
            'price' => $price,
            'currency' => 'PKR',
            'is_active' => true,
        ];
    }

    private function makeShipment(array $overrides = []): Shipment
    {
        $manual = $this->provider('manual');

        return Shipment::query()->create(array_merge([
            'courier_provider_id' => $manual->id,
            'external_id' => 'WGT-'.strtoupper(bin2hex(random_bytes(3))),
            'tracking_number' => 'WGT-'.strtoupper(bin2hex(random_bytes(2))),
            'status' => ShipmentStatus::Created->value,
            'shipped_at' => now(),
            'last_event_at' => now(),
            'currency' => 'PKR',
        ], $overrides));
    }
}

