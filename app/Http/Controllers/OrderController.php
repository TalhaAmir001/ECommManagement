<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class OrderController extends Controller
{
    /**
     * Payment (financial) statuses, mirroring Shopify's Orders filter.
     *
     * @var list<string>
     */
    private const PAYMENT_STATUSES = [
        'AUTHORIZED', 'PENDING', 'PARTIALLY_PAID', 'PAID',
        'PARTIALLY_REFUNDED', 'REFUNDED', 'VOIDED',
    ];

    /**
     * Fulfillment statuses, mirroring Shopify's Orders filter.
     *
     * @var list<string>
     */
    private const FULFILLMENT_STATUSES = [
        'UNFULFILLED', 'PARTIALLY_FULFILLED', 'SCHEDULED', 'ON_HOLD', 'FULFILLED',
    ];

    /**
     * Columns the orders table can be sorted by.
     *
     * @var list<string>
     */
    private const SORTABLE_COLUMNS = ['number', 'created_at', 'total'];

    /**
     * Show the Shopify-style orders list.
     */
    public function index(Request $request): View
    {
        $query = Order::query()
            ->with('customer')
            ->withCount('items');

        $this->applyFilters($query, $request);
        $this->applySorting($query, $request);

        $stats = $this->buildStats($query);

        $orders = $query->paginate(20)->withQueryString();

        return view('orders.index', [
            'orders' => $orders,
            'stats' => $stats,
            'filters' => $request->query(),
            'paymentStatuses' => self::PAYMENT_STATUSES,
            'fulfillmentStatuses' => self::FULFILLMENT_STATUSES,
        ]);
    }

    public function updates(Request $request): JsonResponse
    {
        $since = $request->query('since');
        $latest = $this->latestUpdatedAt();

        $changed = false;
        if ($since !== null && $latest !== null) {
            try {
                $sinceDate = Carbon::parse($since);
                if ($latest->gt($sinceDate)) {
                    $changed = true;
                }
            } catch (\Exception) {
                $changed = false;
            }
        }

        return response()->json([
            'changed' => $changed,
            'latest_updated_at' => $latest?->toIso8601String(),
        ]);
    }

    public function rows(Request $request): JsonResponse
    {
        $since = $request->query('since');

        $query = Order::query()
            ->with('customer')
            ->withCount('items');

        if ($since !== null) {
            try {
                $query->where('updated_at', '>', Carbon::parse($since));
            } catch (\Exception) {
                // ignore malformed date
            }
        }

        $orders = $query
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $rows = [];
        foreach ($orders as $order) {
            $rows[] = [
                'id' => $order->id,
                'shopify_id' => $order->shopify_id,
                'html' => view('orders._row', ['order' => $order])->render(),
            ];
        }

        $latestUpdatedAt = $this->latestUpdatedAt();

        return response()->json([
            'rows' => $rows,
            'latest_updated_at' => $latestUpdatedAt?->toIso8601String(),
        ]);
    }

    /**
     * The most recent updated_at across all orders, as a Carbon instance
     * (or null when there are no orders). Eloquent aggregate methods return
     * a raw database string, so the value must be cast before use.
     */
    private function latestUpdatedAt(): ?Carbon
    {
        $latest = Order::query()->max('updated_at');

        if ($latest === null) {
            return null;
        }

        return Carbon::parse($latest);
    }

    /**
     * Build summary metrics for the currently filtered orders.
     *
     * @return array{count: int, revenue: float, aov: float, open: int}
     */
    private function buildStats(Builder $query): array
    {
        $count = (int) (clone $query)->count();
        $revenue = (float) (clone $query)->sum('orders.total');
        $open = (int) (clone $query)->where(function (Builder $q) {
            $q->where(function (Builder $q2) {
                $q2->where('orders.fulfillment_status', '!=', 'FULFILLED')
                    ->orWhereNull('orders.fulfillment_status');
            })->whereNotIn('orders.financial_status', ['REFUNDED', 'VOIDED']);
        })->count();

        return [
            'count' => $count,
            'revenue' => $revenue,
            'aov' => $count > 0 ? $revenue / $count : 0.0,
            'open' => $open,
        ];
    }

    /**
     * Apply the query-string filters to the orders query.
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        $filters = $request->query();

        // Search across order number, customer name and customer email.
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('orders.number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function (Builder $cq) use ($search) {
                        $cq->where('customers.name', 'like', "%{$search}%")
                            ->orWhere('customers.email', 'like', "%{$search}%");
                    });
            });
        }

        // Payment (financial) status.
        $payment = (string) ($filters['payment'] ?? '');
        if (in_array($payment, self::PAYMENT_STATUSES, true)) {
            $query->where('orders.financial_status', $payment);
        }

        // Fulfillment status.
        $fulfillment = (string) ($filters['fulfillment'] ?? '');
        if (in_array($fulfillment, self::FULFILLMENT_STATUSES, true)) {
            $query->where('orders.fulfillment_status', $fulfillment);
        }

        // Open / closed orders.
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'open') {
            $query->where(function (Builder $q) {
                $q->where(function (Builder $q2) {
                    $q2->where('orders.fulfillment_status', '!=', 'FULFILLED')
                        ->orWhereNull('orders.fulfillment_status');
                })->whereNotIn('orders.financial_status', ['REFUNDED', 'VOIDED']);
            });
        } elseif ($status === 'closed') {
            $query->where(function (Builder $q) {
                $q->where('orders.fulfillment_status', 'FULFILLED')
                    ->orWhereIn('orders.financial_status', ['REFUNDED', 'VOIDED']);
            });
        }

        // Date presets.
        $date = (string) ($filters['date'] ?? '');
        if ($date === 'today') {
            $query->whereDate('orders.created_at', Carbon::today());
        } elseif ($date === '7d') {
            $query->whereDate('orders.created_at', '>=', Carbon::today()->subDays(6));
        } elseif ($date === '30d') {
            $query->whereDate('orders.created_at', '>=', Carbon::today()->subDays(29));
        }

        // Custom date range.
        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');
        if ($from !== '') {
            $query->whereDate('orders.created_at', '>=', $from);
        }
        if ($to !== '') {
            $query->whereDate('orders.created_at', '<=', $to);
        }
    }

    /**
     * Apply sorting to the orders query.
     */
    private function applySorting(Builder $query, Request $request): void
    {
        $sort = (string) $request->query('sort', 'created_at');
        $direction = (string) $request->query('direction', 'desc');

        $sort = in_array($sort, self::SORTABLE_COLUMNS, true) ? $sort : 'created_at';
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';

        $query->orderBy('orders.'.$sort, $direction);
    }
}
