<?php

namespace App\Services\Courier\Providers;

use App\Enums\Courier\Capability;
use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider as CourierProviderModel;
use App\Services\Courier\AbstractCourierProvider;
use App\Services\Courier\Exceptions\CourierException;
use App\Services\Courier\Normalization\Address;
use App\Services\Courier\Normalization\RawEvent;
use App\Services\Courier\Normalization\RawShipment;
use App\Services\Courier\WebTracking\StatusTextMapper;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;

/**
 * A single configurable driver for couriers we don't ship a bespoke class
 * for. Everything — the endpoint, the auth, and the JSON-to-shipment field
 * mapping — comes from the courier_providers row, which is edited from the
 * Settings → Couriers page. That means M&P, Trax.pk, or any other REST
 * courier can be added or adjusted without writing code.
 *
 * How it reads a shipment ("track"):
 *   1. Builds a URL from settings[base_url] + settings[track_endpoint].
 *      Either value may contain the {tracking_number} placeholder.
 *   2. Applies the configured auth (settings[auth_type]): header API key,
 *      bearer token, basic auth, or a query-param API key. Secrets always
 *      come from the encrypted credentials column.
 *   3. GETs (or POSTs) the endpoint and maps the JSON body onto a
 *      RawShipment using settings[field_map] (dot-notation paths, e.g.
 *      "data.result.tracking_number"). settings[track_result_path] can point
 *      at the envelope key that holds the actual shipment object.
 *
 * listShipments() is optional — it only produces data when a list_endpoint
 * is configured; otherwise it returns nothing (track-by-CN only), which is
 * exactly how Leopards behaves.
 */
class GenericHttpProvider extends AbstractCourierProvider
{
    /**
     * Sensible defaults for the JSON field map. Keys are the RawShipment
     * fields; values are dot-notation paths resolved against a shipment
     * object. Anything an admin overrides in settings[field_map] wins.
     *
     * @var array<string, string|null>
     */
    private const DEFAULT_FIELD_MAP = [
        'external_id' => null,
        'tracking_number' => 'tracking_number',
        'reference' => 'reference',
        'status' => 'status',
        'status_detail' => 'status_detail',
        'shipped_at' => 'shipped_at',
        'delivered_at' => 'delivered_at',
        'last_event_at' => 'last_event_at',
        'consignor_name' => 'consignor.name',
        'consignor_phone' => 'consignor.phone',
        'consignor_address' => 'consignor.address',
        'consignor_city' => 'consignor.city',
        'consignee_name' => 'consignee.name',
        'consignee_phone' => 'consignee.phone',
        'consignee_address' => 'consignee.address',
        'consignee_city' => 'consignee.city',
        'weight_kg' => 'weight_kg',
        'pieces' => 'pieces',
        'cod_amount' => 'cod_amount',
        'cost' => 'cost',
        'currency' => 'currency',
        'events' => 'events',
        'event_occurred_at' => 'occurred_at',
        'event_status' => 'status',
        'event_location' => 'location',
        'event_description' => 'description',
    ];

    private ?StatusTextMapper $statusMapper = null;

    public function __construct(
        private readonly CourierProviderModel $config,
    ) {}

    public function key(): string
    {
        return (string) $this->config->key;
    }

    public function displayName(): string
    {
        return (string) $this->config->display_name;
    }

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        $values = (array) $this->config->capabilities;

        return array_values(array_filter(
            array_map(fn (mixed $value) => Capability::tryFrom((string) $value), $values),
            fn (?Capability $capability) => $capability !== null,
        ));
    }

    /**
     * Track one shipment by its tracking number / external id.
     */
    public function getShipment(string $externalId): ?RawShipment
    {
        $this->requireCapability(Capability::ReadShipments);

        $body = $this->track($externalId);
        if ($body === null) {
            return null;
        }

        $item = $this->resolveItem($body, 'track_result_path');
        if ($item === null) {
            return null;
        }

        return $this->normalize($item, $body);
    }

    /**
     * Tracking events for a single shipment, extracted from the same track
     * response (no extra HTTP call — matches the Leopards behaviour).
     *
     * @return LazyCollection<int, RawEvent>
     */
    public function listEvents(string $externalId, ?Carbon $since = null): LazyCollection
    {
        $this->requireCapability(Capability::ReadEvents);

        $shipment = $this->getShipment($externalId);
        if ($shipment === null) {
            return LazyCollection::make();
        }

        $item = $this->resolveItem($shipment->raw, 'track_result_path') ?? $shipment->raw;

        $events = $this->extractEvents($item);
        if ($since !== null) {
            $events = array_values(array_filter(
                $events,
                fn (RawEvent $event) => $event->occurredAt === null || $event->occurredAt->gte($since),
            ));
        }

        return LazyCollection::make($events);
    }

    /**
     * Optional batch sync. Only yields shipments when a list_endpoint is
     * configured; otherwise the provider is track-by-CN only and the sync
     * engine just records a successful (empty) poll.
     *
     * @return LazyCollection<int, RawShipment>
     */
    public function listShipments(?Carbon $since = null): LazyCollection
    {
        $this->requireCapability(Capability::ReadShipments);

        $endpoint = (string) $this->setting('list_endpoint');
        if ($endpoint === '') {
            return LazyCollection::make();
        }

        $url = $this->buildUrl((string) $this->setting('base_url'), $endpoint, '');
        $response = $this->send($url, '');

        $body = $response->json();
        if (! is_array($body)) {
            return LazyCollection::make();
        }

        $resultPath = (string) $this->setting('list_result_path');
        $items = $resultPath === '' ? $body : Arr::get($body, $resultPath);
        if (! is_array($items)) {
            return LazyCollection::make();
        }

        $shipments = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $shipment = $this->normalize($item, $body);
            if ($shipment !== null) {
                $shipments[] = $shipment;
            }
        }

        return LazyCollection::make($shipments);
    }

    /**
     * Perform the configured track request. Returns the decoded JSON body,
     * or null when the courier returned an empty/not-found envelope.
     *
     * @return array<string, mixed>|null
     */
    private function track(string $trackingNumber): ?array
    {
        $baseUrl = (string) $this->setting('base_url');
        $endpoint = (string) $this->setting('track_endpoint');

        if ($baseUrl === '' && $endpoint === '') {
            throw new CourierException(sprintf(
                'Courier "%s" has no structured API configured — set base_url and track_endpoint in Settings → Couriers.',
                $this->key(),
            ));
        }

        $url = $this->buildUrl($baseUrl, $endpoint, $trackingNumber);
        $response = $this->send($url, $trackingNumber);

        $body = $response->json();

        return is_array($body) ? $body : null;
    }

    /**
     * Send one request with the provider's auth + headers applied.
     */
    private function send(string $url, string $trackingNumber): Response
    {
        $method = strtoupper((string) $this->setting('method', 'GET'));

        $http = $this->http()->withHeaders($this->extraHeaders($trackingNumber));
        $http = $this->applyAuth($http);
        $url = $this->applyQueryAuth($url);

        $response = $method === 'POST'
            ? $http->post($url, $this->postPayload($trackingNumber))
            : $http->get($url);

        $this->ensureSuccessful($response);

        return $response;
    }

    /**
     * Build the request URL from base_url + endpoint, interpolating the
     * tracking number. A full URL in the endpoint wins over base_url.
     */
    private function buildUrl(string $baseUrl, string $endpoint, string $trackingNumber): string
    {
        if ($endpoint !== '' && str_starts_with($endpoint, 'http')) {
            return $this->interpolate($endpoint, $trackingNumber);
        }

        $base = rtrim($baseUrl, '/');
        $path = $endpoint === '' ? '' : '/'.ltrim($endpoint, '/');

        return $this->interpolate($base.$path, $trackingNumber);
    }

    /**
     * Static headers from settings[headers] (a JSON object). Values may
     * reference credentials via {credential:name}.
     *
     * @return array<string, string>
     */
    private function extraHeaders(string $trackingNumber): array
    {
        $headers = (array) $this->setting('headers', []);
        $result = [];
        foreach ($headers as $name => $value) {
            $result[(string) $name] = $this->interpolate((string) $value, $trackingNumber);
        }

        return $result;
    }

    /**
     * Apply the configured auth scheme to the pending request. Secrets are
     * read from the (encrypted) credentials column, never from settings.
     */
    private function applyAuth(PendingRequest $http): PendingRequest
    {
        return match ((string) $this->setting('auth_type', 'none')) {
            'bearer' => $http->withToken((string) ($this->credential('bearer_token') ?? '')),
            'basic' => $http->withBasicAuth(
                (string) ($this->credential('username') ?? ''),
                (string) ($this->credential('password') ?? ''),
            ),
            'header' => $http->withHeaders([
                (string) $this->setting('auth_header_name', 'X-API-Key') => (string) ($this->credential('api_key') ?? ''),
            ]),
            default => $http,
        };
    }

    /**
     * query auth appends the API key as a URL parameter.
     */
    private function applyQueryAuth(string $url): string
    {
        if ((string) $this->setting('auth_type') !== 'query') {
            return $url;
        }

        $key = (string) ($this->credential('api_key') ?? '');
        if ($key === '') {
            return $url;
        }

        $param = (string) $this->setting('auth_query_param', 'api_key');
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.$param.'='.urlencode($key);
    }

    /**
     * POST body. Defaults to a {tracking_number} payload; an admin can
     * override it with a JSON template in settings[request_body].
     *
     * @return array<string, mixed>
     */
    private function postPayload(string $trackingNumber): array
    {
        $template = (string) $this->setting('request_body');
        if ($template !== '') {
            $decoded = json_decode($this->interpolate($template, $trackingNumber), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return ['tracking_number' => $trackingNumber];
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) $this->setting('timeout_seconds', 20))
            ->acceptJson()
            ->asJson()
            ->retry(2, 250, throw: false);
    }

    /**
     * @param  array<string, mixed>  $root
     * @return array<string, mixed>|null
     */
    private function resolveItem(array $root, string $pathKey): ?array
    {
        $path = (string) $this->setting($pathKey);
        if ($path === '') {
            return $root;
        }

        $value = Arr::get($root, $path);

        return is_array($value) ? $value : null;
    }

    /**
     * Map one shipment object onto a RawShipment. $root is the full response
     * body (kept as raw_payload for debugging / re-normalization).
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $root
     */
    private function normalize(array $item, array $root): ?RawShipment
    {
        $trackingNumber = $this->str('tracking_number', $item);
        $externalId = $this->str('external_id', $item) ?? $trackingNumber;

        if ($trackingNumber === null && $externalId === null) {
            return null;
        }
        $trackingNumber ??= $externalId;
        $externalId ??= $trackingNumber;

        $status = $this->mapStatus($this->str('status', $item));

        return new RawShipment(
            externalId: $externalId,
            trackingNumber: $trackingNumber,
            reference: $this->str('reference', $item),
            status: $status,
            statusDetail: $this->str('status_detail', $item),
            shippedAt: $this->date('shipped_at', $item),
            deliveredAt: $status === ShipmentStatus::Delivered ? $this->date('delivered_at', $item) : null,
            lastEventAt: $this->date('last_event_at', $item) ?? $this->date('shipped_at', $item),
            consignor: $this->address($item, 'consignor'),
            consignee: $this->address($item, 'consignee'),
            weightKg: $this->float('weight_kg', $item),
            pieces: $this->int('pieces', $item),
            codAmount: $this->float('cod_amount', $item),
            cost: $this->float('cost', $item),
            currency: $this->str('currency', $item) ?? config('couriers.default_currency', 'PKR'),
            raw: $root,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<RawEvent>
     */
    private function extractEvents(array $item): array
    {
        $entries = $this->arr('events', $item);
        if ($entries === null || $entries === []) {
            return [];
        }

        $events = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $events[] = new RawEvent(
                occurredAt: $this->date('event_occurred_at', $entry),
                status: $this->mapStatus($this->str('event_status', $entry)),
                location: $this->str('event_location', $entry),
                description: $this->str('event_description', $entry),
                raw: $entry,
            );
        }

        return $events;
    }

    /**
     * Map a courier's free-form status text onto the app's ShipmentStatus.
     * settings[status_map] (keyword → status value) wins first so providers
     * can teach the mapper their own vocabulary; everything else falls back
     * to the shared StatusTextMapper.
     */
    private function mapStatus(?string $text): ShipmentStatus
    {
        if ($text === null || trim($text) === '') {
            return ShipmentStatus::Unknown;
        }

        $needle = strtolower(trim($text));
        $statusMap = (array) $this->setting('status_map', []);
        foreach ($statusMap as $keyword => $value) {
            if (str_contains($needle, strtolower((string) $keyword))) {
                $status = ShipmentStatus::tryFrom((string) $value);
                if ($status !== null) {
                    return $status;
                }
            }
        }

        return $this->statusMapper()->map($text);
    }

    private function statusMapper(): StatusTextMapper
    {
        return $this->statusMapper ??= app(StatusTextMapper::class);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function address(array $item, string $prefix): Address
    {
        $name = $this->scalarText($this->grab($item, $prefix.'_name'));
        $phone = $this->scalarText($this->grab($item, $prefix.'_phone'));
        $address = $this->scalarText($this->grab($item, $prefix.'_address'));
        $city = $this->scalarText($this->grab($item, $prefix.'_city'));

        if ($name === null && $phone === null && $address === null && $city === null) {
            return new Address;
        }

        return new Address(
            name: $name,
            phone: $phone,
            address: $address,
            city: $city,
        );
    }

    /**
     * Resolve a field_map path (or its default) from a shipment object.
     */
    private function field(string $key): ?string
    {
        $map = (array) $this->setting('field_map', []);
        $path = $map[$key] ?? self::DEFAULT_FIELD_MAP[$key] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function grab(array $item, string $fieldKey): mixed
    {
        $path = $this->field($fieldKey);

        return $path === null ? null : Arr::get($item, $path);
    }

    private function str(string $key, array $item): ?string
    {
        return $this->scalarText($this->grab($item, $key));
    }

    private function int(string $key, array $item): ?int
    {
        $value = $this->grab($item, $key);
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function float(string $key, array $item): ?float
    {
        $value = $this->grab($item, $key);
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function arr(string $key, array $item): ?array
    {
        $value = $this->grab($item, $key);

        return is_array($value) ? $value : null;
    }

    private function date(string $key, array $item): ?Carbon
    {
        return $this->parseDate($this->grab($item, $key));
    }

    private function scalarText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_scalar($value)) {
            $text = trim((string) $value);

            return $text === '' ? null : $text;
        }
        if (is_array($value)) {
            foreach (['address', 'line1', 'line2', 'street'] as $subKey) {
                if (isset($value[$subKey]) && is_scalar($value[$subKey]) && trim((string) $value[$subKey]) !== '') {
                    return (string) $value[$subKey];
                }
            }
        }

        return null;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $number = (float) $value;

            return $number > 1_000_000_000_000
                ? Carbon::createFromTimestampMs((int) $number)
                : Carbon::createFromTimestamp((int) $number);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Exception) {
            return null;
        }
    }

    private function interpolate(string $template, string $trackingNumber): string
    {
        $value = str_replace('{tracking_number}', $trackingNumber, $template);

        if (preg_match_all('/\{credential:([a-zA-Z0-9_]+)\}/', $value, $matches)) {
            foreach ($matches[1] as $name) {
                $value = str_replace('{credential:'.$name.'}', (string) ($this->credential($name) ?? ''), $value);
            }
        }

        return $value;
    }

    private function setting(string $name, mixed $default = null): mixed
    {
        $settings = (array) ($this->config->settings ?? []);

        return $settings[$name] ?? $default;
    }

    private function credential(string $name): mixed
    {
        $credentials = (array) ($this->config->credentials ?? []);

        return $credentials[$name] ?? null;
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new CourierException(sprintf(
            '%s API call failed: %d %s',
            $this->displayName(),
            $response->status(),
            substr($response->body(), 0, 300),
        ));
    }
}
