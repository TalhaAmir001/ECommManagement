<?php

namespace App\Enums\Courier;

/**
 * What a courier provider can do. The string value is what we store in
 * courier_providers.capabilities, what we ship through queues, and what
 * the UI checks to decide whether to show a button.
 *
 * Phase 1 (read-only) only needs READ_SHIPMENTS, READ_EVENTS, and COD_SUPPORT
 * in the database. The write capabilities are declared now so phase 2 only
 * has to flip flags, not touch the schema or the interface.
 */
enum Capability: string
{
    case ReadShipments = 'read_shipments';
    case ReadEvents = 'read_events';
    case Webhooks = 'webhooks';
    case CreateLabel = 'create_label';
    case CancelShipment = 'cancel_shipment';
    case SchedulePickup = 'schedule_pickup';
    case RateQuote = 'rate_quote';
    case CodSupport = 'cod_support';

    /**
     * Human-readable label for the admin UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::ReadShipments => 'Read shipments',
            self::ReadEvents => 'Read tracking events',
            self::Webhooks => 'Receive webhooks',
            self::CreateLabel => 'Create shipping label',
            self::CancelShipment => 'Cancel shipment',
            self::SchedulePickup => 'Schedule pickup',
            self::RateQuote => 'Get rate quotes',
            self::CodSupport => 'Cash on delivery',
        };
    }
}
