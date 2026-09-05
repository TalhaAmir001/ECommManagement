<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\ProfitAndLossService;
use App\Services\Shopify\ShopifySync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    /**
     * Order statuses that count towards revenue and volume metrics.
     */
    private const ACTIVE_STATUSES = ['pending', 'processing', 'shipped', 'delivered'];

    /**
     * Show the e-commerce dashboard.
     */
    public function index(Request $request): View
    {
        $range = (int) $request->query('range', 30);
        $range = in_array($range, [7, 30, 90], true) ? $range : 30;

        $now = Carbon::now();
        $start = $now->copy()->subDays($range);
        $previousStart = $start->copy()->subDays($range);

        $daily = $this->dailyStats($start, $now, $range);

        $revenueCurrent = round(array_sum(array_column($daily['revenue'], 'value')), 2);
        $ordersCurrent = (int) array_sum(array_column($daily['orders'], 'value'));
        $customersCurrent = (int) array_sum(array_column($daily['customers'], 'value'));
        $aovCurrent = $ordersCurrent > 0 ? round($revenueCurrent / $ordersCurrent, 2) : 0.0;

        $revenuePrevious = round($this->revenueBetween($previousStart, $start), 2);
        $ordersPrevious = (int) $this->ordersBetween($previousStart, $start);
        $customersPrevious = (int) Customer::whereBetween('created_at', [$previousStart, $start])->count();
        $aovPrevious = $ordersPrevious > 0 ? round($revenuePrevious / $ordersPrevious, 2) : 0.0;

        // P&L: the same formula as the Audit report (Gross Sale → Total Net Profit).
        $pnlService = app(ProfitAndLossService::class);
        $pnlCurrent = $pnlService->totals($start, $now);
        $pnlPrevious = $pnlService->totals($previousStart, $start);

        $pnl = [
            'online_payments' => $pnlCurrent['online_payments'],
            'cod_collected' => $pnlCurrent['cod_collected'],
            'cod_cost' => $pnlCurrent['cod_cost'],
            'net_cod' => $pnlCurrent['net_cod'],
            'gross_sale' => $pnlCurrent['gross_sale'],
            'returned_products' => $pnlCurrent['returned_products'],
            'total_sale' => $pnlCurrent['total_sale'],
            'cogs_vendor' => $pnlCurrent['cogs_vendor'],
            'gross_profit' => $pnlCurrent['gross_profit'],
            'shipping_received' => $pnlCurrent['shipping_received'],
            'total_profit' => $pnlCurrent['total_profit'],
            'courier_charges' => $pnlCurrent['courier_charges'],
            'net_profit' => $pnlCurrent['net_profit'],
            'expenses' => $pnlCurrent['expenses'],
            'other_income' => $pnlCurrent['other_income'],
            'tax' => $pnlCurrent['tax'],
            'total_net_profit' => $pnlCurrent['total_net_profit'],
            'total_net_profit_previous' => $pnlPrevious['total_net_profit'],
            'total_net_profit_delta' => $this->percentChange(
                $pnlCurrent['total_net_profit'],
                $pnlPrevious['total_net_profit']
            ),
            'gross_profit_delta' => $this->percentChange(
                $pnlCurrent['gross_profit'],
                $pnlPrevious['gross_profit']
            ),
            'net_profit_delta' => $this->percentChange(
                $pnlCurrent['net_profit'],
                $pnlPrevious['net_profit']
            ),
            'gross_sale_delta' => $this->percentChange(
                $pnlCurrent['gross_sale'],
                $pnlPrevious['gross_sale']
            ),
        ];

        $stats = [
            'revenue' => [
                'label' => 'Gross Sale',
                'value' => $pnlCurrent['gross_sale'],
                'format' => 'currency',
                'delta' => $this->percentChange($pnlCurrent['gross_sale'], $pnlPrevious['gross_sale']),
                'spark' => array_column($daily['revenue'], 'value'),
            ],
            'orders' => [
                'label' => 'Orders',
                'value' => $ordersCurrent,
                'format' => 'number',
                'delta' => $this->percentChange($ordersCurrent, $ordersPrevious),
                'spark' => array_column($daily['orders'], 'value'),
            ],
            'customers' => [
                'label' => 'New Customers',
                'value' => $customersCurrent,
                'format' => 'number',
                'delta' => $this->percentChange($customersCurrent, $customersPrevious),
                'spark' => array_column($daily['customers'], 'value'),
            ],
            'aov' => [
                'label' => 'Avg Order Value',
                'value' => $aovCurrent,
                'format' => 'currency',
                'delta' => $this->percentChange($aovCurrent, $aovPrevious),
                'spark' => array_column($daily['aov'], 'value'),
            ],
        ];

        return view('dashboard.index', [
            'range' => $range,
            'stats' => $stats,
            'pnl' => $pnl,
            'revenueSeries' => $daily['revenue'],
            'salesByCategory' => $this->salesByCategory($start, $now),
            'recentOrders' => Order::with('customer')->latest()->take(8)->get(),
            'topProducts' => $this->topProducts($start, $now, 5),
            'lowStock' => Product::where('stock', '<=', 10)->orderBy('stock')->take(6)->get(),
        ]);
    }

    /**
     * Pull the latest products, customers and orders (including corrected
     * product weights) from Shopify on demand, then backfill shipment weights
     * for order-linked shipments that are still blank/zero.
     *
     * This is the action behind the dashboard's "Sync Shopify data" button —
     * a UI trigger for the same code the scheduled `shopify:sync` command
     * runs. Failures never bubble up as a 500: they are logged and flashed.
     */
    public function syncShopify(Request $request): RedirectResponse
    {
        try {
            $counts = app(ShopifySync::class)->syncAll();

            // Non-forced: only fills shipments whose weight is still null/0,
            // so a courier-recorded weight is never overwritten.
            Artisan::call('shipments:recalculate-weights');

            $message = 'Shopify sync complete — '
                .number_format($counts['products']).' products, '
                .number_format($counts['customers']).' customers, '
                .number_format($counts['orders']).' orders synced.';

            return redirect()->route('dashboard')->with('status', $message);
        } catch (Throwable $e) {
            Log::warning('Manual Shopify sync failed', ['error' => $e->getMessage()]);

            return redirect()->route('dashboard')->with('error', 'Shopify sync failed: '.$e->getMessage());
        }
    }

    /**
     * Build daily series for revenue, orders, new customers and AOV.
     *
     * @return array{revenue: array, orders: array, customers: array, aov: array}
     */
    private function dailyStats(Carbon $start, Carbon $end, int $range): array
    {
        $orderRows = Order::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as day, SUM(total) as revenue, COUNT(*) as orders')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $customerRows = Customer::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as day, COUNT(*) as customers')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $revenue = $orders = $customers = $aov = [];

        for ($i = $range - 1; $i >= 0; $i--) {
            $date = $end->copy()->subDays($i);
            $key = $date->toDateString();

            $revenueValue = (float) ($orderRows[$key]->revenue ?? 0);
            $orderValue = (int) ($orderRows[$key]->orders ?? 0);
            $customerValue = (int) ($customerRows[$key]->customers ?? 0);

            $revenue[] = ['label' => $date->format('M j'), 'value' => $revenueValue];
            $orders[] = ['label' => $date->format('M j'), 'value' => $orderValue];
            $customers[] = ['label' => $date->format('M j'), 'value' => $customerValue];
            $aov[] = ['label' => $date->format('M j'), 'value' => $orderValue > 0 ? round($revenueValue / $orderValue, 2) : 0.0];
        }

        return compact('revenue', 'orders', 'customers', 'aov');
    }

    /**
     * Total revenue (excluding cancelled orders) between two dates.
     */
    private function revenueBetween(Carbon $start, Carbon $end): float
    {
        return (float) Order::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereBetween('created_at', [$start, $end])
            ->sum('total');
    }

    /**
     * Number of active orders between two dates.
     */
    private function ordersBetween(Carbon $start, Carbon $end): int
    {
        return Order::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }

    /**
     * Revenue grouped by product category for the given period.
     *
     * @return array<string, float>
     */
    private function salesByCategory(Carbon $start, Carbon $end): array
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('orders.status', self::ACTIVE_STATUSES)
            ->whereBetween('orders.created_at', [$start, $end])
            ->selectRaw('products.category, SUM(order_items.quantity * order_items.unit_price) as total')
            ->groupBy('products.category')
            ->orderByDesc('total')
            ->pluck('total', 'products.category')
            ->map(fn ($value) => (float) $value)
            ->toArray();
    }

    /**
     * Best-selling products by revenue for the given period.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topProducts(Carbon $start, Carbon $end, int $limit): array
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('orders.status', self::ACTIVE_STATUSES)
            ->whereBetween('orders.created_at', [$start, $end])
            ->selectRaw('products.name, products.category, SUM(order_items.quantity) as units, SUM(order_items.quantity * order_items.unit_price) as revenue')
            ->groupBy('products.id', 'products.name', 'products.category')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'category' => $row->category,
                'units' => (int) $row->units,
                'revenue' => (float) $row->revenue,
            ])
            ->toArray();
    }

    /**
     * Percentage change between two values.
     */
    private function percentChange(float|int $current, float|int $previous): float
    {
        if ($previous == 0) {
            return $current == 0 ? 0.0 : 100.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}

