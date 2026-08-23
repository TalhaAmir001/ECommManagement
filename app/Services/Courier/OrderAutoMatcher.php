<?php

namespace App\Services\Courier;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort link between a courier shipment and a local order.
 *
 * Strategies (in order, configured by config('couriers.auto_match.strategies')):
 *   1. reference    — match shipments.reference to orders.number
 *   2. phone        — match the last 7 digits of consignee_phone to a customer's phone
 *
 * Manual matches are sticky: the matcher will not break them unless
 * config('couriers.auto_match.overwrite_manual') is true.
 */
class OrderAutoMatcher
{
    public function __construct(
        private readonly array $strategies,
        private readonly bool $overwriteManual,
    ) {
    }

    /**
     * Match a shipment to an order in place. Returns the order id (or null
     * when no match was found). Existing manual links are preserved unless
     * overwriteManual is true.
     */
    public function match(Shipment $shipment): ?int
    {
        if ($shipment->order_id !== null && $shipment->matched_method === 'manual' && ! $this->overwriteManual) {
            return $shipment->order_id;
        }

        foreach ($this->strategies as $strategy) {
            $orderId = match ($strategy) {
                'reference' => $this->matchByReference($shipment),
                'phone' => $this->matchByPhone($shipment),
                default => null,
            };
            if ($orderId !== null) {
                $shipment->forceFill([
                    'order_id' => $orderId,
                    'matched_method' => $strategy,
                    'matched_at' => now(),
                ])->save();

                return $orderId;
            }
        }

        if ($shipment->order_id !== null) {
            // We had a previous auto-match but no longer can confirm it. Clear
            // the link so the operator notices — unless it's a manual one,
            // which is preserved above.
            $shipment->forceFill([
                'order_id' => null,
                'matched_method' => null,
                'matched_at' => null,
            ])->save();
        }

        return null;
    }

    private function matchByReference(Shipment $shipment): ?int
    {
        $reference = $shipment->reference;
        if ($reference === null || $reference === '') {
            return null;
        }

        $order = Order::query()->where('number', $reference)->first(['id']);

        return $order?->id;
    }

    private function matchByPhone(Shipment $shipment): ?int
    {
        $phone = $shipment->consignee_phone;
        if ($phone === null || $phone === '') {
            return null;
        }

        // Strip everything except digits, then take the trailing 7 — long
        // enough to be specific, short enough to survive country-code
        // variations and dialing prefixes Pakistani carriers add.
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 7) {
            return null;
        }
        $tail = substr($digits, -7);

        // Find any customer whose stored phone also ends with the same
        // trailing 7 digits. We do the tail comparison in PHP because the
        // exact SQL differs across MySQL / PostgreSQL / SQLite and the
        // candidate set is small.
        $candidates = Order::query()
            ->with('customer')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        foreach ($candidates as $order) {
            $customerPhone = $order->customer?->phone;
            if ($customerPhone === null || $customerPhone === '') {
                continue;
            }
            $customerDigits = preg_replace('/\D+/', '', $customerPhone) ?? '';
            if (strlen($customerDigits) >= 7 && substr($customerDigits, -7) === $tail) {
                return (int) $order->id;
            }
        }

        return null;
    }
}
