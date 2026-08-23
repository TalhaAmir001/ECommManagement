<?php

namespace Database\Seeders;

use App\Models\CourierProvider;
use Illuminate\Database\Seeder;

/**
 * Idempotent seeder — upserts the built-in courier_providers rows on every
 * run so the courier config is always in sync with config/couriers.php.
 *
 * Credentials are intentionally NOT seeded; the admin must enter them
 * through the UI (or env-driven provider rows that ship with placeholders).
 */
class CourierProvidersSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('couriers.providers', []) as $key => $config) {
            CourierProvider::query()->updateOrCreate(
                ['key' => $key],
                [
                    'display_name' => $config['display_name'] ?? $key,
                    'driver_class' => $config['driver_class'],
                    'enabled' => (bool) ($config['enabled'] ?? false),
                    'capabilities' => array_values($config['capabilities'] ?? []),
                    'poll_interval_minutes' => (int) ($config['poll_interval_minutes'] ?? 15),
                    'settings' => $config['settings'] ?? null,
                ],
            );
        }
    }
}
