<?php

namespace Tests\Feature;

use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider;
use App\Models\CourierRate;
use App\Models\CourierZone;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Courier\DeliveryRateCalculator;
use App\Services\ShippingFinanceService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierRateCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CourierProvidersSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_rates_page_renders_and_zone_is_created(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $provider = $this->provider('leopards');

        $this->get(route('couriers.rates.index', $provider))
            ->assertOk()
            ->assertSee('Delivery rates');

        $this->post(route('couriers.rates.zones.store', $provider), [
            'name' => 'Karachi',
            'cities' => 'Karachi,  Lahore',
            'is_default' => '1',
        ])->assertRedirect();

        $zone = CourierZone::query()->where('courier_provider_id', $provider->id)->firstOrFail();
        $this->assertSame('Karachi', $zone->name);
        $this->assertSame(['karachi', 'lahore'], $zone->cities);
        $this->assertTrue($zone->is_default);
    }

    public function test_calculator_picks_the_matching_zone_and_weight_band(): void
    {
        $provider = $this->provider('leopards');

        $karachi = $this->makeZone($provider, 'Karachi', ['karachi', 'lahore']);
        $rest = $this->makeZone($provider, 'Rest of Pakistan', [], default: true);

        // Same-city: band 0–1 kg at 150, band 1 kg+ at 250.
        CourierRate::query()->create($this->rate($provider, $karachi, $karachi, 0, 1, 150, codFee: 25));
        CourierRate::query()->create($this->rate($provider, $karachi, $karachi, 1, null, 250));

        // Cross-city via the default zone.
        CourierRate::query()->create($this->rate($provider, $karachi, $rest, 0, 1, 350));

        $calculator = app(DeliveryRateCalculator::class);

        // Weight inside the first band, no COD → price only.
        $this->assertSame(150.0, $calculator->estimate($provider, 0.5, 'Karachi', 'Lahore'));
        // COD parcels pay the COD surcharge on top.
        $this->assertSame(175.0, $calculator->estimate($provider, 0.5, 'Karachi', 'Lahore', 1000));
        // Heavier parcel falls into the 1 kg+ band.
        $this->assertSame(250.0, $calculator->estimate($provider, 3.0, 'Karachi', 'Karachi'));
        // An unlisted destination city falls back to the default zone's rate.
        $this->assertSame(350.0, $calculator->estimate($provider, 0.75, 'Karachi', 'Somewhere Else'));
    }

    public function test_overlapping_weight_bands_are_rejected(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $provider = $this->provider('leopards');
        $karachi = $this->makeZone($provider, 'Karachi', ['karachi'], default: true);

        $this->post(route('couriers.rates.store', $provider), $this->rate($provider, $karachi, $karachi, 0, 1, 150))
            ->assertRedirect();

        $this->post(route('couriers.rates.store', $provider), $this->rate($provider, $karachi, $karachi, 0.5, 2, 200))
            ->assertSessionHasErrors('weight_from_kg');

        $this->assertSame(1, CourierRate::query()->count());
    }

    public function test_shipment_effective_cost_prefers_actual_over_estimate(): void
    {
        $provider = $this->provider('manual');
        $zone = $this->makeZone($provider, 'Default', [], default: true);
        CourierRate::query()->create($this->rate($provider, $zone, $zone, 0, null, 200));

        $shipment = $this->makeShipment($provider, ['weight_kg' => 0.5]);

        $this->assertSame(200.0, $shipment->effectiveCost());
        $this->assertTrue($shipment->costIsEstimated());

        $shipment->forceFill(['cost' => 90.00])->save();
        $shipment->refresh();

        $this->assertSame(90.0, $shipment->effectiveCost());
        $this->assertFalse($shipment->costIsEstimated());
    }

    public function test_shipping_finance_service_includes_estimated_cost_in_totals(): void
    {
        $provider = $this->provider('manual');
        $zone = $this->makeZone($provider, 'Default', [], default: true);
        CourierRate::query()->create($this->rate($provider, $zone, $zone, 0, null, 200));

        // No recorded cost → falls back to the rate-card estimate of 200.
        $this->makeShipment($provider, ['weight_kg' => 0.5, 'consignee_city' => 'Islamabad']);
        // Recorded cost wins over the estimate.
        $this->makeShipment($provider, ['weight_kg' => 0.5, 'cost' => 100.00]);

        $totals = app(ShippingFinanceService::class)->totals(now()->subDays(7), now());

        $this->assertSame(300.0, $totals['shipping_cost']);
        $this->assertSame(100.0, $totals['actual_cost']);
        $this->assertSame(200.0, $totals['estimated_cost']);
    }

    private function provider(string $key): CourierProvider
    {
        return CourierProvider::query()->where('key', $key)->firstOrFail();
    }

    private function makeZone(CourierProvider $provider, string $name, array $cities, bool $default = false): CourierZone
    {
        return CourierZone::query()->create([
            'courier_provider_id' => $provider->id,
            'name' => $name,
            'cities' => array_map(fn (string $city) => DeliveryRateCalculator::normalizeCity($city), $cities),
            'is_default' => $default,
        ]);
    }

    private function rate(CourierProvider $provider, CourierZone $origin, CourierZone $destination, float $from, ?float $to, float $price, ?float $codFee = null): array
    {
        return [
            'courier_provider_id' => $provider->id,
            'origin_zone_id' => $origin->id,
            'destination_zone_id' => $destination->id,
            'weight_from_kg' => $from,
            'weight_to_kg' => $to,
            'price' => $price,
            'cod_fee' => $codFee,
            'currency' => 'PKR',
            'is_active' => true,
        ];
    }

    private function makeShipment(CourierProvider $provider, array $overrides = []): Shipment
    {
        return Shipment::query()->create(array_merge([
            'courier_provider_id' => $provider->id,
            'external_id' => 'RATE-'.strtoupper(bin2hex(random_bytes(4))),
            'tracking_number' => 'RATE-'.strtoupper(bin2hex(random_bytes(2))),
            'status' => ShipmentStatus::Created->value,
            'currency' => 'PKR',
            'shipped_at' => now(),
            'last_event_at' => now(),
        ], $overrides));
    }
}
