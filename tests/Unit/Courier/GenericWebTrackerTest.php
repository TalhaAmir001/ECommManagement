<?php

namespace Tests\Unit\Courier;

use App\Enums\Courier\ShipmentStatus;
use App\Services\Courier\Exceptions\CourierException;
use App\Services\Courier\WebTracking\GenericWebTracker;
use App\Services\Courier\WebTracking\StatusTextMapper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenericWebTrackerTest extends TestCase
{
    private function make(): GenericWebTracker
    {
        return new GenericWebTracker(new StatusTextMapper);
    }

    public function test_it_extracts_a_status_from_meta_description(): void
    {
        Http::fake([
            'leopardscourier.com/*' => Http::response(
                '<html><head><meta name="description" content="LP123456789 has been delivered to recipient."></head><body></body></html>',
                200,
            ),
        ]);

        $raw = $this->make()->track('https://leopardscourier.com/?cn=LP123456789', 'LP123456789');

        $this->assertSame(ShipmentStatus::Delivered, $raw->status);
        $this->assertSame('LP123456789', $raw->trackingNumber);
    }

    public function test_it_extracts_a_status_from_a_table_row(): void
    {
        $html = <<<'HTML'
            <html><body>
                <h1>Tracking</h1>
                <table>
                    <tr><th>Date</th><th>Status</th><th>Location</th></tr>
                    <tr><td>2026-08-26 10:00</td><td>Delivered</td><td>Karachi</td></tr>
                    <tr><td>2026-08-25 08:00</td><td>In transit</td><td>Hyderabad</td></tr>
                </table>
            </body></html>
            HTML;

        Http::fake([
            'example.com/*' => Http::response($html, 200),
        ]);

        $raw = $this->make()->track('https://example.com/track?id=ABC12345', 'ABC12345');

        $this->assertSame(ShipmentStatus::Delivered, $raw->status);
        $this->assertCount(2, $raw->raw['events'] ?? []);
    }

    public function test_it_returns_the_status_label_as_status_detail(): void
    {
        Http::fake([
            'example.com/*' => Http::response(
                '<html><head><title>Delivered</title></head><body></body></html>',
                200,
            ),
        ]);

        $raw = $this->make()->track('https://example.com/?cn=X12345', 'X12345');

        $this->assertSame(ShipmentStatus::Delivered, $raw->status);
        $this->assertSame('Delivered', $raw->statusDetail);
    }

    public function test_it_throws_when_nothing_useful_can_be_parsed(): void
    {
        Http::fake([
            'example.com/*' => Http::response('<html><body>Loading…</body></html>', 200),
        ]);

        $this->expectException(CourierException::class);
        $this->make()->track('https://example.com/?cn=ABC12345', 'ABC12345');
    }

    public function test_it_throws_on_http_errors(): void
    {
        Http::fake([
            'example.com/*' => Http::response('not found', 404),
        ]);

        $this->expectException(CourierException::class);
        $this->expectExceptionMessage('returned HTTP 404');
        $this->make()->track('https://example.com/?cn=ABC12345', 'ABC12345');
    }

    public function test_list_events_returns_events_when_parsing_succeeds(): void
    {
        $html = <<<'HTML'
            <html><body>
                <table>
                    <tr><th>Date</th><th>Activity</th></tr>
                    <tr><td>2026-08-25 12:00</td><td>In transit</td></tr>
                </table>
            </body></html>
            HTML;

        Http::fake([
            'example.com/*' => Http::response($html, 200),
        ]);

        $events = $this->make()->listEvents('https://example.com/?cn=ABC12345');

        $this->assertCount(1, $events);
        $this->assertSame(ShipmentStatus::InTransit, $events[0]->status);
    }

    public function test_list_events_returns_empty_on_failure(): void
    {
        Http::fake([
            'example.com/*' => Http::response('oops', 500),
        ]);

        $this->assertSame([], $this->make()->listEvents('https://example.com/?cn=ABC12345'));
    }
}
