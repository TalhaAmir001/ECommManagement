<?php

namespace App\Services\Courier\WebTracking;

use App\Enums\Courier\ShipmentStatus;

/**
 * Maps a free-form status string (e.g. as scraped from a courier's tracking
 * page) onto the app's ShipmentStatus enum. The mapping is keyword-driven
 * because carriers don't agree on a vocabulary — "DELIVERED" in caps,
 * "Delivered to consignee", "Out for Delivery", "In Transit", and "Shipment
 * in progress" all need to land on the right enum value.
 *
 * The order of the rules matters: more specific phrases are checked first
 * so that "Out for delivery" doesn't accidentally fall into the generic
 * "In transit" bucket.
 */
final class StatusTextMapper
{
    /**
     * @return list<array{needle: string, status: ShipmentStatus}>
     */
    private function rules(): array
    {
        return [
            // Terminal — positive
            ['needle' => 'delivered', 'status' => ShipmentStatus::Delivered],
            ['needle' => 'returned to', 'status' => ShipmentStatus::Returned],
            ['needle' => 'returned', 'status' => ShipmentStatus::Returned],
            ['needle' => 'cancelled', 'status' => ShipmentStatus::Cancelled],
            ['needle' => 'canceled', 'status' => ShipmentStatus::Cancelled],
            ['needle' => 'voided', 'status' => ShipmentStatus::Cancelled],

            // Terminal — negative
            ['needle' => 'lost', 'status' => ShipmentStatus::Exception],
            ['needle' => 'damaged', 'status' => ShipmentStatus::Exception],
            ['needle' => 'on hold', 'status' => ShipmentStatus::Exception],
            ['needle' => 'held', 'status' => ShipmentStatus::Exception],
            ['needle' => 'exception', 'status' => ShipmentStatus::Exception],
            ['needle' => 'failed delivery', 'status' => ShipmentStatus::Exception],
            ['needle' => 'delivery attempt', 'status' => ShipmentStatus::Exception],
            ['needle' => 'attempted', 'status' => ShipmentStatus::Exception],
            ['needle' => 'refused', 'status' => ShipmentStatus::Exception],
            ['needle' => 'undelivered', 'status' => ShipmentStatus::Exception],

            // Active — close to delivery
            ['needle' => 'out for delivery', 'status' => ShipmentStatus::OutForDelivery],
            ['needle' => 'with delivery courier', 'status' => ShipmentStatus::OutForDelivery],
            ['needle' => 'ready for pickup', 'status' => ShipmentStatus::OutForDelivery],
            ['needle' => 'available for pickup', 'status' => ShipmentStatus::OutForDelivery],

            // Active — moving
            ['needle' => 'in transit', 'status' => ShipmentStatus::InTransit],
            ['needle' => 'in-transit', 'status' => ShipmentStatus::InTransit],
            ['needle' => 'intransit', 'status' => ShipmentStatus::InTransit],
            ['needle' => 'on the way', 'status' => ShipmentStatus::InTransit],
            ['needle' => 'on its way', 'status' => ShipmentStatus::InTransit],
            ['needle' => 'shipped', 'status' => ShipmentStatus::InTransit],
            ['needle' => 'dispatched', 'status' => ShipmentStatus::InTransit],
            ['needle' => 'departed', 'status' => ShipmentStatus::InTransit],
            ['needle' => 'arrived at', 'status' => ShipmentStatus::InTransit],
            ['needle' => 'reached', 'status' => ShipmentStatus::InTransit],
            ['needle' => 'processed at', 'status' => ShipmentStatus::InTransit],

            // Active — early
            ['needle' => 'picked up', 'status' => ShipmentStatus::PickedUp],
            ['needle' => 'pickedup', 'status' => ShipmentStatus::PickedUp],
            ['needle' => 'pickup complete', 'status' => ShipmentStatus::PickedUp],

            // Booked but not yet moving
            ['needle' => 'booked', 'status' => ShipmentStatus::Created],
            ['needle' => 'label created', 'status' => ShipmentStatus::Created],
            ['needle' => 'manifest created', 'status' => ShipmentStatus::Created],
            ['needle' => 'order received', 'status' => ShipmentStatus::Created],
            ['needle' => 'pending', 'status' => ShipmentStatus::Created],
        ];
    }

    public function map(?string $text): ShipmentStatus
    {
        if ($text === null || trim($text) === '') {
            return ShipmentStatus::Unknown;
        }

        $needle = strtolower(trim($text));

        foreach ($this->rules() as $rule) {
            if (str_contains($needle, $rule['needle'])) {
                return $rule['status'];
            }
        }

        return ShipmentStatus::Unknown;
    }
}
