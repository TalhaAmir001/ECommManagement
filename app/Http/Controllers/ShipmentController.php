<?php

namespace App\Http\Controllers;

use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider as CourierProviderModel;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Courier\CourierProviderRegistry;
use App\Services\Courier\Exceptions\CourierException;
use App\Services\Courier\OrderAutoMatcher;
use App\Services\Courier\Providers\ManualProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ShipmentController extends Controller
{
    /**
     * List shipments with filters: provider, status, search, date range.
     */
    public function index(Request $request): View
    {
        $filters = $request->query();

        $query = Shipment::query()
            ->with(['provider', 'order.customer'])
            ->orderByDesc('last_event_at')
            ->orderByDesc('id');

        if (! empty($filters['provider'])) {
            $query->whereHas('provider', fn ($q) => $q->where('key', $filters['provider']));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $search = trim((string) $filters['q']);
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('tracking_number', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhere('consignee_name', 'like', "%{$search}%")
                        ->orWhere('consignee_phone', 'like', "%{$search}%")
                        ->orWhereHas('order', fn ($oq) => $oq->where('number', 'like', "%{$search}%"));
                });
            }
        }
        if (! empty($filters['date'])) {
            $query->when($filters['date'] === 'today', fn ($q) => $q->whereDate('created_at', Carbon::today()))
                ->when($filters['date'] === '7d', fn ($q) => $q->whereDate('created_at', '>=', Carbon::today()->subDays(6)))
                ->when($filters['date'] === '30d', fn ($q) => $q->whereDate('created_at', '>=', Carbon::today()->subDays(29)));
        }

        $shipments = $query->paginate(20)->withQueryString();

        return view('shipments.index', [
            'shipments' => $shipments,
            'providers' => CourierProviderModel::query()->orderBy('display_name')->get(),
            'statuses' => ShipmentStatus::cases(),
            'filters' => $filters,
            'totals' => $this->totals(),
        ]);
    }

    /**
     * Detail page with event timeline and manual-link controls.
     */
    public function show(Shipment $shipment): View
    {
        $shipment->load(['provider', 'order.customer', 'events']);

        return view('shipments.show', [
            'shipment' => $shipment,
            'statuses' => ShipmentStatus::cases(),
        ]);
    }

    /**
     * Manually attach (or re-link) a shipment to a local order.
     */
    public function link(Request $request, Shipment $shipment): RedirectResponse
    {
        $data = $request->validate([
            'order_number' => ['required', 'string', 'max:64'],
        ]);

        $order = Order::query()->where('number', $data['order_number'])->first();
        if ($order === null) {
            return back()->withErrors(['order_number' => 'No order with that number exists.']);
        }

        $shipment->forceFill([
            'order_id' => $order->id,
            'matched_method' => 'manual',
            'matched_at' => now(),
        ])->save();

        return redirect()->route('shipments.show', $shipment)->with('status', 'Shipment linked to order '.$order->number.'.');
    }

    /**
     * Break the auto/manual link between a shipment and its order.
     */
    public function unlink(Shipment $shipment): RedirectResponse
    {
        $shipment->forceFill([
            'order_id' => null,
            'matched_method' => null,
            'matched_at' => null,
        ])->save();

        return redirect()->route('shipments.show', $shipment)->with('status', 'Link removed.');
    }

    /**
     * Re-run the auto-matcher on a single shipment.
     */
    public function rematch(Shipment $shipment, OrderAutoMatcher $matcher): RedirectResponse
    {
        $orderId = $matcher->match($shipment);

        return redirect()->route('shipments.show', $shipment)->with(
            'status',
            $orderId
                ? 'Matched to order id '.$orderId.'.'
                : 'No auto-match found; the shipment is now orphaned.'
        );
    }

    /**
     * Pull fresh data from the provider for a single shipment.
     */
    public function refresh(Shipment $shipment, CourierProviderRegistry $registry): RedirectResponse
    {
        $provider = $registry->resolve($shipment->provider);
        try {
            $raw = $provider->getShipment($shipment->external_id);
        } catch (CourierException $e) {
            return back()->withErrors(['status' => 'Provider call failed: '.$e->getMessage()]);
        }

        if ($raw === null) {
            return back()->withErrors(['status' => 'Provider has no record of this shipment.']);
        }

        $shipment->forceFill([
            'status' => $raw->status->value,
            'status_detail' => $raw->statusDetail,
            'shipped_at' => $raw->shippedAt,
            'delivered_at' => $raw->deliveredAt,
            'last_event_at' => $raw->lastEventAt ?? $raw->shippedAt,
            'consignee_name' => $raw->consignee?->name ?? $shipment->consignee_name,
            'consignee_phone' => $raw->consignee?->phone ?? $shipment->consignee_phone,
            'consignee_address' => $raw->consignee?->address ?? $shipment->consignee_address,
            'consignee_city' => $raw->consignee?->city ?? $shipment->consignee_city,
            'weight_kg' => $raw->weightKg,
            'pieces' => $raw->pieces,
            'cod_amount' => $raw->codAmount,
            'cost' => $raw->cost,
            'currency' => $raw->currency,
            'raw_payload' => $raw->raw,
        ])->save();

        // Append any new events.
        $events = $provider->listEvents($shipment->external_id);
        foreach ($events as $event) {
            \App\Models\ShipmentEvent::query()->firstOrCreate(
                [
                    'shipment_id' => $shipment->id,
                    'occurred_at' => $event->occurredAt ?? now(),
                    'location' => $event->location,
                ],
                [
                    'status' => $event->status->value,
                    'description' => $event->description,
                    'raw_payload' => $event->raw,
                ],
            );
        }

        return redirect()->route('shipments.show', $shipment)->with('status', 'Refreshed from '.$shipment->provider->display_name.'.');
    }

    /**
     * Create a shipment from manual entry. The manual provider always exists,
     * so this is the day-one happy path.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tracking_number' => ['required', 'string', 'max:128'],
            'reference' => ['nullable', 'string', 'max:128'],
            'consignee_name' => ['nullable', 'string', 'max:128'],
            'consignee_phone' => ['nullable', 'string', 'max:64'],
            'consignee_address' => ['nullable', 'string', 'max:255'],
            'consignee_city' => ['nullable', 'string', 'max:128'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'pieces' => ['nullable', 'integer', 'min:1'],
            'cod_amount' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', 'string', 'max:32'],
        ]);

        $manual = CourierProviderModel::query()->where('key', 'manual')->firstOrFail();

        $shipment = Shipment::query()->create(array_merge($data, [
            'courier_provider_id' => $manual->id,
            'external_id' => 'MANUAL-'.strtoupper(bin2hex(random_bytes(4))),
            'status' => $data['status'] ?? ShipmentStatus::Created->value,
            'currency' => $data['currency'] ?? config('couriers.default_currency', 'PKR'),
            'shipped_at' => now(),
            'last_event_at' => now(),
        ]));

        // Record the initial event so the timeline isn't empty.
        \App\Models\ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'occurred_at' => now(),
            'status' => $shipment->status,
            'description' => 'Shipment created',
        ]);

        // Try to auto-match to an order.
        $matcher = app(OrderAutoMatcher::class);
        $matcher->match($shipment);

        return redirect()->route('shipments.show', $shipment)->with('status', 'Shipment created.');
    }

    /**
     * Append a new event to a manually-entered shipment.
     */
    public function addEvent(Request $request, Shipment $shipment): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:128'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $registry = app(CourierProviderRegistry::class);
        $provider = $registry->resolve($shipment->provider);
        if (! $provider instanceof ManualProvider) {
            return back()->withErrors(['status' => 'Only manual shipments accept appended events.']);
        }

        $provider->appendEvent(
            $shipment,
            $data['status'],
            $data['description'] ?? null,
            $data['location'] ?? null,
            isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : null,
        );

        return redirect()->route('shipments.show', $shipment)->with('status', 'Event recorded.');
    }

    /**
     * Headline numbers for the page header.
     *
     * @return array<string, int>
     */
    private function totals(): array
    {
        $base = Shipment::query();
        $byStatus = $base->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $totalShipments = (int) array_sum($byStatus);
        $inTransit = (int) ($byStatus[ShipmentStatus::PickedUp->value] ?? 0)
            + (int) ($byStatus[ShipmentStatus::InTransit->value] ?? 0)
            + (int) ($byStatus[ShipmentStatus::OutForDelivery->value] ?? 0);
        $delivered = (int) ($byStatus[ShipmentStatus::Delivered->value] ?? 0);
        $exceptions = (int) ($byStatus[ShipmentStatus::Exception->value] ?? 0);
        $orphaned = (int) Shipment::query()->whereNull('order_id')->count();

        return [
            'total' => $totalShipments,
            'in_transit' => $inTransit,
            'delivered' => $delivered,
            'exceptions' => $exceptions,
            'orphaned' => $orphaned,
        ];
    }
}
