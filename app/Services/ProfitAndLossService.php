<?php

namespace App\Services;

use App\Enums\Courier\ShipmentStatus;
use App\Models\Shipment;
use App\Models\VendorPurchase;
use App\Services\Courier\DeliveryRateCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes the store's Profit & Loss waterfall exactly as the client
 * specified:
 *
 *   Gross Sale        = Online payments + Net COD
 *                       (Net COD = COD collected − courier cost on the parcel)
 *   Total Sale        = Gross Sale − Returned products
 *   Gross Profit      = Total Sale − CoGS (Vendor owed)
 *   Total Profit      = Gross Profit + Shipping received (manual shipments only)
 *   Net Profit        = Total Profit − Courier service charges
 *   Total Net Profit  = Net Profit − Expenses + Other income − 4% Tax
 *
 * CoGS is "vendor owed": what we owe vendors for goods received inside the
 * window (vendor_purchases.total_cost by purchase_date).
 *
 * Courier cost is never double counted: a parcel whose COD is recognised is
 * netted inside Net COD; every other parcel's courier cost lands on the
 * "Courier service charges" line.
 */
class ProfitAndLossService
{
    /** Financial statuses that mean the money was paid online at checkout. */
    public const ONLINE_FINANCIAL_STATUSES = [
        'PAID', 'PARTIALLY_PAID', 'AUTHORIZED',
        'paid', 'partially_paid', 'authorized',
    ];

    /** Financial statuses that mean the goods were returned / refunded. */
    public const RETURNED_FINANCIAL_STATUSES = [
        'REFUNDED', 'PARTIALLY_REFUNDED',
        'refunded', 'partially_refunded',
    ];

    /** Order lifecycle statuses that still count as a real sale. */
    public const ACTIVE_ORDER_STATUSES = [
        'pending', 'processing', 'shipped', 'delivered',
        'PENDING', 'PROCESSING', 'SHIPPED', 'DELIVERED',
    ];

    private ?DeliveryRateCalculator $calculator = null;

    /**
     * The configured flat tax rate (4% by default).
     */
    public function taxRate(): float
    {
        return (float) config('finance.tax_rate', 0.04);
    }

    /**
     * The full P&L waterfall for a window. Null boundaries mean "all time".
     *
     * @return array<string, float|int>
     */
    public function totals(?Carbon $start = null, ?Carbon $end = null): array
    {
        $from = $start?->copy()->startOfDay();
        $to = $end?->copy()->endOfDay();

        [$onlinePayments, $onlineOrders] = $this->ordersMoney(
            self::ONLINE_FINANCIAL_STATUSES,
            $from,
            $to,
            self::ACTIVE_ORDER_STATUSES
        );

        [$returned] = $this->ordersMoney(self::RETURNED_FINANCIAL_STATUSES, $from, $to, null);

        [$netCod, $codCollected, $codCost, $courierCharges, $shippingReceived, $codShipments] =
            $this->shipmentMoney($from, $to);

        $cogsVendor = round($this->vendorCogs($from, $to), 2);

        $journal = app(JournalService::class)->pnlTotals($from, $this->upperBound($to));
        $expenses = round((float) $journal['total_expense'], 2);
        $otherIncome = round((float) $journal['total_income'], 2);

        $onlinePayments = round($onlinePayments, 2);
        $returned = round($returned, 2);
        $netCod = round($netCod, 2);
        $shippingReceived = round($shippingReceived, 2);
        $courierCharges = round($courierCharges, 2);

        $grossSale = round($onlinePayments + $netCod, 2);
        $totalSale = round($grossSale - $returned, 2);
        $grossProfit = round($totalSale - $cogsVendor, 2);
        $totalProfit = round($grossProfit + $shippingReceived, 2);
        $netProfit = round($totalProfit - $courierCharges, 2);
        $profitBeforeTax = round($netProfit - $expenses + $otherIncome, 2);
        $tax = round(max($profitBeforeTax, 0.0) * $this->taxRate(), 2);
        $totalNetProfit = round($profitBeforeTax - $tax, 2);

        $orders = $onlineOrders + $codShipments;

        return [
            'orders' => $orders,
            'online_payments' => $onlinePayments,
            'online_orders' => $onlineOrders,
            'cod_collected' => round($codCollected, 2),
            'cod_cost' => round($codCost, 2),
            'net_cod' => $netCod,
            'cod_shipments' => $codShipments,
            'gross_sale' => $grossSale,
            'returned_products' => $returned,
            'total_sale' => $totalSale,
            'cogs_vendor' => $cogsVendor,
            'gross_profit' => $grossProfit,
            'shipping_received' => $shippingReceived,
            'total_profit' => $totalProfit,
            'courier_charges' => $courierCharges,
            'net_profit' => $netProfit,
            'expenses' => $expenses,
            'other_income' => $otherIncome,
            'profit_before_tax' => $profitBeforeTax,
            'tax' => $tax,
            'total_net_profit' => $totalNetProfit,
            'margin' => $totalSale > 0 ? round(($totalNetProfit / $totalSale) * 100, 1) : 0.0,
            'avg_profit_per_order' => $orders > 0 ? round($totalNetProfit / $orders, 2) : 0.0,
        ];
    }

    /**
     * Per-month waterfall rows inside the window (min. one full month).
     *
     * @return array<int, array<string, float|int|string>>
     */
    public function monthly(?Carbon $start = null, ?Carbon $end = null): array
    {
        $today = Carbon::now()->endOfDay();
        $windowEnd = $end !== null ? $end->copy()->endOfDay() : $today;
        $windowStart = $start !== null ? $start->copy()->startOfDay() : $today->copy()->subMonths(11)->startOfMonth();

        $months = [];
        $cursor = $windowStart->copy()->startOfMonth();
        $endCursor = $windowEnd->copy()->startOfMonth();

        while ($cursor->lte($endCursor)) {
            $key = $cursor->format('Y-m');
            $months[$key] = [
                'key' => $key,
                'label' => $cursor->format('M Y'),
                'orders' => 0,
                'online_payments' => 0.0,
                'net_cod' => 0.0,
                'cod_collected' => 0.0,
                'cod_cost' => 0.0,
                'returned_products' => 0.0,
                'cogs_vendor' => 0.0,
                'shipping_received' => 0.0,
                'courier_charges' => 0.0,
                'expenses' => 0.0,
                'other_income' => 0.0,
            ];
            $cursor->addMonth();
        }

        $monthExpr = DB::connection()->getDriverName() === 'mysql'
            ? "DATE_FORMAT(%s, '%%Y-%%m')"
            : "strftime('%%Y-%%m', %s)";

        // Online payments (and online order counts) per month.
        $rows = DB::table('orders')
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.financial_status', self::ONLINE_FINANCIAL_STATUSES)
            ->whereIn('orders.status', self::ACTIVE_ORDER_STATUSES)
            ->whereBetween('orders.created_at', [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()])
            ->selectRaw(sprintf($monthExpr, 'orders.created_at').' as month_key, '
                .'COALESCE(SUM(order_items.quantity * order_items.unit_price), 0) as item_total, '
                .'COUNT(DISTINCT orders.id) as order_count')
            ->groupBy('month_key')
            ->get();

        foreach ($rows as $row) {
            $key = (string) $row->month_key;
            if (! isset($months[$key])) {
                continue;
            }
            $months[$key]['online_payments'] = round((float) $row->item_total, 2);
            $months[$key]['orders'] = (int) $row->order_count;
        }

        // Returned products per month.
        $rows = DB::table('orders')
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.financial_status', self::RETURNED_FINANCIAL_STATUSES)
            ->whereBetween('orders.created_at', [$windowStart->toDateTimeString(), $windowEnd->toDateTimeString()])
            ->selectRaw(sprintf($monthExpr, 'orders.created_at').' as month_key, '
                .'COALESCE(SUM(order_items.quantity * order_items.unit_price), 0) as item_total')
            ->groupBy('month_key')
            ->get();

        foreach ($rows as $row) {
            $key = (string) $row->month_key;
            if (! isset($months[$key])) {
                continue;
            }
            $months[$key]['returned_products'] = round((float) $row->item_total, 2);
        }


        // Shipment money per month (COD, courier charges, manual shipping income).
        [$byMonth] = $this->shipmentMoney($windowStart, $windowEnd, $months);
        $months = $byMonth;

        // Vendor owed (CoGS) per month.
        $cogsExpr = DB::connection()->getDriverName() === 'mysql'
            ? "DATE_FORMAT(purchase_date, '%Y-%m')"
            : "strftime('%Y-%m', purchase_date)";
        $rows = VendorPurchase::query()
            ->where('purchase_date', '>=', $windowStart->toDateString())
            ->where('purchase_date', '<', $windowEnd->copy()->addDay()->toDateString())
            ->selectRaw("{$cogsExpr} as month_key, SUM(total_cost) as total")
            ->groupBy('month_key')
            ->get();

        foreach ($rows as $row) {
            $key = (string) $row->month_key;
            if (isset($months[$key])) {
                $months[$key]['cogs_vendor'] = round((float) $row->total, 2);
            }
        }

        // Journal expenses / other income per month.
        $journalMonthly = app(JournalService::class)->pnlMonthly($windowStart, $this->upperBound($windowEnd));
        foreach ($journalMonthly as $row) {
            $key = (string) $row['key'];
            if (! isset($months[$key])) {
                continue;
            }
            $months[$key]['expenses'] = round((float) $row['expense'], 2);
            $months[$key]['other_income'] = round((float) $row['income'], 2);
        }

        // Derive the waterfall per month.
        $taxRate = $this->taxRate();
        foreach ($months as $key => $row) {
            $grossSale = round((float) $row['online_payments'] + (float) $row['net_cod'], 2);
            $totalSale = round($grossSale - (float) $row['returned_products'], 2);
            $grossProfit = round($totalSale - (float) $row['cogs_vendor'], 2);
            $totalProfit = round($grossProfit + (float) $row['shipping_received'], 2);
            $netProfit = round($totalProfit - (float) $row['courier_charges'], 2);
            $profitBeforeTax = round($netProfit - (float) $row['expenses'] + (float) $row['other_income'], 2);
            $tax = round(max($profitBeforeTax, 0.0) * $taxRate, 2);

            $months[$key]['gross_sale'] = $grossSale;
            $months[$key]['total_sale'] = $totalSale;
            $months[$key]['gross_profit'] = $grossProfit;
            $months[$key]['total_profit'] = $totalProfit;
            $months[$key]['net_profit'] = $netProfit;
            $months[$key]['profit_before_tax'] = $profitBeforeTax;
            $months[$key]['tax'] = $tax;
            $months[$key]['total_net_profit'] = round($profitBeforeTax - $tax, 2);
            $months[$key]['margin'] = $totalSale > 0
                ? round((($profitBeforeTax - $tax) / $totalSale) * 100, 1)
                : 0.0;
        }

        return array_values($months);
    }


    /**
     * Money recognised on orders in a given financial state.
     *
     * @param  list<string>  $financialStatuses
     * @return array{0: float, 1: int} [amount, order count]
     */
    private function ordersMoney(array $financialStatuses, ?Carbon $from, ?Carbon $to, ?array $orderStatuses): array
    {
        $query = DB::table('orders')
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.financial_status', $financialStatuses);

        if ($orderStatuses !== null) {
            $query->whereIn('orders.status', $orderStatuses);
        }
        if ($from !== null) {
            $query->where('orders.created_at', '>=', $from->toDateTimeString());
        }
        if ($to !== null) {
            $query->where('orders.created_at', '<=', $to->toDateTimeString());
        }

        $row = $query->selectRaw(
            'COALESCE(SUM(order_items.quantity * order_items.unit_price), 0) as item_total, '
            .'COALESCE(SUM(orders.total), 0) as order_total, '
            .'COUNT(DISTINCT orders.id) as order_count'
        )->first();

        $amount = (float) $row->item_total > 0
            ? round((float) $row->item_total, 2)
            : round((float) $row->order_total, 2);

        return [$amount, (int) $row->order_count];
    }

    /**
     * Courier money inside the window:
     *
     *  - Net COD         = Σ (cod_amount − courier cost) on delivered COD parcels.
     *  - Courier charges = Σ effective courier cost on every OTHER parcel.
     *  - Shipping income = Σ shipping_charged on manual-provider shipments.
     *
     * When $months is supplied the same figures are bucketed per month:
     * COD lands on its delivery month, courier/shipping on their cost month.
     *
     * @return array{0: array<string, array<string, float|int>>|float, 1: float, 2: float, 3: float, 4: float, 5: int}
     */
    private function shipmentMoney(?Carbon $from, ?Carbon $to, array $months = []): array
    {
        $netCod = 0.0;
        $codCollected = 0.0;
        $codCost = 0.0;
        $courierCharges = 0.0;
        $shippingReceived = 0.0;
        $codShipments = 0;
        $nettedIds = [];

        $rows = $this->shipmentRows($from, $to);

        foreach ($rows as $row) {
            $isDeliveredCod = $this->statusIs($row, ShipmentStatus::Delivered) && $this->positive($row->cod_amount);
            $codDate = $this->firstDate([$row->delivered_at, $row->shipped_at, $row->created_at]);
            $costDate = $this->firstDate([$row->shipped_at, $row->created_at]);
            $effective = $this->effectiveCost($row);
            $cost = $effective !== null && $effective > 0 ? round($effective, 2) : 0.0;

            if ($isDeliveredCod && $codDate !== null && $this->inside($codDate, $from, $to)) {
                $cod = round((float) $row->cod_amount, 2);
                $codCollected += $cod;
                $codCost += $cost;
                $netCod += round($cod - $cost, 2);
                $codShipments++;
                $nettedIds[(int) $row->id] = true;

                if ($months !== []) {
                    $key = $codDate->format('Y-m');
                    if (isset($months[$key])) {
                        $months[$key]['cod_collected'] = round((float) $months[$key]['cod_collected'] + $cod, 2);
                        $months[$key]['cod_cost'] = round((float) $months[$key]['cod_cost'] + $cost, 2);
                        $months[$key]['net_cod'] = round((float) $months[$key]['net_cod'] + ($cod - $cost), 2);
                        $months[$key]['orders'] = (int) $months[$key]['orders'] + 1;
                    }
                }
            }

            // Shipping income received on manual shipments.
            if ($row->provider_key === 'manual' && $this->positive($row->shipping_charged)
                && $costDate !== null && $this->inside($costDate, $from, $to)) {
                $shippingReceived += (float) $row->shipping_charged;
                if ($months !== []) {
                    $key = $costDate->format('Y-m');
                    if (isset($months[$key])) {
                        $months[$key]['shipping_received'] = round(
                            (float) $months[$key]['shipping_received'] + (float) $row->shipping_charged,
                            2
                        );
                    }
                }
            }
        }


        // Second pass: courier charges for parcels not netted into Net COD.
        foreach ($rows as $row) {
            if (isset($nettedIds[(int) $row->id])) {
                continue;
            }
            $costDate = $this->firstDate([$row->shipped_at, $row->created_at]);
            if ($costDate === null || ! $this->inside($costDate, $from, $to)) {
                continue;
            }
            $effective = $this->effectiveCost($row);
            if ($effective === null || $effective <= 0) {
                continue;
            }
            $courierCharges += round($effective, 2);
            if ($months !== []) {
                $key = $costDate->format('Y-m');
                if (isset($months[$key])) {
                    $months[$key]['courier_charges'] = round(
                        (float) $months[$key]['courier_charges'] + $effective,
                        2
                    );
                }
            }
        }

        if ($months !== []) {
            return [$months, $codCollected, $codCost, $courierCharges, $shippingReceived, $codShipments];
        }

        return [
            round($netCod, 2),
            round($codCollected, 2),
            round($codCost, 2),
            round($courierCharges, 2),
            round($shippingReceived, 2),
            $codShipments,
        ];
    }

    /**
     * Shipment rows that touch the window through either their cost date or
     * their COD (delivery) date. Provider key is joined so manual shipments
     * can be distinguished.
     */
    private function shipmentRows(?Carbon $from, ?Carbon $to): Collection
    {
        $query = Shipment::query()
            ->join('courier_providers', 'courier_providers.id', '=', 'shipments.courier_provider_id')
            ->select('shipments.*', 'courier_providers.key as provider_key')
            ->orderBy('shipments.id');

        if ($from !== null || $to !== null) {
            $costExpr = 'COALESCE(shipments.shipped_at, shipments.created_at)';
            $codExpr = 'COALESCE(shipments.delivered_at, shipments.shipped_at, shipments.created_at)';

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
     * Total vendor purchases (CoGS / vendor owed) in the window.
     */
    private function vendorCogs(?Carbon $from, ?Carbon $to): float
    {
        $query = VendorPurchase::query();

        if ($from !== null) {
            $query->where('purchase_date', '>=', $from->toDateString());
        }
        if ($to !== null) {
            $query->where('purchase_date', '<', $to->copy()->addDay()->toDateString());
        }

        return (float) $query->sum('total_cost');
    }

    /**
     * Date-style windows are stored as "Y-m-d H:i:s" even on date columns, so
     * an inclusive upper bound must reach into the next day. Returns null when
     * the window is open-ended.
     */
    private function upperBound(?Carbon $to): ?Carbon
    {
        return $to !== null ? $to->copy()->addDay()->startOfDay() : null;
    }

    /**
     * The courier cost figure reports should count: the actual recorded cost
     * when present, otherwise the provider's rate-card estimate.
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

    /**
     * @param  list<mixed>  $candidates
     */
    private function firstDate(array $candidates): ?Carbon
    {
        foreach ($candidates as $value) {
            if ($value !== null && $value !== '') {
                try {
                    return $value instanceof Carbon ? $value->copy() : Carbon::parse($value);
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

    /**
     * Compare a shipment's status attribute with an enum case, tolerating
     * both the raw string value and the hydrated enum instance.
     */
    private function statusIs(object $row, ShipmentStatus $status): bool
    {
        $value = $row->status;

        return $value instanceof ShipmentStatus
            ? $value === $status
            : $value === $status->value;
    }
}

