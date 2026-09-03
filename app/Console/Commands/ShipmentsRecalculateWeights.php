<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use Illuminate\Console\Command;

/**
 * Backfill shipment weights from their linked orders.
 *
 * Going forward the ShipmentObserver derives a weight for every shipment
 * saved without one. This command brings pre-existing rows up to the same
 * state: any order-linked shipment with no recorded weight gets
 * `weight_kg` set to the order's total product weight (quantity ×
 * weight), so the DeliveryRateCalculator can price it against the
 * provider's zone/weight rate card.
 *
 * Real courier-reported weights are never overwritten unless --force is
 * given (to reflect a corrected product weight across the board).
 */
class ShipmentsRecalculateWeights extends Command
{
    protected $signature = 'shipments:recalculate-weights
        {--force : Re-derive and overwrite already-recorded weights}';

    protected $description = 'Derive shipment weights from their linked orders\' product weights';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        // A weight of 0 carries no information (Shopify reports unset
        // product weights as 0), so zero rows are treated exactly like
        // blank ones and only ever get a real weight, never a 0.
        $query = Shipment::query()
            ->with(['order.items.product'])
            ->whereNotNull('order_id');

        if (! $force) {
            $query->where(fn ($q) => $q->whereNull('weight_kg')->orWhere('weight_kg', 0));
        }

        $updated = 0;

        $query->chunkById(200, function ($shipments) use (&$updated, $force): void {
            foreach ($shipments as $shipment) {
                $order = $shipment->order;
                if ($order === null) {
                    continue;
                }

                $weight = $order->totalWeightKg();
                $current = $shipment->weight_kg !== null ? (float) $shipment->weight_kg : null;
                $same = $current !== null && $weight !== null && abs($current - $weight) < 0.0005;

                if ($weight !== null && ! $same) {
                    $shipment->forceFill(['weight_kg' => $weight])->save();
                    $updated++;
                } elseif ($force && $weight === null && $current !== null && $current <= 0) {
                    // Nothing to derive yet — clear the meaningless zero so
                    // future saves/backfills re-derive once weights exist.
                    $shipment->forceFill(['weight_kg' => null])->save();
                    $updated++;
                }
            }
        }, 'id');

        $this->info("Shipment weights derived from orders: {$updated} updated.");

        return self::SUCCESS;
    }
}
