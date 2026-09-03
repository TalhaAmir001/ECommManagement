<?php

namespace App\Services\Courier;

use App\Models\CourierProvider;
use App\Models\Shipment;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a shipment's origin/destination zone from its city and picks the
 * matching weight band on the provider's rate matrix, producing an estimated
 * courier cost.
 *
 * Rates are a fallback: the Audit and shipment pages only use the estimate
 * when the shipment has no actual `cost` recorded (real billed amounts always
 * win). Providers without any zones/rates simply yield no estimate.
 *
 * Zone/rate rows are cached per request so batch walks (e.g. ShippingFinanceService)
 * only hit the database once per provider.
 */
class DeliveryRateCalculator
{
    /** @var array<int, array<string, mixed>> */
    private array $maps = [];

    /**
     * Estimate for a full provider row (convenience for callers holding a model).
     */
    public function estimate(
        CourierProvider $provider,
        ?float $weightKg,
        ?string $originCity,
        ?string $destinationCity,
        ?float $codAmount = null
    ): ?float {
        return $this->estimateByProviderId((int) $provider->id, $weightKg, $originCity, $destinationCity, $codAmount);
    }

    /**
     * Estimate for a shipment model.
     */
    public function estimateForShipment(Shipment $shipment): ?float
    {
        return $this->estimateByProviderId(
            (int) $shipment->courier_provider_id,
            $shipment->effectiveWeightKg() ?? 0.0,
            $shipment->consignor_city,
            $shipment->consignee_city,
            $shipment->cod_amount !== null ? (float) $shipment->cod_amount : null
        );
    }

    /**
     * Estimate keyed by provider id — used by batch aggregators that walk
     * plain database rows instead of models.
     */
    public function estimateByProviderId(
        ?int $providerId,
        ?float $weightKg,
        ?string $originCity,
        ?string $destinationCity,
        ?float $codAmount = null
    ): ?float {
        if ($providerId === null) {
            return null;
        }

        $map = $this->mapFor($providerId);
        if ($map['zones'] === [] || $map['rates'] === []) {
            return null;
        }

        $origin = $this->resolveZoneId($map, $originCity);
        $destination = $this->resolveZoneId($map, $destinationCity);
        if ($origin === null || $destination === null) {
            return null;
        }

        $weight = max(0.0, (float) ($weightKg ?? 0));
        $rate = null;

        foreach ($map['rates'] as $row) {
            if ((int) $row['origin_zone_id'] !== $origin || (int) $row['destination_zone_id'] !== $destination) {
                continue;
            }

            $from = (float) $row['weight_from_kg'];
            $to = $row['weight_to_kg'] !== null && $row['weight_to_kg'] !== '' ? (float) $row['weight_to_kg'] : null;

            if ($weight >= $from && ($to === null || $weight < $to)) {
                $rate = $row;
                break;
            }
        }

        if ($rate === null) {
            return null;
        }

        $amount = (float) $rate['price'];
        $codFee = $rate['cod_fee'];
        if ((float) ($codAmount ?? 0) > 0 && $codFee !== null && $codFee !== '') {
            $amount += (float) $codFee;
        }

        return round($amount, 2);
    }

    /**
     * Normalise a city name the same way zones are stored: trimmed, lowercased,
     * internal whitespace collapsed (so "Karachi  City" still matches "karachi city").
     */
    public static function normalizeCity(?string $city): string
    {
        if ($city === null) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', strtolower($city)) ?? '');
    }

    /**
     * @return array{zones: list<array<string, mixed>>, rates: list<array<string, mixed>>, default_zone_id: ?int}
     */
    private function mapFor(int $providerId): array
    {
        if (! array_key_exists($providerId, $this->maps)) {
            $zones = DB::table('courier_zones')
                ->where('courier_provider_id', $providerId)
                ->get(['id', 'name', 'cities', 'is_default'])
                ->map(function ($zone) {
                    $cities = json_decode((string) ($zone->cities ?? '[]'), true);

                    return [
                        'id' => (int) $zone->id,
                        'name' => (string) $zone->name,
                        'cities' => is_array($cities) ? array_values($cities) : [],
                        'is_default' => (bool) $zone->is_default,
                    ];
                })
                ->all();

            $rates = DB::table('courier_rates')
                ->where('courier_provider_id', $providerId)
                ->where('is_active', true)
                ->get(['id', 'origin_zone_id', 'destination_zone_id', 'weight_from_kg', 'weight_to_kg', 'price', 'cod_fee'])
                ->map(fn ($rate) => (array) $rate)
                ->all();

            $defaultZoneId = null;
            foreach ($zones as $zone) {
                if ($zone['is_default']) {
                    $defaultZoneId = $zone['id'];
                    break;
                }
            }

            $this->maps[$providerId] = [
                'zones' => $zones,
                'rates' => $rates,
                'default_zone_id' => $defaultZoneId,
            ];
        }

        return $this->maps[$providerId];
    }

    /**
     * Find the zone a city belongs to, falling back to the provider's default
     * zone, then any zone.
     *
     * @param  array{zones: list<array<string, mixed>>, rates: list<array<string, mixed>>, default_zone_id: ?int}  $map
     */
    private function resolveZoneId(array $map, ?string $city): ?int
    {
        $needle = self::normalizeCity($city);

        if ($needle !== '') {
            foreach ($map['zones'] as $zone) {
                if (in_array($needle, $zone['cities'], true)) {
                    return $zone['id'];
                }
            }
        }

        if ($map['default_zone_id'] !== null) {
            return $map['default_zone_id'];
        }

        return $map['zones'][0]['id'] ?? null;
    }
}
