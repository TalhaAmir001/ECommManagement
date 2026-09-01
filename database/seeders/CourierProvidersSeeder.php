<?php

namespace Database\Seeders;

use App\Models\CourierProvider;
use Illuminate\Database\Seeder;

/**
 * Idempotent seeder — inserts any built-in courier_providers rows that are
 * missing so the courier config is always in sync with config/couriers.php.
 *
 * Existing rows are deliberately left untouched: the settings page is the
 * runtime source of truth (enabled flag, credentials, settings, poll
 * interval), and re-running the seeder must never clobber admin edits.
 *
 * Credentials are intentionally NOT seeded; the admin must enter them
 * through the UI (or env-driven provider rows that ship with placeholders).
 */
class CourierProvidersSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('couriers.providers', []) as $key => $config) {
            CourierProvider::query()->firstOrCreate(
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
