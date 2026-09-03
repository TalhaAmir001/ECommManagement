<?php

namespace App\Services;

use App\Enums\Courier\ShipmentStatus;
use App\Services\Courier\DeliveryRateCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the money side of the courier/shipment pipeline for reports.
 *
 * Every shipment carries two financial figures, populated from whichever
 * source created it (manual entry, courier provider API, Shopify
 * fulfillment, or the order "add tracking" action):
 *
 *  - cost         -> what we paid the courier (an expense).
 *  - cod_amount   -> cash collected from the customer on delivery.
 *
 * The Audit page treats these as real money movements:
 *  - courier cost is deducted from net profit (an operating expense);
 *  - COD is counted as cash collected once the parcel is delivered
 *    (income side) and is reported separately so it never double counts
 *    product revenue that was already recognised at sale time.
 *
 * Attribution windows:
 *  - courier cost is recognised on the shipment's `shipped_at`
 *    (fallback: `created_at`) — that is when the cost is incurred.
 *  - COD is recognised on `delivered_at` (fallback: shipped/created) —
 *    that is when the cash actually lands.
 *
 * The service is deliberately DB-agnostic (month bucketing happens in PHP)
 * so the same code runs against MySQL and the SQLite test suite.
 */
class ShippingFinanceService
{
    private ?DeliveryRateCalculator $calculator = null;

    /**
     * Headline shipping figures for a date range (all time when both
     * boundaries are null).
     *
     * @return array{shipping_cost: float, actual_cost: float, estimated_cost: float, cod_collected: float, shipping_net: float}
     */
    public function totals(?Carbon $start, ?Carbon $end): array
    {
        [$from, $to] = $this->window($start, $end);

        $shippingCost = 0.0;
        $actualCost = 0.0;
        $estimatedCost = 0.0;
        $codCollected = 0.0;

        foreach ($this->rows($from, $to) as $row) {
            $costDate = $this->costDate($row);
            $effective = $this->effectiveCost($row);
            if ($costDate !== null && $this->inside($costDate, $from, $to) && $effective !== null && $effective > 0) {
                $shippingCost += $effective;
                if ($this->positive($row->cost)) {
                    $actualCost += (float) $row->cost;
                } else {
                    $estimatedCost += $effective;
                }
            }

            $codDate = $this->codDate($row);
            if ($codDate !== null
                && $this->inside($codDate, $from, $to)
                && $row->status === ShipmentStatus::Delivered->value
                && $this->positive($row->cod_amount)) {
                $codCollected += (float) $row->cod_amount;
            }
        }

        $shippingCost = round($shippingCost, 2);
        $actualCost = round($actualCost, 2);
        $estimatedCost = round($estimatedCost, 2);
        $codCollected = round($codCollected, 2);

        return [
            'shipping_cost' => $shippingCost,
            'actual_cost' => $actualCost,
            'estimated_cost' => $estimatedCost,
            'cod_collected' => $codCollected,
            'shipping_net' => round($codCollected - $shippingCost, 2),
        ];
    }

    /**
     * Per-month shipping figures inside the same range the Audit monthly
     * table covers (last 12 months when no window is given). Every month in
     * the range is present so the caller can merge without null-checks.
     *
     * @return array<int, array{key: string, label: string, shipping_cost: float, cod_collected: float}>
     */
    public function monthly(?Carbon $start, ?Carbon $end): array
    {
        [$from, $to] = $this->window($start, $end);

        $windowStart = ($from ?? Carbon::now()->subMonths(11))->copy()->startOfMonth();
        $windowEnd = ($to ?? Carbon::now())->copy()->startOfMonth();

        $months = [];
        $cursor = $windowStart->copy();
        while ($cursor->lte($windowEnd)) {
            $key = $cursor->format('Y-m');
            $months[$key] = [
                'key' => $key,
                'label' => $cursor->format('M Y'),
                'shipping_cost' => 0.0,
                'cod_collected' => 0.0,
            ];
            $cursor->addMonth();
        }

        foreach ($this->rows($from, $to) as $row) {
            $costDate = $this->costDate($row);
            $effective = $this->effectiveCost($row);
            if ($costDate !== null && $this->inside($costDate, $from, $to) && $effective !== null && $effective > 0) {
                $key = $costDate->format('Y-m');
                if (isset($months[$key])) {
                    $months[$key]['shipping_cost'] = round($months[$key]['shipping_cost'] + $effective, 2);
                }
            }

            $codDate = $this->codDate($row);
            if ($codDate !== null
                && $this->inside($codDate, $from, $to)
                && $row->status === ShipmentStatus::Delivered->value
                && $this->positive($row->cod_amount)) {
                $key = $codDate->format('Y-m');
                if (isset($months[$key])) {
                    $months[$key]['cod_collected'] = round($months[$key]['cod_collected'] + (float) $row->cod_amount, 2);
                }
            }
        }

        return array_values($months);
    }

    /**
     * Normalise the range to inclusive day boundaries. Null means
     * "unbounded" on that side.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function window(?Carbon $start, ?Carbon $end): array
    {
        return [
            $start !== null ? $start->copy()->startOfDay() : null,
            $end !== null ? $end->copy()->endOfDay() : null,
        ];
    }

    /**
     * All shipment rows whose *effective* financial date (either the cost
     * date or the COD date) could land inside the window.
     *
     * @return Collection<int, object>
     */
    private function rows(?Carbon $from, ?Carbon $to): Collection
    {
        $query = DB::table('shipments')
            ->select(
                'id',
                'courier_provider_id',
                'cost',
                'cod_amount',
                'status',
                'weight_kg',
                'consignor_city',
                'consignee_city',
                'shipped_at',
                'delivered_at',
                'created_at'
            );

        if ($from !== null || $to !== null) {
            $costExpr = 'COALESCE(shipped_at, created_at)';
            $codExpr = 'COALESCE(delivered_at, shipped_at, created_at)';

            $query->where(function ($q) use ($from, $to, $costExpr, $codExpr) {
                if ($from !== null && $to !== null) {
                    $q->whereRaw("{$costExpr} BETWEEN ? AND ?", [$from->toDateTimeString(), $to->toDateTimeString()])
                        ->orWhereRaw("{$codExpr} BETWEEN ? AND ?", [$from->toDateTimeString(), $to->toDateTimeString()]);
                } elseif ($from !== null) {
                    $q->whereRaw("{$costExpr} >= ?", [$from->toDateTimeString()])
                        ->orWhereRaw("{$codExpr} >= ?", [$from->toDateTimeString()]);
                } else {
                    $q->whereRaw("{$costExpr} <= ?", [$to->toDateTimeString()])
                        ->orWhereRaw("{$codExpr} <= ?", [$to->toDateTimeString()]);
                }
            });
        }

        return $query->get();
    }

    /**
     * The cost figure reports should count: the actual recorded courier cost
     * when present, otherwise the provider's rate-card estimate for the row.
     */
    private function effectiveCost(object $row): ?float
    {
        if ($this->positive($row->cost)) {
            return (float) $row->cost;
        }

        return $this->calculator()->estimateByProviderId(
            $row->courier_provider_id !== null ? (int) $row->courier_provider_id : null,
            $row->weight_kg !== null && $row->weight_kg !== '' ? (float) $row->weight_kg : 0.0,
            $row->consignor_city,
            $row->consignee_city,
            $row->cod_amount !== null && $row->cod_amount !== '' ? (float) $row->cod_amount : null
        );
    }

    private function calculator(): DeliveryRateCalculator
    {
        return $this->calculator ??= app(DeliveryRateCalculator::class);
    }

    private function costDate(object $row): ?Carbon
    {
        return $this->firstDate([$row->shipped_at, $row->created_at]);
    }

    private function codDate(object $row): ?Carbon
    {
        return $this->firstDate([$row->delivered_at, $row->shipped_at, $row->created_at]);
    }

    /**
     * Parse the first non-empty date string in the list.
     *
     * @param  list<mixed>  $candidates
     */
    private function firstDate(array $candidates): ?Carbon
    {
        foreach ($candidates as $value) {
            if ($value !== null && $value !== '') {
                try {
                    return Carbon::parse($value);
                } catch (\Exception) {
                    // try the next candidate
                }
            }
        }

        return null;
    }

    private function inside(Carbon $date, ?Carbon $from, ?Carbon $to): bool
    {
        if ($from !== null && $date->lt($from)) {
            return false;
        }
        if ($to !== null && $date->gt($to)) {
            return false;
        }

        return true;
    }

    private function positive(mixed $value): bool
    {
        return is_numeric($value) && (float) $value > 0;
    }
}
