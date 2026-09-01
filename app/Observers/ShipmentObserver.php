<?php

namespace App\Observers;

use App\Enums\Courier\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;

/**
 * Propagates shipment-level events up to the linked order.
 *
 * Two reactions, both idempotent:
 *
 *  1. When a shipment moves to `delivered`, the linked order's
 *     `fulfillment_status` is set to `FULFILLED` (unless the order is
 *     already in a more advanced state).
 *
 *  2. When ALL of an order's shipments reach a terminal state
 *     (`delivered`, `returned`, `cancelled`), the order's `status` is
 *     synced to match — `delivered`, `cancelled`, etc. — so the Orders
 *     page status reflects reality, not stale Shopify data.
 *
 * Why an observer and not inline controller code: the propagation has
 * to fire from EVERY place a shipment status changes — controller
 * `store()`, `addEvent()`, `refresh()`, the `RefreshShipmentFromLinkJob`,
 * the `SyncCourierProviderJob`, the `TrackingLinkResolver`. Centralising
 * it here means there's one place to audit and one place to disable.
 */
class ShipmentObserver
{
    /**
     * Called after a shipment is saved. The shipment's status may have
     * changed (new row, updated row, or an event-driven mutation). We
     * only act when there's actually a linked order.
     */
    public function saved(Shipment $shipment): void
    {
        $order = $shipment->order;
        if ($order === null) {
            return;
        }

        $this->propagateDelivery($order, $shipment);
        $this->propagateAllTerminal($order);
    }

    /**
     * When a single shipment on an order is delivered, mark the order
     * fulfilled. Idempotent: an already-fulfilled order is left alone.
     */
    private function propagateDelivery(Order $order, Shipment $shipment): void
    {
        if ($shipment->status !== ShipmentStatus::Delivered) {
            return;
        }

        if ($order->fulfillment_status === 'FULFILLED') {
            return;
        }

        $order->forceFill(['fulfillment_status' => 'FULFILLED'])->save();
    }

    /**
     * When every shipment linked to the order is in a terminal state,
     * the order's local `status` is reconciled with the courier data.
     *
     * Terminal = `delivered`, `returned`, or `cancelled`. We never
     * *downgrade* a status the operator may have set by hand — only
     * fill in something the courier has made definitive.
     */
    private function propagateAllTerminal(Order $order): void
    {
        $shipments = $order->shipments()->get(['id', 'status']);
        if ($shipments->isEmpty()) {
            return;
        }

        $nonTerminal = $shipments->filter(function (Shipment $s) {
            return $s->status === null || ! $s->status->isTerminal();
        });

        if ($nonTerminal->isNotEmpty()) {
            return;
        }

        $terminalStatuses = $shipments->pluck('status')->all();

        // All delivered → delivered. Any returned → returned. Otherwise
        // the order is cancelled (or a mix of cancelled + delivered —
        // we report the dominant "not delivered" terminal state).
        $newStatus = match (true) {
            collect($terminalStatuses)->every(fn ($s) => $s === ShipmentStatus::Delivered) => 'delivered',
            collect($terminalStatuses)->contains(fn ($s) => $s === ShipmentStatus::Returned) => 'returned',
            collect($terminalStatuses)->contains(fn ($s) => $s === ShipmentStatus::Cancelled) => 'cancelled',
            default => null,
        };

        if ($newStatus === null) {
            return;
        }

        if ($order->status === $newStatus) {
            return;
        }

        Log::info('ShipmentObserver: order status propagated', [
            'order' => $order->number,
            'from' => $order->status,
            'to' => $newStatus,
        ]);

        $order->forceFill(['status' => $newStatus])->save();
    }
}
