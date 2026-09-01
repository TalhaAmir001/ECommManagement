<?php

namespace Tests\Unit\Courier;

use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider;
use App\Models\Shipment;
use App\Services\Courier\CourierProviderRegistry;
use App\Services\Courier\Exceptions\CourierException;
use App\Services\Courier\Normalization\RawShipment;
use App\Services\Courier\Providers\FakeProvider;
use App\Services\Courier\TrackingLinkResolver;
use App\Services\Courier\WebTracking\GenericWebTracker;
use App\Services\Courier\WebTracking\StatusTextMapper;
use App\Services\Courier\WebTracking\TrackingUrlProbe;
use Database\Seeders\CourierProvidersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use Tests\TestCase;

class TrackingLinkResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CourierProvidersSeeder::class);
    }

    private function makeResolver(array $overrides = []): TrackingLinkResolver
    {
        return new TrackingLinkResolver(
            probe: new TrackingUrlProbe($overrides['hosts'] ?? [
                'leopardscourier.com' => 'leopards',
            ]),
            webTracker: $overrides['webTracker'] ?? new GenericWebTracker(new StatusTextMapper),
            registry: app(CourierProviderRegistry::class),
            knownHosts: $overrides['hosts'] ?? [
                'leopardscourier.com' => 'leopards',
            ],
            providerKeysWithStructuredApi: $overrides['apiKeys'] ?? ['leopards'],
        );
    }

    public function test_returns_null_when_shipment_has_no_tracking_url(): void
    {
        $provider = CourierProvider::query()->where('key', 'shopify')->firstOrFail();
        $shipment = Shipment::create([
            'courier_provider_id' => $provider->id,
            'external_id' => 'gid://shopify/Fulfillment/1',
            'tracking_number' => 'LP1',
            'status' => ShipmentStatus::InTransit->value,
        ]);

        $this->assertNull($this->makeResolver()->resolve($shipment));
    }

    public function test_routes_to_structured_api_when_courier_is_known_and_provider_is_registered(): void
    {
        $provider = CourierProvider::query()->where('key', 'leopards')->firstOrFail();
        // Bind a fake Leopards that returns a delivered shipment. The
        // FakeProvider matches by externalId, so we set it equal to the
        // tracking number — that's how the resolver will look it up.
        $this->app->instance(
            CourierProviderRegistry::class,
            $this->newRegistryWithFake($provider, new FakeProvider(
                providerKey: 'leopards',
                shipments: [
                    new RawShipment(
                        externalId: 'LP1',
                        trackingNumber: 'LP1',
                        status: ShipmentStatus::Delivered,
                        statusDetail: 'Delivered to recipient',
                        shippedAt: Carbon::parse('2026-08-25 10:00:00'),
                        deliveredAt: Carbon::parse('2026-08-26 10:00:00'),
                        lastEventAt: Carbon::parse('2026-08-26 10:00:00'),
                    ),
                ],
            )),
        );

        $shipment = Shipment::create([
            'courier_provider_id' => $provider->id,
            'external_id' => 'gid://shopify/Fulfillment/42',
            'tracking_number' => 'LP1',
            'status' => ShipmentStatus::InTransit->value,
            'tracking_url' => 'https://leopardscourier.com/?cn=LP1',
        ]);

        $raw = $this->makeResolver()->resolve($shipment);

        $this->assertNotNull($raw);
        $this->assertSame(ShipmentStatus::Delivered, $raw->status);
        $this->assertSame('api:leopards', $raw->raw['resolver_source']);
    }

    public function test_falls_back_to_web_tracker_for_unknown_carriers(): void
    {
        $provider = CourierProvider::query()->where('key', 'shopify')->firstOrFail();

        Http::fake([
            'dhl.com/*' => Http::response(
                '<html><head><meta name="description" content="DHL tracking: Delivered to recipient."></head><body></body></html>',
                200,
            ),
        ]);

        $shipment = Shipment::create([
            'courier_provider_id' => $provider->id,
            'external_id' => 'gid://shopify/Fulfillment/2',
            'tracking_number' => 'DHL999',
            'status' => ShipmentStatus::InTransit->value,
            'tracking_url' => 'https://www.dhl.com/track?id=DHL999',
        ]);

        $raw = $this->makeResolver([
            'hosts' => ['leopardscourier.com' => 'leopards'], // dhl not in the map
        ])->resolve($shipment);

        $this->assertNotNull($raw);
        $this->assertSame(ShipmentStatus::Delivered, $raw->status);
        $this->assertSame('web:unknown', $raw->raw['resolver_source']);
    }

    public function test_falls_back_to_web_tracker_when_known_carrier_api_throws(): void
    {
        // Build a registry that throws on leopards.
        $registry = $this->createMock(CourierProviderRegistry::class);
        $registry->method('findByKey')->willReturn($this->throwingFakeLeopards());

        Http::fake([
            'leopardscourier.com/*' => Http::response(
                '<html><head><title>Out for delivery</title></head><body></body></html>',
                200,
            ),
        ]);

        $provider = CourierProvider::query()->where('key', 'shopify')->firstOrFail();
        $shipment = Shipment::create([
            'courier_provider_id' => $provider->id,
            'external_id' => 'gid://shopify/Fulfillment/3',
            'tracking_number' => 'LP2',
            'status' => ShipmentStatus::InTransit->value,
            'tracking_url' => 'https://leopardscourier.com/?cn=LP2',
        ]);

        $resolver = new TrackingLinkResolver(
            probe: new TrackingUrlProbe(['leopardscourier.com' => 'leopards']),
            webTracker: new GenericWebTracker(new StatusTextMapper),
            registry: $registry,
            knownHosts: ['leopardscourier.com' => 'leopards'],
            providerKeysWithStructuredApi: ['leopards'],
        );

        $raw = $resolver->resolve($shipment);

        $this->assertNotNull($raw);
        $this->assertSame(ShipmentStatus::OutForDelivery, $raw->status);
    }

    public function test_strategy_for_reports_api_or_web(): void
    {
        $resolver = $this->makeResolver();

        $provider = CourierProvider::query()->where('key', 'shopify')->firstOrFail();

        $base = [
            'courier_provider_id' => $provider->id,
            'external_id' => 'gid://shopify/Fulfillment/X',
            'tracking_number' => 'X1',
            'status' => ShipmentStatus::InTransit->value,
        ];

        $shopify = new Shipment(array_merge($base, ['tracking_url' => 'https://leopardscourier.com/?cn=X1']));
        $this->assertSame('api:leopards', $resolver->strategyFor($shopify));

        $unknown = new Shipment(array_merge($base, ['tracking_url' => 'https://example.com/track?id=X1']));
        $this->assertSame('web:unknown', $resolver->strategyFor($unknown));

        $none = new Shipment(array_merge($base, ['tracking_url' => null]));
        $this->assertSame('none', $resolver->strategyFor($none));
    }

    private function newRegistryWithFake(CourierProvider $row, FakeProvider $fake): CourierProviderRegistry
    {
        $registry = $this->createMock(CourierProviderRegistry::class);
        $registry->method('findByKey')->willReturnCallback(function (string $key) use ($fake) {
            return $key === 'leopards' ? $fake : null;
        });
        $registry->method('resolve')->willReturn($fake);

        return $registry;
    }

    private function throwingFakeLeopards(): FakeProvider
    {
        return new class extends FakeProvider
        {
            public function getShipment(string $externalId): ?RawShipment
            {
                throw new CourierException('simulated API failure');
            }

            public function listEvents(string $externalId, ?Carbon $since = null): LazyCollection
            {
                return LazyCollection::make();
            }
        };
    }
}
