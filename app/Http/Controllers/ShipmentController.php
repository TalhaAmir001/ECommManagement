<?php

namespace App\Http\Controllers;

use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider as CourierProviderModel;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\Courier\CourierProviderRegistry;
use App\Services\Courier\Exceptions\CourierException;
use App\Services\Courier\OrderAutoMatcher;
use App\Services\Courier\OrderLookup;
use App\Services\Courier\Providers\ManualProvider;
use App\Services\Courier\Providers\ShopifyFulfillmentProvider;
use App\Services\Courier\TrackingLinkResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
    public function show(Shipment $shipment, TrackingLinkResolver $resolver): View
    {
        $shipment->load(['provider', 'order.customer', 'events']);

        return view('shipments.show', [
            'shipment' => $shipment,
            'statuses' => ShipmentStatus::cases(),
            'refreshStrategy' => $resolver->strategyFor($shipment),
        ]);
    }

    /**
     * JSON typeahead used by both the shipment link form and the
     * new-shipment form. Returns the most recent matching orders
     * (capped, with enough context for the operator to verify the pick
     * before submitting).
     */
    public function lookupOrders(Request $request, OrderLookup $lookup): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:128'],
            'consignee_phone' => ['nullable', 'string', 'max:64'],
            'reference' => ['nullable', 'string', 'max:128'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));
        $phone = trim((string) ($validated['consignee_phone'] ?? ''));
        $reference = trim((string) ($validated['reference'] ?? ''));

        if ($query !== '') {
            return response()->json([
                'results' => $lookup->suggest($query),
            ]);
        }

        // The new-shipment form passes the partial fields it already has;
        // we run them through the same conservative matcher and return
        // the same shape the typeahead expects.
        if ($phone !== '' || $reference !== '') {
            return response()->json([
                'results' => $lookup->suggestForNewShipment($reference ?: null, $phone ?: null),
            ]);
        }

        return response()->json(['results' => []]);
    }

    /**
     * Generate a fresh manual-shipment tracking number on demand. The
     * JS on the new-shipment form calls this when the operator clicks
     * "Generate new", or when no tracking number has been entered and
     * the page just loaded.
     */
    public function generateTrackingNumber(): JsonResponse
    {
        return response()->json([
            'tracking_number' => $this->generateManualTrackingNumber(),
        ]);
    }

    /**
     * Manually attach (or re-link) a shipment to a local order.
     *
     * Two safety rails:
     *  - The order is looked up by primary key, not by a free-text
     *    number the operator might have mistyped. The typeahead that
     *    powers the form returns the id, not the number, so a typo
     *    simply returns "order not found" instead of silently linking
     *    to a wrong order.
     *  - If the shipment is already linked to a different order, the
     *    controller checks the `confirm` flag — the form must be
     *    re-submitted with confirm=1 to actually switch. This makes
     *    accidental switches hard.
     *
     * After success the operator is redirected back to whichever page
     * invoked the action. The detail page (`shipments.show`) is the
     * default; row-level actions on `/shipments` set a `return=index`
     * flag in the form so they land back on the list.
     */
    public function link(Request $request, Shipment $shipment, OrderLookup $lookup): RedirectResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'min:1'],
            'confirm' => ['nullable', 'boolean'],
            'return' => ['nullable', 'string', 'in:index,show'],
        ]);

        $order = $lookup->findById($data['order_id']);
        if ($order === null) {
            return back()->withErrors(['order_id' => 'That order does not exist (id '.$data['order_id'].').'])->withInput();
        }

        // Idempotent: linking to the same order is a no-op.
        if ((int) $shipment->order_id === (int) $order->id) {
            return $this->linkRedirect($request, $shipment)
                ->with('status', 'Shipment is already linked to order '.$order->number.'.');
        }

        // Switching an already-linked shipment requires explicit confirm.
        $isSwitch = $shipment->order_id !== null
            && (int) $shipment->order_id !== (int) $order->id;

        if ($isSwitch && ! $request->boolean('confirm')) {
            $previous = $shipment->order?->number ?? '(unknown)';

            return back()->withErrors([
                'order_id' => "This shipment is currently linked to {$previous}. "
                    .'Re-submit with the confirmation checked to switch it to '.$order->number.'.',
            ])->withInput();
        }

        $previousNumber = $shipment->order?->number;

        $shipment->forceFill([
            'order_id' => $order->id,
            'matched_method' => 'manual',
            'matched_at' => now(),
        ])->save();

        $message = $isSwitch && $previousNumber !== null
            ? "Shipment moved from order {$previousNumber} to {$order->number}."
            : "Shipment linked to order {$order->number}.";

        return $this->linkRedirect($request, $shipment)->with('status', $message);
    }

    /**
     * Pick the right post-link landing page based on the form's `return`
     * flag. Falls back to the shipment detail page.
     */
    private function linkRedirect(Request $request, Shipment $shipment): RedirectResponse
    {
        if ($request->input('return') === 'index') {
            return redirect()->route('shipments.index');
        }

        return redirect()->route('shipments.show', $shipment);
    }

    /**
     * Break the auto/manual link between a shipment and its order.
     *
     * Idempotent: unlinking a shipment that isn't linked is a no-op so
     * a double-click or a stale button doesn't error out.
     *
     * Row-level actions on `/shipments` pass a `return=index` flag so
     * the operator lands back on the list. Otherwise we send them to
     * the detail page.
     */
    public function unlink(Request $request, Shipment $shipment): RedirectResponse
    {
        $request->validate([
            'return' => ['nullable', 'string', 'in:index,show'],
        ]);

        if ($shipment->order_id === null) {
            return $this->unlinkRedirect($request, $shipment)
                ->with('status', 'Shipment was not linked to an order.');
        }

        $previousNumber = $shipment->order?->number;

        $shipment->forceFill([
            'order_id' => null,
            'matched_method' => null,
            'matched_at' => null,
        ])->save();

        $message = $previousNumber !== null
            ? 'Shipment unlinked from order '.$previousNumber.'.'
            : 'Link removed.';

        return $this->unlinkRedirect($request, $shipment)->with('status', $message);
    }

    /**
     * Pick the right post-unlink landing page based on the form's `return`
     * flag. Falls back to the shipment detail page.
     */
    private function unlinkRedirect(Request $request, Shipment $shipment): RedirectResponse
    {
        if ($request->input('return') === 'index') {
            return redirect()->route('shipments.index');
        }

        return redirect()->route('shipments.show', $shipment);
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
     * Pull fresh data for a single shipment.
     *
     * Two paths, picked automatically:
     *  - Shopify-originated shipments with a tracking_url go through the
     *    TrackingLinkResolver, which follows the courier's own link.
     *  - Everything else calls the provider's structured getShipment()
     *    (Leopards, TCS, manual, etc.) — the same path as before.
     */
    public function refresh(Shipment $shipment, CourierProviderRegistry $registry, TrackingLinkResolver $resolver): RedirectResponse
    {
        // Shopify-originated shipments have a tracking_url from Shopify's
        // trackingInfo block. That's the courier service hyperlink we use
        // to ask the courier for a real delivery status.
        if ($shipment->provider->driver_class === ShopifyFulfillmentProvider::class
            && trim((string) $shipment->tracking_url) !== '') {
            return $this->refreshFromTrackingLink($shipment, $resolver);
        }

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
            ShipmentEvent::query()->firstOrCreate(
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
     * Refresh a Shopify-originated shipment by following its tracking URL
     * through the TrackingLinkResolver.
     */
    private function refreshFromTrackingLink(Shipment $shipment, TrackingLinkResolver $resolver): RedirectResponse
    {
        try {
            $raw = $resolver->resolve($shipment);
        } catch (CourierException $e) {
            return back()->withErrors(['status' => 'Courier link refresh failed: '.$e->getMessage()]);
        }

        if ($raw === null) {
            return back()->withErrors(['status' => 'This shipment has no tracking URL to follow.']);
        }

        $shipment->forceFill([
            'status' => $raw->status->value,
            'status_detail' => $raw->statusDetail,
            'shipped_at' => $raw->shippedAt ?? $shipment->shipped_at,
            'delivered_at' => $raw->status === ShipmentStatus::Delivered
                ? ($raw->deliveredAt ?? $shipment->delivered_at ?? now())
                : $shipment->delivered_at,
            'last_event_at' => $raw->lastEventAt ?? $raw->shippedAt ?? $shipment->last_event_at ?? now(),
            'raw_payload' => $raw->raw,
        ])->save();

        $appended = 0;
        foreach ($resolver->listEvents($shipment) as $event) {
            $created = ShipmentEvent::query()->firstOrCreate(
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
            if ($created->wasRecentlyCreated) {
                $appended++;
            }
        }

        $source = $resolver->strategyFor($shipment);

        return redirect()->route('shipments.show', $shipment)->with(
            'status',
            "Refreshed from courier tracking link ({$source}); {$appended} new event(s) appended."
        );
    }

    /**
     * Create a shipment from manual entry. The manual provider always
     * exists, so this is the day-one happy path.
     *
     * The form accepts an optional `order_id` to pre-link the new
     * shipment to a specific order. The id is validated against the
     * real order by the OrderLookup — a tampered or stale id simply
     * becomes "no link", not "wrong link".
     */
    public function store(Request $request, OrderLookup $lookup): RedirectResponse
    {
        $data = $request->validate([
            'tracking_number' => ['nullable', 'string', 'max:128'],
            'reference' => ['nullable', 'string', 'max:128'],
            'order_id' => ['nullable', 'integer', 'min:1'],
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

        // Resolve the order BEFORE we create the shipment so a bad id
        // becomes a validation error, not a phantom-link row.
        $order = $lookup->findById($data['order_id'] ?? null);

        // Auto-generate a tracking number when the operator leaves the
        // field blank. The "MNL-" prefix makes manual entries visually
        // distinct from courier-issued numbers at a glance. The loop
        // protects against the (very unlikely) case of an 8-char random
        // collision; falling back to a hex value guarantees uniqueness.
        $trackingInput = trim((string) ($data['tracking_number'] ?? ''));
        $trackingNumber = $trackingInput !== ''
            ? $trackingInput
            : $this->generateManualTrackingNumber();

        // Merge the form input with the metadata we set ourselves.
        // The right-hand side wins, so any metadata key in the form is
        // overridden by our server-controlled values (status, currency,
        // courier_provider_id, external_id, timestamps).
        $attributes = array_merge($data, [
            'courier_provider_id' => $manual->id,
            'external_id' => 'MANUAL-'.strtoupper(bin2hex(random_bytes(4))),
            'tracking_number' => $trackingNumber,
            'order_id' => $order?->id,
            'status' => $data['status'] ?? ShipmentStatus::Created->value,
            'currency' => $data['currency'] ?? config('couriers.default_currency', 'PKR'),
            'shipped_at' => now(),
            'last_event_at' => now(),
        ]);

        $shipment = Shipment::query()->create($attributes);

        // Record the initial event so the timeline isn't empty.
        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'occurred_at' => now(),
            'status' => $shipment->status,
            'description' => 'Shipment created',
        ]);

        // If the operator didn't pick an order explicitly, the auto-
        // matcher gets one shot at linking by reference → phone.
        if ($shipment->order_id === null) {
            app(OrderAutoMatcher::class)->match($shipment);
        } else {
            // The operator picked this order by hand — mark it manual so
            // a future auto-match doesn't quietly override it.
            $shipment->forceFill([
                'matched_method' => 'manual',
                'matched_at' => now(),
            ])->save();
        }

        $message = $order !== null
            ? 'Shipment created and linked to order '.$order->number.'.'
            : 'Shipment created.';

        return redirect()->route('shipments.show', $shipment)->with('status', $message);
    }

    /**
     * Update the status of a shipment from the operator's POV.
     *
     * Two paths, picked by the shipment's provider type:
     *  - Manual shipments: append a new event with the requested status,
     *    description and location (see ManualProvider::appendEvent). The
     *    timeline gains a real entry.
     *  - Courier-driven shipments: pull a fresh status from the courier
     *    API via `refresh()`. The form's status / description / location
     *    fields are ignored — the courier is the source of truth for
     *    non-manual shipments.
     *
     * After success the operator is redirected back to whichever page
     * invoked the action. The detail page (`shipments.show`) is the
     * default; row-level actions on `/shipments` set a `return=index`
     * flag in the form so they land back on the list.
     */
    public function addEvent(Request $request, Shipment $shipment): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:128'],
            'occurred_at' => ['nullable', 'date'],
            'return' => ['nullable', 'string', 'in:index,show'],
        ]);

        $registry = app(CourierProviderRegistry::class);
        $provider = $registry->resolve($shipment->provider);

        // Non-manual providers: defer to the courier's structured API.
        // The status field from the form is informational only — the
        // courier decides what the actual current state is.
        if (! $provider instanceof ManualProvider) {
            $resolver = app(TrackingLinkResolver::class);
            $refreshResult = $this->refresh($shipment, $registry, $resolver);

            // `refresh()` always returns a RedirectResponse aimed at
            // `shipments.show`. If the operator came from the index page,
            // we re-aim it at the index so they keep their context.
            if ($request->input('return') === 'index') {
                return redirect()->route('shipments.index')->with('status', session('status') ?? 'Refreshed shipment status.');
            }

            return $refreshResult;
        }

        $provider->appendEvent(
            $shipment,
            $data['status'],
            $data['description'] ?? null,
            $data['location'] ?? null,
            isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : null,
        );

        $statusEnum = ShipmentStatus::tryFrom((string) $data['status']);
        $label = $statusEnum?->label() ?? $data['status'];

        $destination = $request->input('return') === 'index'
            ? redirect()->route('shipments.index')
            : redirect()->route('shipments.show', $shipment);

        return $destination->with('status', "Status updated to \"{$label}\".");
    }

    /**
     * Backfill / correct the money fields on a single shipment.
     *
     * Courier-API and Shopify rows often arrive with a null cost (the
     * courier hasn't billed yet) or a COD amount the operator wants to
     * confirm. The Audit report reads these columns, so every shipment
     * should be editable regardless of which source created it.
     *
     * Empty strings arrive as null (ConvertEmptyStringsToNull), which
     * clears the figure. Leaving currency blank keeps the existing value.
     */
    public function updateMoney(Request $request, Shipment $shipment): RedirectResponse
    {
        $data = $request->validate([
            'cod_amount' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $shipment->forceFill([
            'cod_amount' => $data['cod_amount'] ?? null,
            'cost' => $data['cost'] ?? null,
            'currency' => $data['currency'] !== null
                ? strtoupper($data['currency'])
                : ($shipment->currency ?: config('couriers.default_currency', 'PKR')),
        ])->save();

        return redirect()->route('shipments.show', $shipment)
            ->with('status', 'Shipment pricing and costs updated.');
    }

    /**
     * Generate a unique tracking number for a manual shipment.
     *
     * Format: `MNL-XXXXXXXX` where X is drawn from an alphabet that
     * omits the easily-confused O/0/I/1/L characters. The loop is
     * paranoid about uniqueness — 36^8 ≈ 2.8 trillion combinations
     * means a collision is essentially impossible — but the DB check
     * is cheap insurance.
     */
    public function generateManualTrackingNumber(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $random = '';
            for ($i = 0; $i < 8; $i++) {
                $random .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $candidate = 'MNL-'.$random;
            if (! Shipment::query()->where('tracking_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        // Should be unreachable in practice, but the hex fallback
        // guarantees we always return *something* unique.
        return 'MNL-'.strtoupper(bin2hex(random_bytes(4)));
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
