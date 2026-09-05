<?php

namespace Tests\Feature;

use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Courier\Providers\GenericHttpProvider;
use Database\Seeders\CourierProvidersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourierSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CourierProvidersSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_settings_page_lists_all_providers(): void
    {
        $this->get(route('couriers.settings'))
            ->assertOk()
            ->assertSee('Courier settings')
            ->assertSee('Manual Entry')
            ->assertSee('Leopards Courier')
            ->assertSee('TCS')
            ->assertSee('M&P Express')
            ->assertSee('Trax.pk')
            ->assertSee('Add courier');
    }

    public function test_create_page_renders_the_generic_schema(): void
    {
        $this->get(route('couriers.create'))
            ->assertOk()
            ->assertSee('Add a courier service provider')
            ->assertSee('track_endpoint')
            ->assertSee('Authentication')
            ->assertSee('Field map');
    }

    public function test_edit_page_renders_credentials_for_builtin_providers(): void
    {
        $leopards = CourierProvider::query()->where('key', 'leopards')->firstOrFail();

        $this->get(route('couriers.edit', $leopards))
            ->assertOk()
            ->assertSee('Leopards Courier')
            ->assertSee('API key')
            ->assertSee('Base URL');
    }

    public function test_admin_can_add_a_generic_courier_with_encrypted_credentials(): void
    {
        $this->post(route('couriers.store'), [
            'key' => 'my-courier',
            'display_name' => 'My Courier',
            'enabled' => '1',
            'poll_interval_minutes' => '30',
            'capabilities' => ['read_shipments', 'read_events', 'cod_support'],
            'credentials' => ['api_key' => 'top-secret-key'],
            'settings' => [
                'base_url' => 'https://api.mycourier.pk',
                'track_endpoint' => '/track/{tracking_number}',
                'method' => 'GET',
                'auth_type' => 'query',
                'timeout_seconds' => '20',
            ],
        ])->assertRedirect(route('couriers.settings'));

        $this->assertDatabaseHas('courier_providers', [
            'key' => 'my-courier',
            'display_name' => 'My Courier',
            'enabled' => true,
        ]);

        $row = CourierProvider::query()->where('key', 'my-courier')->firstOrFail();

        // Decrypted credentials are readable through the accessor…
        $this->assertSame('top-secret-key', $row->credentials['api_key']);
        // …but never stored in plaintext.
        $this->assertStringNotContainsString('top-secret-key', (string) $row->getRawOriginal('credentials'));

        $this->assertSame('https://api.mycourier.pk', $row->settings['base_url']);
        $this->assertSame('query', $row->settings['auth_type']);
        $this->assertSame(30, $row->poll_interval_minutes);
    }

    public function test_key_must_be_unique_and_slug_safe(): void
    {
        $this->post(route('couriers.store'), [
            'key' => 'leopards', // already seeded
            'display_name' => 'Duplicate',
        ])->assertSessionHasErrors('key');

        $this->post(route('couriers.store'), [
            'key' => 'BAD KEY!',
            'display_name' => 'Bad key',
        ])->assertSessionHasErrors('key');
    }

    public function test_invalid_json_settings_are_rejected(): void
    {
        $this->post(route('couriers.store'), [
            'key' => 'bad-json',
            'display_name' => 'Bad JSON',
            'settings' => ['status_map' => '{not json'],
        ])->assertSessionHasErrors('settings.status_map');
    }

    public function test_admin_can_update_credentials_and_settings(): void
    {
        $provider = CourierProvider::query()->where('key', 'leopards')->firstOrFail();

        $this->put(route('couriers.update', $provider), [
            'display_name' => 'Leopards (updated)',
            'enabled' => '1',
            'poll_interval_minutes' => '30',
            'capabilities' => ['read_shipments', 'read_events', 'cod_support'],
            'credentials' => ['api_key' => 'fresh-key'],
            'settings' => ['base_url' => 'https://merchantapi.leopardscourier.com', 'timeout_seconds' => '10'],
        ])->assertRedirect();

        $provider->refresh();
        $this->assertSame('Leopards (updated)', $provider->display_name);
        $this->assertSame('fresh-key', $provider->credentials['api_key']);
        $this->assertSame('https://merchantapi.leopardscourier.com', $provider->settings['base_url']);
        $this->assertSame(30, $provider->poll_interval_minutes);
        $this->assertTrue($provider->enabled);
    }

    public function test_blank_credential_fields_keep_existing_values(): void
    {
        $provider = CourierProvider::query()->where('key', 'leopards')->firstOrFail();
        $provider->forceFill(['credentials' => ['api_key' => 'keep-me']])->save();

        $this->put(route('couriers.update', $provider), [
            'display_name' => 'Leopards',
            'enabled' => '1',
            'poll_interval_minutes' => '15',
            'capabilities' => ['read_shipments', 'read_events', 'cod_support'],
            'credentials' => ['api_key' => ''],
            'settings' => ['base_url' => 'https://merchantapi.leopardscourier.com', 'timeout_seconds' => '20'],
        ])->assertRedirect();

        $provider->refresh();
        $this->assertSame('keep-me', $provider->credentials['api_key']);
    }

    public function test_toggle_and_sync_actions_work(): void
    {
        $provider = CourierProvider::query()->where('key', 'trax')->firstOrFail();
        $this->assertFalse($provider->enabled);

        $this->post(route('courier-providers.toggle', $provider))->assertRedirect();
        $provider->refresh();
        $this->assertTrue($provider->enabled);

        // Sync with no list endpoint is a successful no-op poll.
        $this->post(route('courier-providers.sync', $provider))->assertRedirect();
        $provider->refresh();
        $this->assertSame('success', $provider->last_sync_status);
    }

    public function test_manual_provider_cannot_be_toggled_off(): void
    {
        $manual = CourierProvider::query()->where('key', 'manual')->firstOrFail();

        $this->post(route('courier-providers.toggle', $manual))->assertSessionHasErrors();
        $manual->refresh();
        $this->assertTrue($manual->enabled);
    }

    public function test_builtin_providers_cannot_be_deleted(): void
    {
        $manual = CourierProvider::query()->where('key', 'manual')->firstOrFail();

        $this->delete(route('couriers.destroy', $manual))->assertSessionHasErrors();
        $this->assertDatabaseHas('courier_providers', ['key' => 'manual']);
    }

    public function test_custom_provider_can_be_deleted(): void
    {
        $row = CourierProvider::query()->create([
            'key' => 'temp-courier',
            'display_name' => 'Temp Courier',
            'driver_class' => GenericHttpProvider::class,
            'enabled' => false,
        ]);

        $this->delete(route('couriers.destroy', $row))->assertRedirect(route('couriers.settings'));
        $this->assertDatabaseMissing('courier_providers', ['key' => 'temp-courier']);
    }

    public function test_provider_with_shipments_cannot_be_deleted(): void
    {
        $row = CourierProvider::query()->create([
            'key' => 'busy-courier',
            'display_name' => 'Busy Courier',
            'driver_class' => GenericHttpProvider::class,
            'enabled' => false,
        ]);

        Shipment::query()->create([
            'courier_provider_id' => $row->id,
            'external_id' => 'EXT-1',
            'tracking_number' => 'TRK-1',
            'status' => ShipmentStatus::Created->value,
        ]);

        $this->delete(route('couriers.destroy', $row))->assertSessionHasErrors();
        $this->assertDatabaseHas('courier_providers', ['key' => 'busy-courier']);
    }
}
