<?php

namespace App\Services\Courier\WebTracking;

use App\Enums\Courier\ShipmentStatus;
use App\Services\Courier\Exceptions\CourierException;
use App\Services\Courier\Normalization\Address;
use App\Services\Courier\Normalization\RawEvent;
use App\Services\Courier\Normalization\RawShipment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Generic tracker for couriers we don't have a structured API for.
 *
 * Given a public tracking URL, this:
 *  1. GETs the page (HTML, occasionally JSON wrapped in <pre> or a
 *     <script> tag — some carriers ship their tracking state inline).
 *  2. Tries to find a current status by looking at a handful of common
 *     patterns: meta description, page title, well-known CSS selectors,
 *     and any "Status:" label followed by a value.
 *  3. Tries to find an event list by scanning HTML tables for rows that
 *     look like (date, location, activity) triples.
 *
 * The output is a best-effort RawShipment with a single synthesized
 * "current status" event. When we can't find anything useful, we throw
 * CourierException so the caller can decide to skip rather than record
 * a misleading Unknown.
 *
 * This tracker is intentionally conservative — it's better to surface
 * "we couldn't read the page" than to invent a status.
 */
final class GenericWebTracker
{
    public function __construct(
        private readonly StatusTextMapper $statusMapper,
        private readonly int $timeoutSeconds = 20,
    ) {}

    /**
     * @throws CourierException
     */
    public function track(string $trackingUrl, ?string $trackingNumber = null): RawShipment
    {
        $html = $this->fetch($trackingUrl);

        $statusText = $this->extractStatusText($html);
        $status = $this->statusMapper->map($statusText);

        // Events come back in the order they appeared on the page (newest
        // first) — that's how courier sites render activity logs.
        $events = $this->extractEvents($html);

        // If neither the page nor any of its events gave us a status we
        // recognize, the page is probably JS-rendered or behind a captcha
        // and we should bail rather than store a misleading "Unknown".
        if ($status === ShipmentStatus::Unknown && $events === []) {
            throw new CourierException(
                "GenericWebTracker could not parse a status from {$trackingUrl}. "
                .'The courier page may be JavaScript-rendered or behind a captcha.'
            );
        }

        // Prefer the status from the newest event when we don't have a
        // confident top-level one — the latest event is the current state.
        if ($status === ShipmentStatus::Unknown && $events !== []) {
            $status = $events[0]->status;
            $statusText = $events[0]->description;
        }

        $lastEventAt = null;
        foreach ($events as $event) {
            if ($event->occurredAt !== null && ($lastEventAt === null || $event->occurredAt->gt($lastEventAt))) {
                $lastEventAt = $event->occurredAt;
            }
        }
        $lastEventAt ??= now();

        $shippedAt = null;
        // Scan from oldest to newest so the "first time it moved" sticks.
        foreach (array_reverse($events) as $event) {
            if ($event->status === ShipmentStatus::PickedUp || $event->status === ShipmentStatus::InTransit) {
                $shippedAt = $event->occurredAt;
                break;
            }
        }

        $deliveredAt = null;
        if ($status === ShipmentStatus::Delivered) {
            // Newest event is the delivery record.
            $deliveredAt = $events[0]->occurredAt ?? now();
        }

        return new RawShipment(
            externalId: $trackingNumber ?? $trackingUrl,
            trackingNumber: $trackingNumber ?? '',
            status: $status,
            statusDetail: $statusText,
            shippedAt: $shippedAt,
            deliveredAt: $deliveredAt,
            lastEventAt: $lastEventAt,
            consignor: null,
            consignee: new Address,
            currency: 'PKR',
            raw: [
                'url' => $trackingUrl,
                'status_text' => $statusText,
                'events' => array_map(static fn (RawEvent $e) => [
                    'occurred_at' => $e->occurredAt?->toIso8601String(),
                    'status' => $e->status->value,
                    'location' => $e->location,
                    'description' => $e->description,
                ], $events),
            ],
        );
    }

    /**
     * @return list<RawEvent>
     */
    public function listEvents(string $trackingUrl): array
    {
        try {
            $html = $this->fetch($trackingUrl);
        } catch (CourierException) {
            return [];
        }

        return $this->extractEvents($html);
    }

    private function fetch(string $url): string
    {
        try {
            $response = $this->http()->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])->get($url);
        } catch (Throwable $e) {
            throw new CourierException("GenericWebTracker: HTTP request to {$url} failed: ".$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new CourierException(
                "GenericWebTracker: {$url} returned HTTP {$response->status()}."
            );
        }

        $body = $response->body();
        if (! is_string($body) || $body === '') {
            throw new CourierException("GenericWebTracker: {$url} returned an empty body.");
        }

        return $body;
    }

    private function http(): PendingRequest
    {
        return Http::timeout($this->timeoutSeconds)
            ->accept('text/html')
            ->retry(2, 250, throw: false)
            ->withUserAgent('Mozilla/5.0 (compatible; ECommManagement/1.0; +https://example.com)');
    }

    private function extractStatusText(string $html): ?string
    {
        // Strip scripts/styles so we don't accidentally match UI text in JS.
        $clean = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);

        // 1. <meta name="description" content="..."> is the cheapest signal
        //    because most courier sites put the status there for SEO.
        if (preg_match('/<meta\s+[^>]*name=["\']description["\'][^>]*content=["\']([^"\']+)["\']/i', $clean, $m) === 1) {
            $text = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($this->statusMapper->map($text) !== ShipmentStatus::Unknown) {
                return $text;
            }
        }

        // 2. <title> often summarizes the page ("LP123456789 – Delivered").
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $clean, $m) === 1) {
            $text = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($this->statusMapper->map($text) !== ShipmentStatus::Unknown) {
                return $text;
            }
        }

        // 3. Label-style: "Status: <value>" or "Current Status: <value>".
        if (preg_match('/(?:current\s+)?status\s*[:\-]\s*([A-Za-z][A-Za-z 0-9_\-\/]{2,60})/i', $clean, $m) === 1) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // 4. Well-known CSS classes used by some common tracking UIs.
        foreach (['.tracking-status', '.shipment-status', '.current-status', '.status-text', '#status', '#shipment-status'] as $selector) {
            // Naive class match — good enough without a CSS selector engine.
            $className = ltrim($selector, '.#');
            $pattern = '/<(?:p|div|span|h\d)\b[^>]*class=["\'][^"\']*\b'.preg_quote($className, '/').'\b[^"\']*["\'][^>]*>(.*?)<\/(?:p|div|span|h\d)>/is';
            if (preg_match($pattern, $clean, $m) === 1) {
                $text = trim(strip_tags($m[1]));
                $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($text !== '' && $this->statusMapper->map($text) !== ShipmentStatus::Unknown) {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * @return list<RawEvent>
     */
    private function extractEvents(string $html): array
    {
        $clean = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);

        // Try tables first — most courier tracking pages render the activity
        // log as <table> rows. Each row is expected to contain a date, an
        // optional location, and an activity description.
        $events = $this->extractEventsFromTables($clean);
        if ($events !== []) {
            return $events;
        }

        // Fallback: JSON-LD blocks. Some pages embed the tracking state
        // as a <script type="application/ld+json"> blob.
        $events = $this->extractEventsFromJsonLd($clean);
        if ($events !== []) {
            return $events;
        }

        return [];
    }

    /**
     * @return list<RawEvent>
     */
    private function extractEventsFromTables(string $html): array
    {
        $events = [];

        if (preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $html, $rows) === false) {
            return [];
        }

        foreach ($rows[1] as $rowHtml) {
            // Header rows use <th>; skip them so we don't invent events out
            // of "Date / Activity / Location" column titles.
            if (preg_match('/<th\b/i', $rowHtml) === 1) {
                continue;
            }

            if (preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $rowHtml, $cells) === false) {
                continue;
            }

            $cellValues = array_map(static function (string $cell): string {
                $text = trim(strip_tags($cell));
                $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

                return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }, $cells[1]);

            if (count($cellValues) < 2) {
                continue;
            }

            // Try every (date, activity) and (date, location, activity)
            // permutation so we don't have to assume column order.
            $date = null;
            $location = null;
            $description = null;
            foreach ($cellValues as $value) {
                $parsed = $this->parseDate($value);
                if ($date === null && $parsed !== null) {
                    $date = $parsed;

                    continue;
                }
                if ($description === null && $this->statusMapper->map($value) !== ShipmentStatus::Unknown) {
                    $description = $value;

                    continue;
                }
                if ($location === null && $value !== '' && $value !== $description) {
                    $location = $value;
                }
            }
            // If we never found a status-bearing cell, take the longest
            // remaining one as the description.
            if ($description === null) {
                $remaining = array_values(array_filter($cellValues, fn ($v) => $v !== '' && $v !== ($date?->toDateTimeString())));
                if ($remaining !== []) {
                    $description = (string) (array_reduce($remaining, fn ($a, $b) => strlen((string) $a) >= strlen((string) $b) ? $a : $b) ?? '');
                }
            }
            if ($description === null || $description === '') {
                continue;
            }
            if ($this->statusMapper->map($description) === ShipmentStatus::Unknown) {
                // No recognizable status in this row. Treating it as an event
                // would just add noise to the timeline.
                continue;
            }

            $events[] = new RawEvent(
                occurredAt: $date ?? now(),
                status: $this->statusMapper->map($description),
                location: $location,
                description: $description,
                raw: ['cells' => $cellValues],
            );
        }

        // Courier pages render the activity log newest-first, so we keep
        // the same order — the app's display sorts by occurred_at anyway.
        return $events;
    }

    /**
     * @return list<RawEvent>
     */
    private function extractEventsFromJsonLd(string $html): array
    {
        if (preg_match_all('/<script\b[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches) === false) {
            return [];
        }

        $events = [];
        foreach ($matches[1] as $raw) {
            $decoded = json_decode(trim($raw), true);
            if (! is_array($decoded)) {
                continue;
            }

            $items = $decoded['@graph'] ?? [$decoded];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $subEvents = $item['events'] ?? $item['trackingEvents'] ?? $item['parcelEvents'] ?? null;
                if (! is_array($subEvents)) {
                    continue;
                }
                foreach ($subEvents as $sub) {
                    if (! is_array($sub)) {
                        continue;
                    }
                    $description = (string) ($sub['description'] ?? $sub['eventDescription'] ?? $sub['status'] ?? '');
                    if ($description === '') {
                        continue;
                    }
                    $events[] = new RawEvent(
                        occurredAt: $this->parseDate($sub['date'] ?? $sub['eventDate'] ?? null) ?? now(),
                        status: $this->statusMapper->map($description),
                        location: $sub['location'] ?? null,
                        description: $description,
                        raw: $sub,
                    );
                }
            }
        }

        return $events;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // Try a couple of common date formats the courier sites use before
        // falling back to Carbon's flexible parser. Carbon 3 throws
        // InvalidFormatException in strict mode when a format doesn't match,
        // so each attempt is wrapped in try/catch.
        $candidates = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s\Z',
            'd M Y, H:i',
            'd-M-Y H:i',
            'M d, Y H:i',
            'd/m/Y H:i',
            'm/d/Y H:i',
        ];
        foreach ($candidates as $format) {
            try {
                $dt = Carbon::createFromFormat($format, $value);
                if ($dt !== false) {
                    return $dt;
                }
            } catch (Throwable) {
                // try the next format
            }
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }
}
