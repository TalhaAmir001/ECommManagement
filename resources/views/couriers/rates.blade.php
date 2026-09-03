@extends('layouts.dashboard')

@section('title', $provider->display_name.' · Rates')

@section('content')
    @php
        $editing = $editingRate;
        $inputClass = 'w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10';
        $defaultZoneId = $zones->firstWhere('is_default', true)?->id ?? $zones->first()?->id;
        $bandLabel = function (?string $from, ?string $to): string {
            $fromText = $from !== null ? rtrim(rtrim(number_format((float) $from, 3), '0'), '.') : '0';
            if ($to === null || $to === '') {
                return $fromText.' kg+';
            }
            return $fromText.' – '.rtrim(rtrim(number_format((float) $to, 3), '0'), '.').' kg';
        };
    @endphp

    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        <div>
            <a href="{{ route('couriers.settings', $provider) }}"
                class="inline-flex items-center gap-1 text-xs font-medium text-muted transition-colors hover:text-ink">
                <x-dashboard.icon name="chevron-left" class="h-3.5 w-3.5" />
                Back to {{ $provider->display_name }} settings
            </a>
            <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-[22px] font-semibold tracking-tight text-ink">Delivery rates</h1>
                    <p class="mt-1 text-sm text-muted">
                        {{ $provider->display_name }} — zones × weight bands. Used to estimate courier cost for
                        shipments whose real cost was never recorded.
                    </p>
                </div>
                <a href="{{ route('couriers.edit', $provider) }}"
                    class="inline-flex items-center gap-2 rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium text-ink transition-colors hover:bg-canvas">
                    <x-dashboard.icon name="edit" class="h-4 w-4" />
                    Edit API settings
                </a>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-4 rounded-xl border border-line bg-positive-soft px-4 py-3 text-sm text-positive">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mt-4 rounded-xl border border-line bg-negative-soft px-4 py-3 text-sm text-negative">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        {{-- Zones --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="border-b border-line p-5">
                <h2 class="text-sm font-semibold text-ink">Zones</h2>
                <p class="mt-0.5 text-xs text-muted">Cities grouped into zones. A shipment's consignee city picks its destination zone; the default zone catches unlisted cities.</p>
            </div>

            <div class="grid grid-cols-1 gap-px bg-line lg:grid-cols-5">
                <div class="bg-surface p-5 lg:col-span-3">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-faint">Saved zones</h3>
                    @if ($zones->isEmpty())
                        <p class="mt-3 text-sm text-muted">No zones yet. Add your first zone on the right (one default is recommended).</p>
                    @else
                        <div class="mt-3 divide-y divide-line">
                            @foreach ($zones as $zone)
                                <div class="flex items-center justify-between gap-3 py-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-ink">
                                            {{ $zone->name }}
                                            @if ($zone->is_default)
                                                <span class="ml-1 inline-flex rounded-full bg-accent-soft px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-accent">Default</span>
                                            @endif
                                        </p>
                                        <p class="mt-0.5 truncate text-xs text-muted">{{ implode(', ', $zone->cities) ?: '—' }}</p>
                                    </div>
                                    <form method="POST" action="{{ route('couriers.rates.zones.destroy', [$provider, $zone]) }}"
                                        onsubmit="return confirm('Delete zone {{ $zone->name }}? Its rate rows are deleted too.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded-md p-1.5 text-muted transition-colors hover:bg-negative-soft hover:text-negative" title="Delete zone">
                                            <x-dashboard.icon name="trash" class="h-4 w-4" />
                                        </button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="bg-surface p-5 lg:col-span-2">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-faint">Add a zone</h3>
                    <form method="POST" action="{{ route('couriers.rates.zones.store', $provider) }}" class="mt-3 space-y-3">
                        @csrf
                        <div>
                            <label for="zone_name" class="mb-1.5 block text-xs font-medium text-muted">Zone name</label>
                            <input id="zone_name" name="name" value="{{ old('name') }}" placeholder="e.g. Karachi, Punjab, Default" class="{{ $inputClass }}" />
                        </div>
                        <div>
                            <label for="zone_cities" class="mb-1.5 block text-xs font-medium text-muted">Cities</label>
                            <textarea id="zone_cities" name="cities" rows="3" placeholder="Comma or newline separated, e.g. Karachi, Lahore, Faisalabad" class="{{ $inputClass }}">{{ old('cities') }}</textarea>
                        </div>
                        <label class="flex items-center gap-2 text-xs font-medium text-muted">
                            <input type="checkbox" name="is_default" value="1" class="h-4 w-4 rounded border-line-strong accent-ink" @checked(old('is_default') || $zones->isEmpty()) />
                            Make this the default zone
                        </label>
                        <button type="submit" class="rounded-lg bg-ink px-3.5 py-2 text-xs font-medium text-surface transition-colors hover:bg-ink/90">
                            Add zone
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Rates --}}
        <div class="mt-6 rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
            <div class="border-b border-line p-5">
                <h2 class="text-sm font-semibold text-ink">Rate matrix</h2>
                <p class="mt-0.5 text-xs text-muted">A price per origin zone × destination zone × weight band. Estimates only apply when a shipment has no recorded courier cost.</p>
            </div>

            @if ($zones->isEmpty())
                <div class="p-10 text-center text-sm text-muted">Add at least one zone above before entering rates.</div>
            @else
                <div class="border-b border-line bg-canvas/30 p-5">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-faint">{{ $editing ? 'Edit rate' : 'Add a rate' }}</h3>
                    <form method="POST"
                        action="{{ $editing ? route('couriers.rates.update', [$provider, $editing]) : route('couriers.rates.store', $provider) }}"
                        class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        @csrf
                        @if ($editing)
                            @method('PUT')
                        @endif

                        <div>
                            <label for="origin_zone_id" class="mb-1.5 block text-xs font-medium text-muted">Origin zone</label>
                            <select id="origin_zone_id" name="origin_zone_id" class="{{ $inputClass }}">
                                @foreach ($zones as $zone)
                                    <option value="{{ $zone->id }}" @selected((string) old('origin_zone_id', $editing?->origin_zone_id ?? $defaultZoneId) === (string) $zone->id)>
                                        {{ $zone->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="destination_zone_id" class="mb-1.5 block text-xs font-medium text-muted">Destination zone</label>
                            <select id="destination_zone_id" name="destination_zone_id" class="{{ $inputClass }}">
                                @foreach ($zones as $zone)
                                    <option value="{{ $zone->id }}" @selected((string) old('destination_zone_id', $editing?->destination_zone_id ?? $defaultZoneId) === (string) $zone->id)>
                                        {{ $zone->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="weight_from_kg" class="mb-1.5 block text-xs font-medium text-muted">Weight from (kg)</label>
                            <input id="weight_from_kg" name="weight_from_kg" type="number" step="0.001" min="0" value="{{ old('weight_from_kg', $editing?->weight_from_kg ?? 0) }}" class="{{ $inputClass }}" />
                        </div>
                        <div>
                            <label for="weight_to_kg" class="mb-1.5 block text-xs font-medium text-muted">Weight to (kg)</label>
                            <input id="weight_to_kg" name="weight_to_kg" type="number" step="0.001" min="0" value="{{ old('weight_to_kg', $editing?->weight_to_kg ?? '') }}" placeholder="Blank = no upper limit" class="{{ $inputClass }}" />
                        </div>
                        <div>
                            <label for="price" class="mb-1.5 block text-xs font-medium text-muted">Price</label>
                            <input id="price" name="price" type="number" step="0.01" min="0" value="{{ old('price', $editing?->price ?? '') }}" class="{{ $inputClass }}" />
                        </div>
                        <div>
                            <label for="cod_fee" class="mb-1.5 block text-xs font-medium text-muted">COD fee (optional)</label>
                            <input id="cod_fee" name="cod_fee" type="number" step="0.01" min="0" value="{{ old('cod_fee', $editing?->cod_fee ?? '') }}" placeholder="e.g. 0" class="{{ $inputClass }}" />
                        </div>
                        <div>
                            <label for="currency" class="mb-1.5 block text-xs font-medium text-muted">Currency</label>
                            <input id="currency" name="currency" maxlength="3" value="{{ old('currency', $editing?->currency ?? 'PKR') }}" class="{{ $inputClass }} uppercase" />
                        </div>
                        <div class="flex flex-col justify-between gap-2">
                            <label class="flex items-center gap-2 text-xs font-medium text-muted">
                                <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded border-line-strong accent-ink" @checked((bool) old('is_active', $editing?->is_active ?? true)) />
                                Active
                            </label>
                            <button type="submit" class="rounded-lg bg-ink px-3.5 py-2 text-xs font-medium text-surface transition-colors hover:bg-ink/90">
                                {{ $editing ? 'Save changes' : 'Add rate' }}
                            </button>
                            @if ($editing)
                                <a href="{{ route('couriers.rates.index', $provider) }}" class="text-center text-xs font-medium text-muted hover:text-ink">Cancel edit</a>
                            @endif
                        </div>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    @if ($rates->isEmpty())
                        <div class="p-10 text-center text-sm text-muted">No rates yet. Add your first origin → destination × weight price above.</div>
                    @else
                        <table class="w-full min-w-[680px] text-left text-sm">
                            <thead>
                                <tr class="border-b border-line text-[11px] font-medium uppercase tracking-[0.1em] text-faint">
                                    <th class="py-3 pl-5 pr-4 font-medium">Route</th>
                                    <th class="py-3 pr-4 font-medium">Weight</th>
                                    <th class="py-3 pr-4 text-right font-medium">Price</th>
                                    <th class="py-3 pr-4 text-right font-medium">COD fee</th>
                                    <th class="py-3 pr-4 font-medium">Status</th>
                                    <th class="py-3 pr-5 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($rates as $rate)
                                    <tr class="group transition-colors hover:bg-canvas/60">
                                        <td class="py-3 pl-5 pr-4">
                                            <p class="font-medium text-ink">{{ $rate->originZone?->name }} → {{ $rate->destinationZone?->name }}</p>
                                        </td>
                                        <td class="py-3 pr-4 tabular-nums text-muted">{{ $bandLabel($rate->weight_from_kg, $rate->weight_to_kg) }}</td>
                                        <td class="py-3 pr-4 text-right font-semibold tabular-nums text-ink">
                                            {{ format_money((float) $rate->price) }} {{ $rate->currency }}
                                        </td>
                                        <td class="py-3 pr-4 text-right tabular-nums text-muted">
                                            {{ $rate->cod_fee !== null ? format_money((float) $rate->cod_fee) : '—' }}
                                        </td>
                                        <td class="py-3 pr-4">
                                            @if ($rate->is_active)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-positive-soft px-2 py-0.5 text-xs font-medium text-positive"><span class="h-1.5 w-1.5 rounded-full bg-positive"></span>Active</span>
                                            @else
                                                <span class="inline-flex items-center gap-1 rounded-full bg-canvas px-2 py-0.5 text-xs font-medium text-muted"><span class="h-1.5 w-1.5 rounded-full bg-faint"></span>Inactive</span>
                                            @endif
                                        </td>
                                        <td class="py-3 pr-5">
                                            <div class="flex items-center justify-end gap-1">
                                                <a href="{{ route('couriers.rates.index', ['provider' => $provider, 'edit' => $rate->id]) }}"
                                                    class="rounded-md p-1.5 text-muted transition-colors hover:bg-canvas hover:text-ink" title="Edit rate">
                                                    <x-dashboard.icon name="edit" class="h-4 w-4" />
                                                </a>
                                                <form method="POST" action="{{ route('couriers.rates.destroy', [$provider, $rate]) }}"
                                                    onsubmit="return confirm('Delete this rate?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="rounded-md p-1.5 text-muted transition-colors hover:bg-negative-soft hover:text-negative" title="Delete rate">
                                                        <x-dashboard.icon name="trash" class="h-4 w-4" />
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endsection
