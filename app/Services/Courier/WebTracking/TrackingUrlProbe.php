<?php

namespace App\Services\Courier\WebTracking;

/**
 * Best-effort detection of which courier a tracking URL belongs to, plus
 * the tracking number embedded in the URL when present.
 *
 * The detection is deliberately conservative: a hostname has to match a
 * well-known suffix, otherwise the carrier is reported as "unknown" and
 * the GenericWebTracker is left to scrape the page. Wrong matches would
 * feed the wrong provider's normalizer and corrupt the data, so when in
 * doubt we return null and let the caller fall back.
 *
 * The known-host list lives in config('couriers.tracking_hosts') and is
 * deliberately small — only the carriers this app has either integrated
 * (leopards, tcs) or seen often enough to recognize reliably.
 */
final class TrackingUrlProbe
{
    /**
     * @param  array<string, string>  $knownHosts  hostname suffix (lowercased) → courier key
     */
    public function __construct(
        private readonly array $knownHosts = [],
    ) {}

    /**
     * Parse a tracking URL and return the courier key + tracking number, or
     * nulls when the URL doesn't yield a confident match.
     *
     * @return array{courier: ?string, tracking_number: ?string, host: ?string}
     */
    public function probe(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['courier' => null, 'tracking_number' => null, 'host' => null];
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return ['courier' => null, 'tracking_number' => null, 'host' => null];
        }

        $host = strtolower($parts['host']);
        $courier = $this->matchHost($host);
        $trackingNumber = $this->extractTrackingNumber($parts, $url);

        return [
            'courier' => $courier,
            'tracking_number' => $trackingNumber,
            'host' => $host,
        ];
    }

    /**
     * True when the URL hostname belongs to a known courier.
     */
    public function matchesKnownCourier(string $url): bool
    {
        return $this->probe($url)['courier'] !== null;
    }

    private function matchHost(string $host): ?string
    {
        foreach ($this->knownHosts as $suffix => $key) {
            $suffix = strtolower((string) $suffix);
            if ($suffix === '') {
                continue;
            }

            // Match either the exact host or a subdomain. "example.com" should
            // match "track.example.com" but not "notexample.com".
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * Try to pull a tracking number straight out of the URL so we don't have
     * to scrape the page just to know which shipment we're tracking.
     *
     * @param  array<string, mixed>  $parts
     */
    private function extractTrackingNumber(array $parts, string $originalUrl): ?string
    {
        $candidates = [];

        if (! empty($parts['query'])) {
            parse_str((string) $parts['query'], $q);
            // Common parameter names used by tracking links. We deliberately
            // skip tiny ones like "id" because they collide with internal
            // session params on some sites.
            foreach (['tracking_number', 'trackingNumber', 'track', 'cn', 'awb', 'consignment', 'id'] as $key) {
                if (isset($q[$key]) && is_string($q[$key]) && $q[$key] !== '') {
                    $candidates[] = $q[$key];
                }
            }
        }

        // Also try the last path segment — many carriers put the number on
        // the URL like /track/123456 or /123456.
        if (! empty($parts['path'])) {
            $segments = array_values(array_filter(explode('/', (string) $parts['path']), 'strlen'));
            if ($segments !== []) {
                $candidates[] = rawurldecode((string) end($segments));
            }
        }

        foreach ($candidates as $candidate) {
            $trimmed = trim($candidate);
            // A reasonable tracking number is at least 4 chars of word/digit
            // content and doesn't look like a path fragment or html-escaped
            // garbage. We accept letters, digits, dashes, and underscores.
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{3,63}$/', $trimmed) === 1) {
                return $trimmed;
            }
        }

        return null;
    }
}
