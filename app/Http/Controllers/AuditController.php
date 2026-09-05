<?php

namespace App\Http\Controllers;

use App\Models\JournalCategory;
use App\Models\Order;
use App\Services\JournalService;
use App\Services\ProfitAndLossService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Audit (Profit & Loss) report.
 *
 * The headline numbers follow the agreed client formula:
 *
 *   Gross Sale        = Online payments + Net COD
 *   Total Sale        = Gross Sale − Returned products
 *   Gross Profit      = Total Sale − CoGS (Vendor owed)
 *   Total Profit      = Gross Profit + Shipping received (manual shipments only)
 *   Net Profit        = Total Profit − Courier service charges
 *   Total Net Profit  = Net Profit − Expenses + Other income − 4% Tax
 *
 * Product/category breakdowns on this page stay product-level insights built
 * from the "successful sales" order set (not the cash waterfall above).
 */
class AuditController extends Controller
{
    /**
     * Financial statuses that represent an actual sale (i.e. money was
     * collected and not later voided). Shopify-aligned, with fallbacks for
     * legacy lowercase statuses used by older seed data.
     *
     * @var list<string>
     */
    private const SUCCESS_FINANCIAL_STATUSES = [
        'PAID', 'PARTIALLY_PAID', 'AUTHORIZED', 'PENDING',
        'paid', 'partially_paid', 'authorized', 'pending',
    ];

    /**
     * Order statuses that count as a real sale. We exclude cancellations and
     * refund-like states.
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

        $pnl = app(ProfitAndLossService::class);
        $totals = $pnl->totals($start, $end);
        $monthly = $pnl->monthly($start, $end);

        // Product / category insights use the successful-sales order set.
        $base = $this->successfulOrdersQuery($start, $end);
        $byCategory = $this->byCategory($base);
        $topProducts = $this->topProducts($base, 10);

        $journalTotals = app(JournalService::class)->pnlTotals(
            $start?->copy()->startOfDay(),
            $end?->copy()->endOfDay()
        );
        $journalByCategory = $this->journalBreakdownByCategory($journalTotals['by_category']);

        return view('audit.index', [
            'totals' => $totals,
            'monthly' => $monthly,
            'byCategory' => $byCategory,
            'topProducts' => $topProducts,
            'journalTotals' => $journalTotals,
            'journalByCategory' => $journalByCategory,
            'taxRate' => $pnl->taxRate(),
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
     * Successful-sales order query used for product/category insights.
     */
    private function successfulOrdersQuery(?Carbon $start, ?Carbon $end): Builder
    {
        $query = Order::query()
            ->with('items.product')
            ->whereIn('financial_status', self::SUCCESS_FINANCIAL_STATUSES)
            ->whereIn('status', self::SUCCESS_ORDER_STATUSES)
            ->whereNotIn('financial_status', ['REFUNDED', 'VOIDED', 'refunded', 'voided']);

        if ($start !== null) {
            $query->where('orders.created_at', '>=', $start);
        }
        if ($end !== null) {
            $query->where('orders.created_at', '<=', $end->copy()->endOfDay());
        }

        return $query;
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
     * @return array<int, array{name: string, sku: ?string, category: string, units: int, revenue: float, cogs: float, profit: float, margin: float}>
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
     * Format the journal P&L by-category list for the audit view.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $byCategory
     * @return array<int, array{name: string, type: string, account: string, color: ?string, amount: float, signed: float}>
     */
    private function journalBreakdownByCategory($byCategory): array
    {
        $categoryIds = $byCategory
            ->pluck('category_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $categories = JournalCategory::with('defaultAccount')
            ->whereIn('id', $categoryIds)
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($byCategory as $row) {
            $category = $categories->get($row->category_id);
            if (! $category) {
                continue;
            }
            $amount = (float) $row->amount;
            $rows[] = [
                'name' => $category->name,
                'type' => $category->type,
                'account' => $category->defaultAccount?->name ?? '—',
                'color' => $category->color,
                'amount' => abs($amount),
                'signed' => $amount,
            ];
        }

        usort($rows, function ($a, $b) {
            return abs($b['signed']) <=> abs($a['signed']);
        });

        return $rows;
    }
}

