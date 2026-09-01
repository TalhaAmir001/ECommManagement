<?php

namespace Tests\Unit\Courier;

use App\Enums\Courier\ShipmentStatus;
use App\Services\Courier\WebTracking\StatusTextMapper;
use PHPUnit\Framework\TestCase;

class StatusTextMapperTest extends TestCase
{
    private StatusTextMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new StatusTextMapper;
    }

    public function test_empty_or_null_inputs_become_unknown(): void
    {
        $this->assertSame(ShipmentStatus::Unknown, $this->mapper->map(null));
        $this->assertSame(ShipmentStatus::Unknown, $this->mapper->map(''));
        $this->assertSame(ShipmentStatus::Unknown, $this->mapper->map('   '));
    }

    /**
     * @dataProvider statusProvider
     */
    public function test_it_maps_known_phrases(string $text, ShipmentStatus $expected): void
    {
        $this->assertSame($expected, $this->mapper->map($text));
    }

    public static function statusProvider(): array
    {
        return [
            'delivered' => ['Package has been delivered', ShipmentStatus::Delivered],
            'delivered_alt' => ['DELIVERED', ShipmentStatus::Delivered],
            'returned' => ['Returned to sender', ShipmentStatus::Returned],
            'cancelled' => ['Order cancelled by customer', ShipmentStatus::Cancelled],
            'exception_hold' => ['Shipment on hold at customs', ShipmentStatus::Exception],
            'exception_attempt' => ['Delivery attempted, no one home', ShipmentStatus::Exception],
            'out_for_delivery' => ['Out for delivery today', ShipmentStatus::OutForDelivery],
            'in_transit' => ['In transit to destination hub', ShipmentStatus::InTransit],
            'shipped' => ['Shipped from warehouse', ShipmentStatus::InTransit],
            'picked_up' => ['Picked up by courier', ShipmentStatus::PickedUp],
            'booked' => ['Booked into system', ShipmentStatus::Created],
        ];
    }

    public function test_it_picks_the_most_specific_match_first(): void
    {
        // "Out for delivery" should beat "in transit" — both substrings are
        // present but the rules are ordered most-specific-first.
        $this->assertSame(
            ShipmentStatus::OutForDelivery,
            $this->mapper->map('Currently out for delivery in Lahore'),
        );
    }

    public function test_unknown_phrases_return_unknown(): void
    {
        $this->assertSame(ShipmentStatus::Unknown, $this->mapper->map('foo bar baz'));
    }
}
