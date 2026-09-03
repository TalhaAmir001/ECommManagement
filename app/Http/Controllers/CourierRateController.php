<?php

namespace App\Http\Controllers;

use App\Models\CourierProvider as CourierProviderModel;
use App\Models\CourierRate;
use App\Models\CourierZone;
use App\Services\Courier\DeliveryRateCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Settings → Couriers → Rates. Manages a courier's delivery rate card:
 *
 *  - zones: named collections of cities (one default zone per provider),
 *  - rates: price matrix cells of origin zone × destination zone × weight
 *    band → price (+ optional COD surcharge).
 *
 * The DeliveryRateCalculator uses these to estimate a shipment's courier
 * cost when the real billed cost was never recorded, which then flows into
 * the Audit report's courier-cost line.
 */
class CourierRateController extends Controller
{
    public function index(Request $request, CourierProviderModel $provider): View
    {
        $zones = $provider->zones()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $rates = $provider->rates()
            ->with(['originZone', 'destinationZone'])
            ->orderBy('origin_zone_id')
            ->orderBy('destination_zone_id')
            ->orderBy('weight_from_kg')
            ->get();

        $editingRate = null;
        $editId = (int) $request->query('edit');
        if ($editId > 0) {
            $editingRate = CourierRate::query()
                ->where('courier_provider_id', $provider->id)
                ->with(['originZone', 'destinationZone'])
                ->find($editId);
        }

        return view('couriers.rates', [
            'provider' => $provider,
            'zones' => $zones,
            'rates' => $rates,
            'editingRate' => $editingRate,
        ]);
    }

    public function storeZone(Request $request, CourierProviderModel $provider): RedirectResponse
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('courier_zones', 'name')->where('courier_provider_id', $provider->id),
            ],
            'cities' => ['required', 'string', 'max:2000'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $cities = $this->normalizeCities((string) $data['cities']);
        if ($cities === []) {
            return back()->withErrors(['cities' => 'Enter at least one city for the zone.'])->withInput();
        }

        $isDefault = $request->boolean('is_default');

        if ($isDefault) {
            CourierZone::query()
                ->where('courier_provider_id', $provider->id)
                ->update(['is_default' => false]);
        }

        $provider->zones()->create([
            'name' => trim((string) $data['name']),
            'cities' => $cities,
            'is_default' => $isDefault,
        ]);

        return back()->with('status', 'Zone added. Now add rate rows for its destination prices.');
    }

    public function destroyZone(CourierProviderModel $provider, CourierZone $zone): RedirectResponse
    {
        $this->assertOwned($provider, $zone->courier_provider_id);

        $zone->delete();

        return back()->with('status', 'Zone "'.$zone->name.'" deleted along with its rate rows.');
    }

    public function store(Request $request, CourierProviderModel $provider): RedirectResponse
    {
        $data = $this->validateRate($request, $provider);

        CourierRate::query()->create(array_merge($data, [
            'courier_provider_id' => $provider->id,
            'is_active' => $request->boolean('is_active', true),
        ]));

        return redirect()->route('couriers.rates.index', $provider)
            ->with('status', 'Rate added to the '.$provider->display_name.' rate card.');
    }

    public function update(Request $request, CourierProviderModel $provider, CourierRate $rate): RedirectResponse
    {
        $this->assertOwned($provider, $rate->courier_provider_id);

        $data = $this->validateRate($request, $provider, ignoreId: $rate->id);

        $rate->forceFill(array_merge($data, [
            'is_active' => $request->boolean('is_active', true),
        ]))->save();

        return redirect()->route('couriers.rates.index', $provider)
            ->with('status', 'Rate updated.');
    }

    public function destroy(CourierProviderModel $provider, CourierRate $rate): RedirectResponse
    {
        $this->assertOwned($provider, $rate->courier_provider_id);

        $rate->delete();

        return back()->with('status', 'Rate removed from the '.$provider->display_name.' rate card.');
    }

    /**
     * Validate a rate-row payload (create or update).
     *
     * @return array{origin_zone_id: int, destination_zone_id: int, weight_from_kg: string, weight_to_kg: ?string, price: string, cod_fee: ?string, currency: string}
     */
    private function validateRate(Request $request, CourierProviderModel $provider, ?int $ignoreId = null): array
    {
        $zoneIds = $provider->zones()->pluck('id')->all();

        $data = $request->validate([
            'origin_zone_id' => ['required', 'integer', Rule::in($zoneIds)],
            'destination_zone_id' => ['required', 'integer', Rule::in($zoneIds)],
            'weight_from_kg' => ['required', 'numeric', 'min:0'],
            'weight_to_kg' => ['nullable', 'numeric', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'cod_fee' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $origin = (int) $data['origin_zone_id'];
        $destination = (int) $data['destination_zone_id'];
        $from = (float) $data['weight_from_kg'];
        $to = $data['weight_to_kg'] !== null && $data['weight_to_kg'] !== ''
            ? (float) $data['weight_to_kg']
            : null;

        if ($to !== null && $to <= $from) {
            throw ValidationException::withMessages(['weight_to_kg' => 'The weight "to" value must be greater than the "from" value.']);
        }

        if ($this->bandOverlaps($provider->id, $origin, $destination, $from, $to, $ignoreId)) {
            throw ValidationException::withMessages(['weight_from_kg' => 'This weight band overlaps an existing rate for the same origin → destination pair.']);
        }

        return [
            'origin_zone_id' => $origin,
            'destination_zone_id' => $destination,
            'weight_from_kg' => $from,
            'weight_to_kg' => $to,
            'price' => (string) round((float) $data['price'], 2),
            'cod_fee' => $data['cod_fee'] !== null && $data['cod_fee'] !== ''
                ? (string) round((float) $data['cod_fee'], 2)
                : null,
            'currency' => strtoupper(trim((string) ($data['currency'] ?? 'PKR'))),
        ];
    }

    /**
     * Whether an existing active-or-inactive rate already covers part of the
     * requested weight range for the same zone pair.
     */
    private function bandOverlaps(int $providerId, int $origin, int $destination, float $from, ?float $to, ?int $ignoreId): bool
    {
        $existing = CourierRate::query()
            ->where('courier_provider_id', $providerId)
            ->where('origin_zone_id', $origin)
            ->where('destination_zone_id', $destination)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get(['weight_from_kg', 'weight_to_kg']);

        $newTo = $to ?? INF;

        foreach ($existing as $row) {
            $existingTo = $row->weight_to_kg !== null ? (float) $row->weight_to_kg : INF;

            // Overlap when the ranges intersect: from < other.to && to > other.from
            if ($from < $existingTo && $newTo > (float) $row->weight_from_kg) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse a comma/newline separated city list into normalized, unique city
     * names (trimmed + lowercased + whitespace collapsed).
     *
     * @return list<string>
     */
    private function normalizeCities(string $raw): array
    {
        $cities = [];

        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $part) {
            $city = DeliveryRateCalculator::normalizeCity($part);
            if ($city !== '' && ! in_array($city, $cities, true)) {
                $cities[] = $city;
            }
        }

        return $cities;
    }

    private function assertOwned(CourierProviderModel $provider, int $providerId): void
    {
        if ((int) $provider->id !== $providerId) {
            abort(404);
        }
    }
}
