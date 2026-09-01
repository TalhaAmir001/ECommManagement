<?php

namespace App\Console\Commands;

use App\Enums\Courier\ShipmentStatus;
use App\Jobs\RefreshShipmentFromLinkJob;
use App\Models\Shipment;
use App\Services\Courier\TrackingLinkResolver;
use Illuminate\Console\Command;

/**
 * php artisan couriers:refresh-links                       # one batch
 * php artisan couriers:refresh-links --all                # keep going until done
 * php artisan couriers:refresh-links --queue              # dispatch jobs, return immediately
 * php artisan couriers:refresh-links --shipment=42        # refresh one shipment by id
 * php artisan couriers:refresh-links --dry-run            # show what would run
 */
class CouriersRefreshLinks extends Command
{
    protected $signature = 'couriers:refresh-links
        {--shipment= : Refresh a single shipment by id}
        {--queue : Dispatch each refresh as a queued job instead of running inline}
        {--all : Keep dispatching in batches until there is nothing left to refresh}
        {--dry-run : Print the shipments that would be refreshed without calling the resolver}';

    protected $description = 'Refresh Shopify-originated shipments by following their tracking URLs';

    public function handle(): int
    {
        $batchSize = (int) config('couriers.link_refresh.batch_size', 50);
        $maxAgeDays = (int) config('couriers.link_refresh.max_age_days', 30);
        $queue = (bool) $this->option('queue');
        $all = (bool) $this->option('all');
        $dryRun = (bool) $this->option('dry-run');

        // Single-shipment short circuit.
        if ($id = (string) $this->option('shipment')) {
            $shipment = Shipment::query()->where('id', (int) $id)->first();
            if ($shipment === null) {
                $this->error("No shipment with id {$id}.");

                return self::FAILURE;
            }
            if ($dryRun) {
                $this->line("Would refresh shipment #{$shipment->id} ({$shipment->tracking_number})");

                return self::SUCCESS;
            }
            if ($queue) {
                RefreshShipmentFromLinkJob::dispatch($shipment->id);
                $this->info("Queued shipment #{$shipment->id} for refresh.");

                return self::SUCCESS;
            }
            (new RefreshShipmentFromLinkJob($shipment->id))->handle(app(TrackingLinkResolver::class));
            $this->info("Refreshed shipment #{$shipment->id}.");

            return self::SUCCESS;
        }

        $cutoff = now()->subDays(max($maxAgeDays, 1));
        $terminal = array_map(fn (ShipmentStatus $s) => $s->value, array_filter(
            ShipmentStatus::cases(),
            fn (ShipmentStatus $s) => $s->isTerminal(),
        ));

        $baseQuery = Shipment::query()
            ->whereNotNull('tracking_url')
            ->where('tracking_url', '!=', '')
            ->whereNotIn('status', $terminal)
            ->where('created_at', '>=', $cutoff)
            ->orderBy('last_event_at');

        $totalDispatched = 0;
        $skippedAlreadyFresh = 0;
        $stop = false;

        do {
            // Exclude shipments that just got an event in the last few
            // minutes so two runs back-to-back don't double-fire.
            $min = now()->subMinutes(RefreshShipmentFromLinkJob::MIN_INTERVAL_MINUTES);
            $batch = (clone $baseQuery)
                ->where(function ($q) use ($min) {
                    $q->whereNull('last_event_at')->orWhere('last_event_at', '<=', $min);
                })
                ->limit($batchSize)
                ->get();

            if ($batch->isEmpty()) {
                $stop = true;
                break;
            }

            foreach ($batch as $shipment) {
                if ($dryRun) {
                    $this->line("- would refresh #{$shipment->id} ({$shipment->tracking_number}) via {$shipment->tracking_url}");

                    continue;
                }
                if ($queue) {
                    RefreshShipmentFromLinkJob::dispatch($shipment->id);
                } else {
                    (new RefreshShipmentFromLinkJob($shipment->id))->handle(app(TrackingLinkResolver::class));
                }
                $totalDispatched++;
            }

            $skippedAlreadyFresh = (clone $baseQuery)
                ->where('last_event_at', '>', $min)
                ->count();
        } while ($all && ! $stop);

        if ($dryRun) {
            $this->info("Dry run complete. {$batch->count()} shipments would be refreshed.");

            return self::SUCCESS;
        }

        $this->info("Refreshed {$totalDispatched} shipment(s). Skipped {$skippedAlreadyFresh} that were already fresh.");

        return self::SUCCESS;
    }
}
