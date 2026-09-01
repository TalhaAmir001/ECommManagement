<?php

namespace Tests\Unit\Courier;

use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider;
use App\Services\Courier\Exceptions\CourierException;
use App\Services\Courier\Providers\GenericHttpProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenericHttpProviderTest extends TestCase
{
    private function provider(array $settings = [], array $credentials = [], array $capabilities = ['read_shipments', 'read_events']): GenericHttpProvider
    {
        $row = new CourierProvider([
            'key' => 'custom',
            'display_name' => 'Custom Courier',
            'driver_class' => GenericHttpProvider::class,
            'enabled' => true,
            'capabilities' => $capabilities,
            'credentials' => $credentials,
            'settings' => array_merge([
                'base_url' => 'https://api.example.com',
                'track_endpoint' => '/api/track?cn={tracking_number}',
                'method' => 'GET',
                'auth_type' => 'none',
            ], $settings),
        ]);

        return new GenericHttpProvider($row);
    }

    private function fakeTrackResponse(array $body): void
    {
        Http::fake([
            'api.example.com/*' => Http::response($body, 200),
        ]);
    }

    public function test_it_normalizes_a_track_response(): void
    {
        $this->fakeTrackResponse([
            'tracking_number' => 'TRK-123',
            'reference' => 'ORD-9',
            'status' => 'Delivered',
            'status_detail' => 'Delivered to consignee',
            'shipped_at' => '2026-08-24 10:00:00',
            'delivered_at' => '2026-08-26 14:30:00',
            'consignee' => ['name' => 'Ali Khan', 'phone' => '03001234567', 'city' => 'Karachi'],
            'cod_amount' => 500,
            'cost' => 220.5,
            'pieces' => 2,
        ]);

        $shipment = $this->provider()->getShipment('TRK-123');

        $this->assertNotNull($shipment);
        $this->assertSame('TRK-123', $shipment->externalId);
        $this->assertSame('ORD-9', $shipment->reference);
        $this->assertSame(ShipmentStatus::Delivered, $shipment->status);
        $this->assertSame('Ali Khan', $shipment->consignee?->name);
        $this->assertSame('Karachi', $shipment->consignee?->city);
        $this->assertSame(500.0, $shipment->codAmount);
        $this->assertSame(220.5, $shipment->cost);
        $this->assertSame(2, $shipment->pieces);
        $this->assertNotNull($shipment->deliveredAt);
        $this->assertSame('PKR', $shipment->currency);
        $this->assertIsArray($shipment->raw);
    }

    public function test_it_extracts_events_and_filters_by_since(): void
    {
        $this->fakeTrackResponse([
            'tracking_number' => 'TRK-EVT',
            'status' => 'Delivered',
            'events' => [
                ['occurred_at' => '2026-08-24 10:00:00', 'status' => 'Booked', 'location' => 'Lahore'],
                ['occurred_at' => '2026-08-25 08:00:00', 'status' => 'In transit', 'location' => 'Hyderabad'],
                ['occurred_at' => '2026-08-26 14:00:00', 'status' => 'Delivered', 'location' => 'Karachi'],
            ],
        ]);

        $provider = $this->provider();
        $events = $provider->listEvents('TRK-EVT')->all();

        $this->assertCount(3, $events);
        $this->assertSame(ShipmentStatus::Created, $events[0]->status);
        $this->assertSame('Lahore', $events[0]->location);
        $this->assertSame(ShipmentStatus::Delivered, $events[2]->status);

        $since = Carbon::parse('2026-08-26 00:00:00');
        $filtered = $provider->listEvents('TRK-EVT', $since)->all();
        $this->assertCount(1, $filtered);
    }

    public function test_it_applies_header_api_key_auth(): void
    {
        $this->fakeTrackResponse(['tracking_number' => 'T1', 'status' => 'In transit']);

        $this->provider(['auth_type' => 'header', 'auth_header_name' => 'X-API-Key'], ['api_key' => 'secret-key'])
            ->getShipment('T1');

        Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'secret-key'));
    }

    public function test_it_applies_bearer_token_auth(): void
    {
        $this->fakeTrackResponse(['tracking_number' => 'T2', 'status' => 'In transit']);

        $this->provider(['auth_type' => 'bearer'], ['bearer_token' => 'tok-123'])->getShipment('T2');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer tok-123'));
    }

    public function test_it_applies_basic_auth(): void
    {
        $this->fakeTrackResponse(['tracking_number' => 'T3', 'status' => 'In transit']);

        $this->provider(['auth_type' => 'basic'], ['username' => 'merchant', 'password' => 'p@ss'])
            ->getShipment('T3');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Basic '.base64_encode('merchant:p@ss'));
        });
    }

    public function test_it_appends_api_key_as_query_param(): void
    {
        $this->fakeTrackResponse(['tracking_number' => 'T4', 'status' => 'In transit']);

        $this->provider(['auth_type' => 'query', 'auth_query_param' => 'key'], ['api_key' => 'q-secret'])
            ->getShipment('T4');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'key=q-secret'));
    }

    public function test_it_posts_a_json_body_when_method_is_post(): void
    {
        $this->fakeTrackResponse(['tracking_number' => 'T5', 'status' => 'In transit']);

        $this->provider(['method' => 'POST'])->getShipment('T5');

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && ($request->data()['tracking_number'] ?? null) === 'T5';
        });
    }

    public function test_it_interpolates_credential_placeholders_in_headers(): void
    {
        $this->fakeTrackResponse(['tracking_number' => 'T6', 'status' => 'In transit']);

        $this->provider(['headers' => ['X-Tenant' => 'acme', 'X-Auth' => '{credential:api_key}']], ['api_key' => 'k-42'])
            ->getShipment('T6');

        Http::assertSent(fn ($request) => $request->hasHeader('X-Tenant', 'acme') && $request->hasHeader('X-Auth', 'k-42'));
    }

    public function test_it_uses_track_result_path_and_field_map_overrides(): void
    {
        $this->fakeTrackResponse([
            'data' => ['result' => ['cn' => 'CN-OVR', 'status_text' => 'Out for delivery']],
        ]);

        $provider = $this->provider([
            'track_result_path' => 'data.result',
            'field_map' => ['tracking_number' => 'cn', 'status' => 'status_text'],
        ]);

        $shipment = $provider->getShipment('CN-OVR');

        $this->assertNotNull($shipment);
        $this->assertSame('CN-OVR', $shipment->trackingNumber);
        $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->status);
    }

    public function test_it_maps_status_keywords_from_status_map(): void
    {
        $this->fakeTrackResponse(['tracking_number' => 'S1', 'status' => 'RTS']);

        $shipment = $this->provider(['status_map' => ['rts' => 'returned']])->getShipment('S1');

        $this->assertSame(ShipmentStatus::Returned, $shipment->status);
    }

    public function test_it_lists_shipments_via_list_endpoint(): void
    {
        Http::fake([
            'api.example.com/api/shipments' => Http::response([
                'data' => ['shipments' => [
                    ['tracking_number' => 'L1', 'status' => 'In transit', 'consignee' => ['city' => 'Lahore']],
                    ['tracking_number' => 'L2', 'status' => 'Delivered'],
                ]],
            ], 200),
        ]);

        $provider = $this->provider(['list_endpoint' => '/api/shipments', 'list_result_path' => 'data.shipments']);
        $listed = $provider->listShipments()->all();

        $this->assertCount(2, $listed);
        $this->assertSame('L1', $listed[0]->trackingNumber);
        $this->assertSame('Lahore', $listed[0]->consignee?->city);
        $this->assertSame(ShipmentStatus::Delivered, $listed[1]->status);
    }

    public function test_list_shipments_is_empty_without_list_endpoint(): void
    {
        $this->assertSame(0, $this->provider()->listShipments()->count());
    }

    public function test_it_throws_when_no_endpoint_is_configured(): void
    {
        $provider = $this->provider(['base_url' => '', 'track_endpoint' => '']);

        $this->expectException(CourierException::class);
        $provider->getShipment('X');
    }

    public function test_it_returns_null_when_envelope_has_no_shipment(): void
    {
        $this->fakeTrackResponse(['data' => null]);

        $this->assertNull($this->provider()->getShipment('MISSING'));
    }

    public function test_it_throws_on_http_error(): void
    {
        Http::fake([
            'api.example.com/*' => Http::response('unauthorized', 401),
        ]);

        $this->expectException(CourierException::class);
        $this->provider()->getShipment('T-401');
    }
}
