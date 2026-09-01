<?php

namespace Tests\Unit\Courier;

use App\Services\Courier\WebTracking\TrackingUrlProbe;
use PHPUnit\Framework\TestCase;

class TrackingUrlProbeTest extends TestCase
{
    private function probe(array $hosts = []): TrackingUrlProbe
    {
        return new TrackingUrlProbe($hosts ?: [
            'leopardscourier.com' => 'leopards',
            'tcs.com.pk' => 'tcs',
            'dhl.com' => 'manual',
        ]);
    }

    public function test_empty_url_returns_nulls(): void
    {
        $result = $this->probe()->probe('');

        $this->assertNull($result['courier']);
        $this->assertNull($result['tracking_number']);
        $this->assertNull($result['host']);
    }

    public function test_it_identifies_a_known_carrier_from_the_hostname(): void
    {
        $result = $this->probe()->probe('https://www.leopardscourier.com/?cn=LP123456789');

        $this->assertSame('leopards', $result['courier']);
        $this->assertSame('LP123456789', $result['tracking_number']);
        $this->assertSame('www.leopardscourier.com', $result['host']);
    }

    public function test_it_recognizes_subdomains(): void
    {
        $result = $this->probe()->probe('https://track.tcs.com.pk/?cn=TCS9999');

        $this->assertSame('tcs', $result['courier']);
        $this->assertSame('TCS9999', $result['tracking_number']);
    }

    public function test_unknown_hosts_return_a_null_courier(): void
    {
        $result = $this->probe()->probe('https://example.com/?cn=ABC123');

        $this->assertNull($result['courier']);
        $this->assertSame('ABC123', $result['tracking_number']);
    }

    public function test_it_does_not_match_unrelated_hosts_with_a_shared_suffix(): void
    {
        // A naive "endsWith" would match "notleopardscourier.com" too.
        $result = $this->probe()->probe('https://notleopardscourier.com/?cn=LP1');

        $this->assertNull($result['courier']);
    }

    public function test_it_extracts_tracking_number_from_common_url_forms(): void
    {
        $probe = $this->probe();

        $this->assertSame('A1B2C3', $probe->probe('https://www.dhl.com/track?id=A1B2C3')['tracking_number']);
        $this->assertSame('AWB-77', $probe->probe('https://example.com/track/AWB-77')['tracking_number']);
        $this->assertSame('TRK_99', $probe->probe('https://example.com/path/TRK_99')['tracking_number']);
    }

    public function test_it_ignores_tracking_number_candidates_that_are_too_short(): void
    {
        $result = $this->probe()->probe('https://example.com/?cn=AB');

        $this->assertNull($result['tracking_number']);
    }
}
