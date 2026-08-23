<?php

namespace Tests\Feature;

use App\Enums\Courier\ShipmentStatus;
use App\Jobs\SyncCourierProviderJob;
use App\Models\CourierProvider;
use App\Models\Shipment;
use App\Services\Courier\CourierProviderRegistry;
use App\Services\Courier\Providers\FakeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SyncCourierProviderJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CourierProvidersSeeder::class);
    }

    public function test_it_upserts_a_new_shipment_from_the_provider(): void
    {
        $provider = CourierProvider::query()->where('key', 'manual')->firstOrFail();

        $this->bindFakeProvider($provider->key, new FakeProvider(
            providerKey: $provider->key,
            shipments: [
                new \App\Services\Courier\Normalization\RawShipment(
                    externalId: 'EXT-001',
                    trackingNumber: 'TRK-001',
                    reference: null,
                    status: ShipmentStatus::InTransit,
                    shippedAt: Carbon::parse('2026-08-01 10:00:00'),
                    lastEventAt: Carbon::parse('2026-08-02 10:00:00'),
                    consignee: new \App\Services\Courier\Normalization\Address('Receiver', '03001234567', 'A', 'Karachi'),
                ),
            ],
        ));

        (new SyncCourierProviderJob($provider->id))->handle($this->app->make(CourierProviderRegistry::class));

        $shipment = Shipment::query()->where('external_id', 'EXT-001')->firstOrFail();
        $this->assertSame('TRK-001', $shipment->tracking_number);
        $this->assertSame('In transit', $shipment->status->label());
        $this->assertSame('Receiver', $shipment->consignee_name);
    }

    public function test_it_skips_providers_that_are_not_due_for_sync(): void
    {
        $provider = CourierProvider::query()->where('key', 'manual')->firstOrFail();
        $provider->forceFill(['last_synced_at' => now(), 'last_sync_status' => 'success'])->save();

        // The ManualProvider's poll_interval is 0 in the seeder, which means
        // never due. So the job should no-op.
        (new SyncCourierProviderJob($provider->id))->handle($this->app->make(CourierProviderRegistry::class));

        $this->assertSame(0, Shipment::query()->count());
    }

    public function test_it_records_last_sync_status_on_success(): void
    {
        $provider = CourierProvider::query()->where('key', 'manual')->firstOrFail();
        $provider->forceFill(['poll_interval_minutes' => 0])->save();
        $provider->refresh();
        // For the job to consider this due, it must be enabled. We force
        // a sync by setting last_synced_at to null.
        $provider->forceFill(['last_synced_at' => null, 'poll_interval_minutes' => 0])->save();

        $this->bindFakeProvider($provider->key, new FakeProvider(
            providerKey: $provider->key,
            shipments: [],
        ));

        (new SyncCourierProviderJob($provider->id))->handle($this->app->make(CourierProviderRegistry::class));

        $provider->refresh();
        $this->assertSame('success', $provider->last_sync_status);
        $this->assertNull($provider->last_sync_error);
    }

    private function bindFakeProvider(string $key, FakeProvider $fake): void
    {
        // Override the registry so it returns our fake for this key.
        $registry = new class($fake, $key) extends CourierProviderRegistry {
            public function __construct(private FakeProvider $fake, private string $key) {}

            public function resolve(\App\Models\CourierProvider $row): \App\Services\Courier\CourierProvider
            {
                if ($row->key === $this->key) {
                    return $this->fake;
                }

                return parent::resolve($row);
            }
        };
        $this->app->instance(CourierProviderRegistry::class, $registry);
    }
}
