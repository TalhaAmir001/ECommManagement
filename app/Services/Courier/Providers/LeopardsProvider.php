<?php

namespace App\Services\Courier\Providers;

use App\Enums\Courier\Capability;
use App\Models\CourierProvider as CourierProviderModel;
use App\Services\Courier\AbstractCourierProvider;
use App\Services\Courier\Exceptions\CourierException;
use App\Services\Courier\Normalization\Address;
use App\Services\Courier\Normalization\RawEvent;
use App\Services\Courier\Normalization\RawShipment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;

/**
 * Leopards Courier (Pakistan) integration. Auth is a static API key passed
 * in the request body. The shape of the read endpoints below matches the
 * public Leopards API as of 2025; if their schema changes the normalizer is
 * the only place that needs to follow.
 *
 * Phase 1 supports the read endpoints (track + shipment list). Phase 2 will
 * implement createLabel / cancelShipment against the same base URL.
 */
class LeopardsProvider extends AbstractCourierProvider
{
    public function __construct(
        private readonly CourierProviderModel $config,
    ) {
    }

    public function key(): string
    {
        return 'leopards';
    }

    public function displayName(): string
    {
        return 'Leopards Courier';
    }

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return [
            Capability::ReadShipments,
            Capability::ReadEvents,
            // Capability::CreateLabel,            // phase 2
            // Capability::CancelShipment,         // phase 2
            // Capability::SchedulePickup,         // phase 2
            // Capability::RateQuote,              // phase 2
            Capability::CodSupport,
        ];
    }

    public function listShipments(?Carbon $since = null): LazyCollection
    {
        // Leopards doesn't expose a true "list updated since" endpoint; the
        // practical pattern is to keep the set of tracking numbers we already
        // have in the DB and call getShipment / track on each. The sync
        // engine handles dedupe.
        return LazyCollection::make();
    }

    public function getShipment(string $externalId): ?RawShipment
    {
        $payload = $this->callTrack($externalId);
        if ($payload === null) {
            return null;
        }

        return $this->normalizeTrackResponse($payload);
    }

    public function listEvents(string $externalId, ?Carbon $since = null): LazyCollection
    {
        $shipment = $this->getShipment($externalId);
        if ($shipment === null) {
            return LazyCollection::make();
        }

        $events = $this->extractEvents($shipment->raw);
        if ($since !== null) {
            $events = array_values(array_filter($events, fn (RawEvent $e) => $e->occurredAt === null || $e->occurredAt->gte($since)));
        }

        return LazyCollection::make($events);
    }

    /**
     * POST /api/track_booked_packet_api/. The body asks for a single tracking
     * number and returns either a list of packets or a "not found" envelope.
     *
     * @return array<string, mixed>|null
     */
    private function callTrack(string $trackingNumber): ?array
    {
        $response = $this->http()->post($this->baseUrl().'/api/track_booked_packet_api/', [
            'api_key' => $this->apiKey(),
            'tracking_number' => $trackingNumber,
        ]);

        $this->ensureSuccessful($response);

        $body = $response->json();
        if (! is_array($body)) {
            return null;
        }

        // The track endpoint nests the data; if it's empty, treat as not-found.
        $packetList = $body['track_packet_list'] ?? null;
        if ($packetList === null || $packetList === '') {
            return null;
        }

        return is_array($packetList) ? $packetList : [$packetList];
    }

    /**
     * @param  array<int, array<string, mixed>>  $packets
     */
    private function normalizeTrackResponse(array $packets): RawShipment
    {
        // Use the first packet — a single tracking number should map to one.
        $packet = $packets[0] ?? [];
        $trackingNumber = (string) ($packet['track_number'] ?? $packet['tracking_number'] ?? '');

        $status = $this->mapStatus($packet);
        $lastEventAt = $this->parseDate($packet['booking_date'] ?? null)
            ?? $this->parseDate($packet['packet_date'] ?? null);

        $consignee = new Address(
            name: $packet['consignee_name'] ?? null,
            phone: $packet['consignee_phone'] ?? null,
            address: $packet['consignee_address'] ?? null,
            city: $packet['consignee_city'] ?? $packet['destination_city'] ?? null,
        );

        return new RawShipment(
            externalId: $trackingNumber,
            trackingNumber: $trackingNumber,
            reference: $packet['order_id'] ?? $packet['reference_no'] ?? null,
            status: $status,
            statusDetail: $packet['current_status'] ?? $packet['status'] ?? null,
            shippedAt: $this->parseDate($packet['booking_date'] ?? null),
            deliveredAt: $status === \App\Enums\Courier\ShipmentStatus::Delivered
                ? $this->parseDate($packet['delivery_date'] ?? null)
                : null,
            lastEventAt: $lastEventAt,
            consignor: null,
            consignee: $consignee,
            weightKg: isset($packet['weight']) ? (float) $packet['weight'] : null,
            pieces: isset($packet['packet_count']) ? (int) $packet['packet_count'] : 1,
            codAmount: isset($packet['cod_amount']) ? (float) $packet['cod_amount'] : null,
            cost: isset($packet['charged_amount']) ? (float) $packet['charged_amount'] : null,
            currency: 'PKR',
            raw: $packet,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<RawEvent>
     */
    private function extractEvents(array $raw): array
    {
        // Leopards returns the activity log as an array under packet_history
        // or activity; if neither is present we synthesize one event from the
        // shipment's current status so the timeline is never empty.
        $history = $raw['packet_history'] ?? $raw['activity'] ?? null;
        if (! is_array($history) || $history === []) {
            $synth = $this->parseDate($raw['booking_date'] ?? null);
            if ($synth === null) {
                return [];
            }

            return [new RawEvent(
                occurredAt: $synth,
                status: $this->mapStatus($raw),
                location: $raw['origin_city'] ?? null,
                description: $raw['current_status'] ?? null,
                raw: $raw,
            )];
        }

        $events = [];
        foreach ($history as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $events[] = new RawEvent(
                occurredAt: $this->parseDate($entry['date'] ?? $entry['activity_date'] ?? null),
                status: $this->mapStatus($entry),
                location: $entry['city'] ?? $entry['location'] ?? null,
                description: $entry['status'] ?? $entry['activity'] ?? null,
                raw: $entry,
            );
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function mapStatus(array $raw): \App\Enums\Courier\ShipmentStatus
    {
        $needle = strtolower((string) ($raw['current_status'] ?? $raw['status'] ?? $raw['packet_status'] ?? ''));

        return match (true) {
            str_contains($needle, 'deliver') => \App\Enums\Courier\ShipmentStatus::Delivered,
            str_contains($needle, 'return') => \App\Enums\Courier\ShipmentStatus::Returned,
            str_contains($needle, 'cancel') || str_contains($needle, 'void') => \App\Enums\Courier\ShipmentStatus::Cancelled,
            str_contains($needle, 'out for delivery') => \App\Enums\Courier\ShipmentStatus::OutForDelivery,
            str_contains($needle, 'exception') || str_contains($needle, 'hold') || str_contains($needle, 'failed') => \App\Enums\Courier\ShipmentStatus::Exception,
            str_contains($needle, 'in transit') || str_contains($needle, 'dispatch') || str_contains($needle, 'transit') => \App\Enums\Courier\ShipmentStatus::InTransit,
            str_contains($needle, 'picked') || str_contains($needle, 'pickup') => \App\Enums\Courier\ShipmentStatus::PickedUp,
            str_contains($needle, 'book') => \App\Enums\Courier\ShipmentStatus::Created,
            default => \App\Enums\Courier\ShipmentStatus::Unknown,
        };
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Exception) {
            return null;
        }
    }

    private function http(): PendingRequest
    {
        $timeout = (int) ($this->config->settings['timeout_seconds'] ?? 20);

        return Http::timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->retry(2, 250, throw: false);
    }

    private function baseUrl(): string
    {
        return (string) ($this->config->settings['base_url'] ?? 'https://merchantapi.leopardscourier.com');
    }

    private function apiKey(): string
    {
        $creds = $this->config->credentials ?? [];
        $key = $creds['api_key'] ?? null;
        if (! is_string($key) || $key === '') {
            throw new CourierException('LeopardsProvider is missing the "api_key" credential.');
        }

        return $key;
    }

    /**
     * @param  \Illuminate\Http\Client\Response  $response
     */
    private function ensureSuccessful($response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new CourierException(sprintf(
            'Leopards API call failed: %d %s',
            $response->status(),
            substr($response->body(), 0, 300),
        ));
    }
}
