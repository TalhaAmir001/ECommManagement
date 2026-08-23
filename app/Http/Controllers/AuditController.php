<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AuditController extends Controller
{
    /**
     * Financial statuses that represent an actual sale (i.e. money was collected
     * and not later voided). Shopify-aligned, with sensible fallbacks for the
     * legacy lowercase statuses used by older seed data.
     *
     * @var list<string>
     */
    private const SUCCESS_FINANCIAL_STATUSES = [
        'PAID', 'PARTIALLY_PAID', 'AUTHORIZED', 'PENDING',
        'paid', 'partially_paid', 'authorized', 'pending',
    ];

    /**
     * Order statuses that count as a real sale. We exclude any cancellation or
     * refund-like states — anything in here is treated as "successful".
     *
     * @var list<string>
     */
    private const SUCCESS_ORDER_STATUSES = [
        'pending', 'processing', 'shipped', 'delivered',
        'PENDING', 'PROCESSING', 'SHIPPED', 'DELIVERED',
    ];

    /**
     * Date-range presets the audit page understands.
     *
     * @var array<string, array{label: string, start: ?string, end: ?string}>
     */
    private const DATE_PRESETS = [
        'today' => ['label' => 'Today', 'start' => 'today', 'end' => 'today'],
        '7d' => ['label' => 'Last 7 days', 'start' => '-6 days', 'end' => 'today'],
        '30d' => ['label' => 'Last 30 days', 'start' => '-29 days', 'end' => 'today'],
        '90d' => ['label' => 'Last 90 days', 'start' => '-89 days', 'end' => 'today'],
        'ytd' => ['label' => 'Year to date', 'start' => 'first day of january this year', 'end' => 'today'],
        '12m' => ['label' => 'Last 12 months', 'start' => '-11 months', 'end' => 'today'],
        'all' => ['label' => 'All time', 'start' => null, 'end' => null],
    ];

    /**
     * Show the Audit (Profit & Loss) report.
     */
    public function index(Request $request): View
    {
        [$start, $end, $activePreset] = $this->resolveRange($request);

        // Base query: every order in range that counts as a successful sale.
        $base = Order::query()
            ->with('items.product')
            ->whereIn('financial_status', self::SUCCESS_FINANCIAL_STATUSES)
            ->whereIn('status', self::SUCCESS_ORDER_STATUSES)
            ->whereNotIn('financial_status', ['REFUNDED', 'VOIDED', 'refunded', 'voided']);

        if ($start !== null) {
            $base->where('orders.created_at', '>=', $start);
        }
        if ($end !== null) {
            // Include the entire end day.
            $base->where('orders.created_at', '<=', $end->copy()->endOfDay());
        }

        $totals = $this->summarize($base);
        $byCategory = $this->byCategory($base);
        $topProducts = $this->topProducts($base, 10);
        $monthly = $this->monthly($start, $end);

        return view('audit.index', [
            'totals' => $totals,
            'byCategory' => $byCategory,
            'topProducts' => $topProducts,
            'monthly' => $monthly,
            'range' => [
                'preset' => $activePreset,
                'start' => $start,
                'end' => $end,
                'label' => $this->humanRange($activePreset, $start, $end),
            ],
            'presets' => self::DATE_PRESETS,
        ]);
    }

    /**
     * Resolve the date range from the query string.
     *
     * @return array{0: ?Carbon, 1: ?Carbon, 2: string}
     */
    private function resolveRange(Request $request): array
    {
        $preset = (string) $request->query('preset', '30d');
        $customFrom = (string) $request->query('from', '');
        $customTo = (string) $request->query('to', '');

        // Custom range wins when both endpoints are provided.
        if ($customFrom !== '' && $customTo !== '') {
            try {
                $start = Carbon::parse($customFrom)->startOfDay();
                $end = Carbon::parse($customTo)->endOfDay();

                return [$start, $end, 'custom'];
            } catch (\Exception) {
                // fall through to preset handling
            }
        }

        if (! array_key_exists($preset, self::DATE_PRESETS)) {
            $preset = '30d';
        }

        $config = self::DATE_PRESETS[$preset];
        $start = $config['start'] ? Carbon::parse($config['start'])->startOfDay() : null;
        $end = $config['end'] ? Carbon::parse($config['end'])->endOfDay() : null;

        return [$start, $end, $preset];
    }

    /**
     * Compute headline totals for the report: revenue, COGS, profit, margin.
     *
     * @return array{
     *     orders: int,
     *     units: int,
     *     revenue: float,
     *     cogs: float,
     *     profit: float,
     *     margin: float,
     *     avg_order_value: float,
     *     avg_profit_per_order: float
     * }
     */
    private function summarize(Builder $base): array
    {
        $orderRows = (clone $base)
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(orders.total), 0) as revenue')
            ->first();

        $orders = (int) ($orderRows->order_count ?? 0);
        $revenue = (float) ($orderRows->revenue ?? 0);

        $itemRows = (clone $base)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as units,
                         COALESCE(SUM(order_items.quantity * order_items.unit_price), 0) as item_revenue,
                         COALESCE(SUM(order_items.quantity * products.cost), 0) as cogs')
            ->first();

        $units = (int) ($itemRows->units ?? 0);
        $itemRevenue = (float) ($itemRows->item_revenue ?? 0);
        $cogs = (float) ($itemRows->cogs ?? 0);

        // Trust the recomputed item revenue when it's present; fall back to orders.total.
        $effectiveRevenue = $itemRevenue > 0 ? $itemRevenue : $revenue;
        $profit = round($effectiveRevenue - $cogs, 2);
        $margin = $effectiveRevenue > 0 ? round(($profit / $effectiveRevenue) * 100, 1) : 0.0;

        return [
            'orders' => $orders,
            'units' => $units,
            'revenue' => round($effectiveRevenue, 2),
            'cogs' => round($cogs, 2),
            'profit' => $profit,
            'margin' => $margin,
            'avg_order_value' => $orders > 0 ? round($effectiveRevenue / $orders, 2) : 0.0,
            'avg_profit_per_order' => $orders > 0 ? round($profit / $orders, 2) : 0.0,
        ];
    }

    /**
     * Revenue / COGS / profit grouped by product category.
     *
     * @return array<int, array{category: string, revenue: float, cogs: float, profit: float, margin: float}>
     */
    private function byCategory(Builder $base): array
    {
        $rows = (clone $base)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->selectRaw('products.category as category,
                         SUM(order_items.quantity * order_items.unit_price) as revenue,
                         SUM(order_items.quantity * products.cost) as cogs')
            ->groupBy('products.category')
            ->orderByDesc('revenue')
            ->get();

        return $rows->map(function ($row) {
            $revenue = (float) $row->revenue;
            $cogs = (float) $row->cogs;
            $profit = round($revenue - $cogs, 2);
            $margin = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0.0;

            return [
                'category' => $row->category ?: 'Uncategorized',
                'revenue' => round($revenue, 2),
                'cogs' => round($cogs, 2),
                'profit' => $profit,
                'margin' => $margin,
            ];
        })->all();
    }

    /**
     * Most profitable products inside the selected range.
     *
     * @return array<int, array{name: string, sku: string, category: string, units: int, revenue: float, cogs: float, profit: float, margin: float}>
     */
    private function topProducts(Builder $base, int $limit): array
    {
        $rows = (clone $base)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->selectRaw('products.name as name,
                         products.sku as sku,
                         products.category as category,
                         SUM(order_items.quantity) as units,
                         SUM(order_items.quantity * order_items.unit_price) as revenue,
                         SUM(order_items.quantity * products.cost) as cogs')
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.category')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) {
            $revenue = (float) $row->revenue;
            $cogs = (float) $row->cogs;
            $profit = round($revenue - $cogs, 2);
            $margin = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0.0;

            return [
                'name' => $row->name,
                'sku' => $row->sku,
                'category' => $row->category ?: 'Uncategorized',
                'units' => (int) $row->units,
                'revenue' => round($revenue, 2),
                'cogs' => round($cogs, 2),
                'profit' => $profit,
                'margin' => $margin,
            ];
        })->all();
    }

    /**
     * Monthly profit & loss view, oldest to newest. Always covers the last
     * 12 months (or the range itself, whichever is shorter) so the chart and
     * the table stay useful even for short custom windows.
     *
     * @return array<int, array{key: string, label: string, revenue: float, cogs: float, profit: float, margin: float, orders: int}>
     */
    private function monthly(?Carbon $start, ?Carbon $end): array
    {
        $today = Carbon::now()->endOfDay();
        $windowEnd = $end !== null ? $end->copy()->endOfDay() : $today;
        $windowStart = $start !== null ? $start->copy()->startOfDay() : $today->copy()->subMonths(11)->startOfMonth();

        $cursor = $windowStart->copy()->startOfMonth();
        $endCursor = $windowEnd->copy()->startOfMonth();

        // Build a month skeleton so empty months still render with zeros.
        $months = [];
        while ($cursor->lte($endCursor)) {
            $key = $cursor->format('Y-m');
            $months[$key] = [
                'key' => $key,
                'label' => $cursor->format('M Y'),
                'revenue' => 0.0,
                'cogs' => 0.0,
                'profit' => 0.0,
                'margin' => 0.0,
                'orders' => 0,
            ];
            $cursor->addMonth();
        }

        $query = Order::query()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('orders.financial_status', self::SUCCESS_FINANCIAL_STATUSES)
            ->whereIn('orders.status', self::SUCCESS_ORDER_STATUSES)
            ->whereNotIn('orders.financial_status', ['REFUNDED', 'VOIDED', 'refunded', 'voided'])
            ->whereBetween('orders.created_at', [$windowStart, $windowEnd])
            ->selectRaw('DATE_FORMAT(orders.created_at, "%Y-%m") as month_key,
                         SUM(order_items.quantity * order_items.unit_price) as revenue,
                         SUM(order_items.quantity * products.cost) as cogs,
                         COUNT(DISTINCT orders.id) as orders')
            ->groupBy('month_key')
            ->get();

        foreach ($query as $row) {
            $key = (string) $row->month_key;
            if (! isset($months[$key])) {
                continue;
            }
            $revenue = (float) $row->revenue;
            $cogs = (float) $row->cogs;
            $months[$key]['revenue'] = round($revenue, 2);
            $months[$key]['cogs'] = round($cogs, 2);
            $months[$key]['profit'] = round($revenue - $cogs, 2);
            $months[$key]['margin'] = $revenue > 0 ? round((($revenue - $cogs) / $revenue) * 100, 1) : 0.0;
            $months[$key]['orders'] = (int) $row->orders;
        }

        return array_values($months);
    }

    /**
     * Build a short, human-readable label for the active date range.
     */
    private function humanRange(string $preset, ?Carbon $start, ?Carbon $end): string
    {
        if ($preset === 'custom' && $start && $end) {
            return $start->format('M j, Y').' – '.$end->format('M j, Y');
        }
        if ($preset === 'all') {
            return 'All time';
        }
        if (isset(self::DATE_PRESETS[$preset])) {
            return self::DATE_PRESETS[$preset]['label'];
        }

        return $start && $end
            ? $start->format('M j, Y').' – '.$end->format('M j, Y')
            : 'All time';
    }
}
