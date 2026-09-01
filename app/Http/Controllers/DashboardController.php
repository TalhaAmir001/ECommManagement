<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\JournalService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

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

        // P&L: gross profit + journal adjustment.
        $cogsCurrent = $this->cogsBetween($start, $now);
        $cogsPrevious = $this->cogsBetween($previousStart, $start);
        $grossProfitCurrent = round($revenueCurrent - $cogsCurrent, 2);
        $grossProfitPrevious = round($revenuePrevious - $cogsPrevious, 2);

        $journal = app(JournalService::class);
        $journalCurrent = $journal->pnlTotals($start, $now);
        $journalPrevious = $journal->pnlTotals($previousStart, $start);
        $netProfitCurrent = round($grossProfitCurrent + $journalCurrent['net_adjustment'], 2);
        $netProfitPrevious = round($grossProfitPrevious + $journalPrevious['net_adjustment'], 2);

        $stats = [
            'revenue' => [
                'label' => 'Revenue',
                'value' => $revenueCurrent,
                'format' => 'currency',
                'delta' => $this->percentChange($revenueCurrent, $revenuePrevious),
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

        $pnl = [
            'gross_profit' => $grossProfitCurrent,
            'gross_profit_delta' => $this->percentChange($grossProfitCurrent, $grossProfitPrevious),
            'journal_expense' => $journalCurrent['total_expense'],
            'journal_expense_previous' => $journalPrevious['total_expense'],
            'journal_income' => $journalCurrent['total_income'],
            'journal_income_previous' => $journalPrevious['total_income'],
            'journal_net' => $journalCurrent['net_adjustment'],
            'net_profit' => $netProfitCurrent,
            'net_profit_delta' => $this->percentChange($netProfitCurrent, $netProfitPrevious),
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
     * Total COGS (sum of product cost × quantity) between two dates.
     */
    private function cogsBetween(Carbon $start, Carbon $end): float
    {
        return (float) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('orders.status', self::ACTIVE_STATUSES)
            ->whereBetween('orders.created_at', [$start, $end])
            ->sum(DB::raw('order_items.quantity * products.cost'));
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

