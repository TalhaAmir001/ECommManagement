<?php

namespace App\Services\Courier;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Centralised "find an order to associate with" lookup.
 *
 * Two consumers:
 *  - The order typeahead endpoint (AJAX) returns a JSON list of recent
 *    matching orders, formatted for the typeahead widget.
 *  - The auto-matcher fallback path uses the same primitives so a human
 *    decision and an algorithmic one share one source of truth on what
 *    "matches" means.
 *
 * Every public method is conservative on purpose. It will not return an
 * order that doesn't exist, and it never silently coerces bad input —
 * bad input is filtered out so the typeahead only ever shows orders the
 * operator is safe to pick.
 */
class OrderLookup
{
    /**
     * Maximum number of suggestions the typeahead should display at once.
     * Above this the UX gets noisy and the response gets slow on SQLite.
     */
    public const MAX_RESULTS = 12;

    /**
     * Hard cap on how far back we search. Orders older than this are
     * unlikely to need a new shipment link, and skipping them keeps the
     * query fast on stores with many years of history.
     */
    public const LOOKBACK_DAYS = 365;

    /**
     * Find an order by its primary key. Returns null if the id is null,
     * non-numeric, or doesn't match a real row. The integer-coercion
     * rule is the defence against a tampered form posting
     * `order_id=garbage`.
     */
    public function findById(mixed $id): ?Order
    {
        if ($id === null || $id === '') {
            return null;
        }

        $id = is_numeric($id) ? (int) $id : null;
        if ($id === null || $id <= 0) {
            return null;
        }

        return Order::query()->find($id);
    }

    /**
     * Find an order by its number. The number is what's printed on
     * invoices and used as the Leopards "reference" — so when an
     * operator pastes a tracking number, we want to surface the order
     * by number, not by primary key.
     */
    public function findByNumber(string $number): ?Order
    {
        $number = trim($number);
        if ($number === '') {
            return null;
        }

        return Order::query()->where('number', $number)->first();
    }

    /**
     * Suggest orders that match a free-text query. Used by the
     * typeahead. Matches against order number, customer name, customer
     * email and customer phone — the four things an operator is likely
     * to have in front of them.
     *
     * Results are constrained to recent non-terminal orders (the
     * candidates most likely to need a new shipment link), capped at
     * `MAX_RESULTS`, and returned newest-first so the most plausible
     * match is at the top of the dropdown.
     *
     * Each result also carries a `link_status` of "linked" / "unlinked"
     * — whether the order already has any shipment attached. The UI
     * uses this to label results so operators can spot unfulfilled
     * orders quickly. It also carries the order's derived `weight_kg`,
     * `pieces` (total item quantity) and the customer consignee fields
     * (name/phone/email) so the new-shipment form can pre-fill those
     * inputs when an order is picked.
     *
     * @return list<array{id: int, number: string, customer: ?string, city: ?string, total: float, status: ?string, fulfillment: ?string, link_status: string, weight_kg: ?float, pieces: int, consignee_name: ?string, consignee_phone: ?string, consignee_email: ?string, consignee_city: ?string, consignee_address: ?string, cod_amount: ?float}>
     */
    public function suggest(string $query, ?int $excludeId = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $like = '%'.$query.'%';
        $cutoff = now()->subDays(self::LOOKBACK_DAYS);

        $builder = Order::query()
            ->with(['customer', 'items.product'])
            ->where('created_at', '>=', $cutoff)
            ->where(function (Builder $q) use ($like) {
                $q->where('number', 'like', $like)
                    ->orWhereHas('customer', function (Builder $cq) use ($like) {
                        $cq->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like);
                    });
            })
            ->orderByDesc('created_at')
            ->limit(self::MAX_RESULTS);

        if ($excludeId !== null) {
            $builder->where('orders.id', '!=', $excludeId);
        }

        $orders = $builder->get();

        // Bulk-fetch the shipment counts for these orders so we can
        // label each result as "linked" (already has at least one
        // shipment) or "unlinked" (no shipment yet). Doing it in one
        // query beats N+1 lookups.
        $linkedIds = Shipment::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->select('order_id')
            ->distinct()
            ->pluck('order_id')
            ->all();
        $linkedSet = array_flip(array_map('intval', $linkedIds));

        return $orders
            ->map(function (Order $order) use ($linkedSet) {
                $consigneeAddress = implode(', ', array_filter([
                    $order->shipping_address1,
                    $order->shipping_address2,
                ])) ?: null;

                return [
                    'id' => (int) $order->id,
                    'number' => (string) $order->number,
                    'customer' => $order->customer?->name,
                    'city' => $order->shipping_city ?: $order->customer?->country,
                    'total' => (float) $order->total,
                    'status' => $order->status,
                    'fulfillment' => $order->fulfillment_status,
                    'link_status' => isset($linkedSet[(int) $order->id]) ? 'linked' : 'unlinked',
                    'weight_kg' => $order->totalWeightKg(),
                    'pieces' => $order->totalItemQuantity(),
                    'consignee_name' => $order->shipping_name ?: $order->customer?->name,
                    'consignee_phone' => $order->shipping_phone ?: $order->customer?->phone,
                    'consignee_email' => $order->customer?->email,
                    'consignee_city' => $order->shipping_city ?: $order->customer?->country,
                    'consignee_address' => $consigneeAddress,
                    // COD amount = order total when unpaid (cash to collect on delivery).
                    'cod_amount' => strtoupper((string) $order->financial_status) === 'PENDING' && (float) $order->total > 0
                        ? (float) $order->total
                        : null,
                ];
            })
            ->all();
    }

    /**
     * Suggest orders for a NEW shipment, given the partial data the
     * operator has already typed (tracking number, consignee phone).
     *
     * The algorithm is the same conservative match used by the
     * auto-matcher (exact reference = order number, trailing phone
     * digits) but the result list is presented to a human, not stored.
     * Returns at most `MAX_RESULTS` candidates, sorted by recency.
     *
     * Each result also carries a `link_status` of "linked" / "unlinked"
     * so the UI can label whether the order already has a shipment, plus
     * the order's derived `weight_kg`, `pieces` (total item quantity)
     * and the customer consignee fields (name/phone/email) so the
     * new-shipment form can pre-fill those inputs when picked.
     *
     * @return list<array{id: int, number: string, customer: ?string, reason: string, link_status: string, weight_kg: ?float, pieces: int, consignee_name: ?string, consignee_phone: ?string, consignee_email: ?string, consignee_city: ?string, consignee_address: ?string}>
     */
    public function suggestForNewShipment(?string $reference, ?string $consigneePhone): array
    {
        $candidates = [];

        if ($reference !== null && trim($reference) !== '') {
            $order = $this->findByNumber(trim($reference));
            if ($order !== null) {
                $candidates[] = [
                    'id' => (int) $order->id,
                    'number' => (string) $order->number,
                    'customer' => $order->customer?->name,
                    'reason' => 'Tracking reference matches the order number exactly.',
                ];
            }
        }

        if ($consigneePhone !== null && trim($consigneePhone) !== '') {
            $digits = preg_replace('/\D+/', '', $consigneePhone) ?? '';
            if (strlen($digits) >= 7) {
                $tail = substr($digits, -7);
                $matches = Order::query()
                    ->with('customer')
                    ->whereHas('customer', function (Builder $cq) use ($tail) {
                        // MySQL / SQLite / Postgres all accept LIKE with %
                        // wildcards; we just need the *last* 7 digits to match.
                        $cq->whereRaw('SUBSTR(REPLACE(REPLACE(REPLACE(REPLACE(phone, " ", ""), "-", ""), "+", ""), "\t", ""), -7) = ?', [$tail]);
                    })
                    ->orderByDesc('created_at')
                    ->limit(self::MAX_RESULTS - count($candidates))
                    ->get();

                foreach ($matches as $order) {
                    // Don't double-list an order already added via reference.
                    if (collect($candidates)->contains(fn ($c) => $c['id'] === (int) $order->id)) {
                        continue;
                    }
                    $candidates[] = [
                        'id' => (int) $order->id,
                        'number' => (string) $order->number,
                        'customer' => $order->customer?->name,
                        'reason' => 'Consignee phone matches the customer of this order.',
                    ];
                }
            }
        }

        if ($candidates === []) {
            return [];
        }

        // Bulk-fetch the shipment counts for the candidate orders so
        // we can label each as "linked" / "unlinked". Doing it once
        // here is cheaper than N+1 lookups in the UI.
        $linkedIds = Shipment::query()
            ->whereIn('order_id', array_column($candidates, 'id'))
            ->select('order_id')
            ->distinct()
            ->pluck('order_id')
            ->all();
        $linkedSet = array_flip(array_map('intval', $linkedIds));

        foreach ($candidates as $i => $c) {
            $candidates[$i]['link_status'] = isset($linkedSet[(int) $c['id']]) ? 'linked' : 'unlinked';
        }

        return $this->attachOrderStats($candidates);
    }

    /**
     * Attach the derived shipment defaults (`weight_kg` and `pieces`) to a
     * list of candidate orders so the new-shipment form can pre-fill them
     * when one of these orders is picked.
     *
     * Candidates only carry ids/numbers, so the orders (with their line
     * items and products) are loaded once here instead of per candidate.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function attachOrderStats(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $orders = Order::query()
            ->with(['customer', 'items.product'])
            ->whereIn('id', array_map('intval', array_column($candidates, 'id')))
            ->get()
            ->keyBy('id');

        foreach ($candidates as $i => $candidate) {
            $order = $orders->get((int) $candidate['id']);

            $candidates[$i]['weight_kg'] = $order?->totalWeightKg();
            $candidates[$i]['pieces'] = $order !== null ? $order->totalItemQuantity() : 0;
            $candidates[$i]['consignee_name'] = $order?->shipping_name ?: $order?->customer?->name;
            $candidates[$i]['consignee_phone'] = $order?->shipping_phone ?: $order?->customer?->phone;
            $candidates[$i]['consignee_email'] = $order?->customer?->email;
            $candidates[$i]['consignee_city'] = $order?->shipping_city ?: $order?->customer?->country;
            $candidates[$i]['consignee_address'] = $order !== null
                ? (implode(', ', array_filter([
                    $order->shipping_address1,
                    $order->shipping_address2,
                ])) ?: null)
                : null;
            // COD amount = the order total when the order is still unpaid
            // (cash to collect on delivery). Paid/refunded orders have
            // nothing left to collect, so the shipment defaults to no COD.
            $candidates[$i]['cod_amount'] = $order !== null
                && strtoupper((string) $order->financial_status) === 'PENDING'
                && (float) $order->total > 0
                ? (float) $order->total
                : null;
        }

        return $candidates;
    }
}
