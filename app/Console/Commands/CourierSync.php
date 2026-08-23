<?php

namespace App\Console\Commands;

use App\Jobs\SyncCourierProviderJob;
use App\Models\CourierProvider as CourierProviderModel;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan couriers:sync                  # all due providers, sync
 * php artisan couriers:sync --provider=leopards
 * php artisan couriers:sync --all            # force every enabled provider
 * php artisan couriers:sync --queue          # dispatch as jobs instead of running inline
 */
class CourierSync extends Command
{
    protected $signature = 'couriers:sync
        {--provider= : Only sync this provider key (e.g. leopards)}
        {--all : Force every enabled provider, ignoring poll cadence}
        {--queue : Dispatch each sync as a queued job instead of running inline}';

    protected $description = 'Pull the latest shipments and events from one or all configured courier providers';

    public function handle(): int
    {
        $query = CourierProviderModel::query()->where('enabled', true);

        if ($key = (string) $this->option('provider')) {
            $query->where('key', $key);
        }

        $providers = $query->get();
        if ($providers->isEmpty()) {
            $this->warn('No enabled courier providers matched.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('all');
        $useQueue = (bool) $this->option('queue');
        $synced = 0;
        $skipped = 0;

        foreach ($providers as $provider) {
            if (! $force && ! $provider->isDueForSync()) {
                $lastSync = $provider->last_synced_at?->diffForHumans() ?? 'never';
                $this->line("- {$provider->key}: not due (last sync {$lastSync})");
                $skipped++;
                continue;
            }

            if ($useQueue) {
                SyncCourierProviderJob::dispatch($provider->id);
                $this->info("+ {$provider->key}: queued");
                $synced++;
                continue;
            }

            try {
                (new SyncCourierProviderJob($provider->id))->handle(app(\App\Services\Courier\CourierProviderRegistry::class));
                $this->info("+ {$provider->key}: synced");
                $synced++;
            } catch (Throwable $e) {
                $this->error("! {$provider->key}: {$e->getMessage()}");
            }
        }

        $this->info("Done. Synced: {$synced}, Skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
