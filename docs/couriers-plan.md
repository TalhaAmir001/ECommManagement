# Multi-courier provider integration — Plan

> Phase 1 = read-only delivery data. Phase 2 = full control (book, cancel, track, etc).
> Goal of this design: phase 2 is **additive**, not a rewrite.

---

## 1. Headline shape

| Concern | Decision |
|---|---|
| Adding a new courier | Drop in one PHP class. No migration, no controller change, no UI rebuild. |
| Phase-1 → Phase-2 | Add `createLabel()` to the interface; flip a capability flag in config. Existing read code untouched. |
| Provider with no API | A `ManualProvider` ships day one — admin pastes tracking numbers. Never blocked by an integration. |
| Each provider is different | A `Capability` enum drives both backend guards and UI affordances. Buttons hidden when not supported. |
| Real-time vs poll | Phase 1 = poll. Phase 2 = add a generic webhook receiver per provider that supports it. |
| Where do I see deliveries? | New "Shipments" sidebar item, plus a tracking column on the existing Orders page. |
| Auto-match to local order | Yes — best-effort on insert, with a manual re-link button. |

---

## 2. Architecture

```
                          ┌──────────────────────────┐
   sidebar "Shipments" ──▶│  ShipmentController      │
                          └──────────┬───────────────┘
                                     │
   ┌──────────────────────┐    ┌──────▼───────────────────┐    ┌──────────────────────┐
   │ courier_providers    │    │ CourierProviderRegistry  │───▶│ CourierProvider      │
   │  (config table)      │───▶│  resolves key → driver   │    │  (interface)         │
   └──────────────────────┘    └──────────────────────────┘    │                      │
                                                                 │  read*  (P1)         │
                                                                 │  createLabel (P2)    │
                                                                 │  cancel    (P2)      │
                                                                 └──────┬───────────────┘
                                                                        │
                                                  ┌─────────────────────┼──────────────────┐
                                                  ▼                     ▼                  ▼
                                            LeopardsProvider     TcsProvider         ManualProvider
                                            (REST API)           (REST API, stub)    (admin paste)
```

The interface is the contract. Each provider declares its own capabilities. The registry and models don't know or care who's behind the curtain.

---

## 3. Data model

### `courier_providers` (config table — runtime source of truth)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `key` | string unique | `leopards`, `tcs`, `manual`, `mnp`, … |
| `display_name` | string | "Leopards Courier" |
| `driver_class` | string | FQCN of the `CourierProvider` implementation |
| `enabled` | bool | soft kill-switch without deleting config |
| `credentials` | encryptedJson | API keys, account id, etc. — encrypted at rest |
| `settings` | json | Provider-specific knobs (default pickup address, COD enabled, etc.) |
| `capabilities` | json array | `["read_shipments", "read_events", "create_label", …]` — drives UI |
| `poll_interval_minutes` | int | Provider-declared cadence; scheduler respects it |
| `last_synced_at` | timestamp nullable | |
| `last_sync_status` | enum (`success`,`failed`) | |
| `last_sync_error` | text nullable | short error string for the admin UI |

> The credentials are stored encrypted (Laravel's `encrypted` cast). The provider table is **not** a per-store, per-tenant model — it's "one row per provider integration in this app". If you ever go multi-tenant, this table gets a `store_id` FK. For now, keep it single-tenant.

### `shipments`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `courier_provider_id` | fk | |
| `external_id` | string | The provider's own id for the shipment (unique per provider) |
| `tracking_number` | string indexed | |
| `reference` | string nullable | Provider-side reference; often = our order `number` |
| `status` | enum | `created`, `picked_up`, `in_transit`, `out_for_delivery`, `delivered`, `exception`, `returned`, `cancelled`, `unknown` |
| `status_detail` | text nullable | Free text the courier returns |
| `shipped_at`, `delivered_at`, `last_event_at` | timestamps nullable | |
| `consignor_*` | strings | Name, address, city, phone |
| `consignee_*` | strings | Name, address, city, phone |
| `weight_kg`, `pieces` | decimal/int nullable | Parcel dims |
| `cod_amount` | decimal nullable | Cash-on-delivery amount (PK context) |
| `cost` | decimal nullable | What we paid the courier (feeds Audit page) |
| `currency` | string(3) default `PKR` | |
| `raw_payload` | json | Original provider response — debugging + re-normalization |
| `order_id` | fk nullable indexed | Local order link, set by auto-match or admin |
| `matched_at` | timestamp nullable | |
| `matched_method` | string nullable | `phone`, `reference`, `manual` |

Indexes: `unique(courier_provider_id, external_id)`, `tracking_number`, `order_id`.

### `shipment_events` (append-only)

| Column | Type |
|---|---|
| `id` | bigint pk |
| `shipment_id` | fk indexed |
| `occurred_at` | timestamp |
| `status` | enum (same vocabulary as above) |
| `location` | string nullable |
| `description` | text nullable |
| `raw_payload` | json nullable |

`unique(shipment_id, occurred_at, location)` to keep the append idempotent when the courier repeats an event.

---

## 4. The `CourierProvider` contract

```php
interface CourierProvider
{
    /** Unique key used in config and DB (e.g. "leopards"). */
    public function key(): string;

    /** Human name for UI. */
    public function displayName(): string;

    /** What this provider can do. Used by UI + backend guards. */
    public function capabilities(): array; // list<Capability>

    /** Quick "yes/no" check. */
    public function supports(Capability $cap): bool;

    // ─── Phase 1: read ────────────────────────────────────────────
    /** Stream shipments updated since the given cursor. */
    public function listShipments(?Carbon $since = null): LazyCollection;

    /** Stream tracking events for one shipment, optionally since a cursor. */
    public function listEvents(string $externalId, ?Carbon $since = null): LazyCollection;

    /** Fetch a single shipment on-demand (for the detail page "refresh" button). */
    public function getShipment(string $externalId): ?RawShipment;

    // ─── Phase 2: write ───────────────────────────────────────────
    // All of these throw CapabilityUnsupportedException unless the
    // provider declared the matching capability in `capabilities()`.
    public function createLabel(CourierLabelRequest $req): RawShipment;
    public function cancelShipment(string $externalId, ?string $reason = null): bool;
    public function schedulePickup(PickupRequest $req): RawPickupConfirmation;
    public function rateQuote(RateQuoteRequest $req): array; // list<RateQuote>
}
```

**Capability enum** (string-backed so it survives cache/JSON):

```
READ_SHIPMENTS, READ_EVENTS, WEBHOOKS,
CREATE_LABEL, CANCEL_SHIPMENT, SCHEDULE_PICKUP,
RATE_QUOTE, COD_SUPPORT
```

**Three concrete providers ship in phase 1:**

| Class | Capabilities | Notes |
|---|---|---|
| `LeopardsProvider` | READ_SHIPMENTS, READ_EVENTS, CREATE_LABEL (P2), CANCEL_SHIPMENT (P2), COD_SUPPORT | Strong PK API, full read+write in phase 2 |
| `TcsProvider` | READ_SHIPMENTS, READ_EVENTS | Stub the read endpoints; write methods throw until creds are in |
| `ManualProvider` | READ_SHIPMENTS, READ_EVENTS, COD_SUPPORT | Admin-paste only. Always enabled. No external calls. |

A `FakeProvider` is also exposed for tests (`CourierProvider::fake('leopards')`).

---

## 5. Normalization

Each provider returns whatever shape its API uses. A `Normalizer` (private to the provider) maps that to our DTOs:

```php
final class RawShipment {
    public function __construct(
        public string $externalId,
        public string $trackingNumber,
        public ?string $reference,
        public ShipmentStatus $status,
        public ?string $statusDetail,
        public ?Carbon $shippedAt,
        public ?Carbon $deliveredAt,
        public ?Carbon $lastEventAt,
        public ?Address $consignor,
        public ?Address $consignee,
        public ?float $weightKg,
        public ?int $pieces,
        public ?float $codAmount,
        public ?float $cost,
        public string $currency,
        public array $raw,
    ) {}
}
```

The `shipments.raw_payload` column stores the original `array` so we can re-normalize later if our vocabulary changes.

---

## 6. Sync engine

```
   php artisan couriers:sync                  # all enabled providers
   php artisan couriers:sync --provider=leopards
   php artisan couriers:sync --since=1h        # override cursor
```

1. `CourierSyncCommand` walks every enabled row in `courier_providers`.
2. For each, dispatches `SyncCourierProviderJob` (queueable) on the `couriers` queue.
3. The job calls `$provider->listShipments($provider->last_synced_at)`, normalizes, and **upserts**:
   - On insert → `OrderAutoMatcher::match()` tries to link to a local order.
   - Always → diff events against `shipment_events` and append only new ones.
4. Updates `courier_providers.last_synced_at`, `last_sync_status`, `last_sync_error`.

**Scheduler** (`routes/console.php` or `bootstrap/app.php`):

```php
Schedule::command('couriers:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
```

Each provider can also override its own cadence via `poll_interval_minutes` — the job reads that and bails out early if the last sync was too recent.

> No background worker assumed to be running in local dev. The command works synchronously too. In prod, set `QUEUE_CONNECTION=redis` (or whatever) and run `php artisan queue:work`.

---

## 7. Order auto-matching

A `OrderAutoMatcher` service runs on every new `shipments` row:

1. **Exact** match on `tracking_number` against any order that has the same number stamped on it (we'll store a denormalized `tracking_number` on `orders` once we know an order shipped).
2. **Reference** match on `shipments.reference` against `orders.number` (this is the dominant case in phase 2, where we book the shipment with our order number as `reference`).
3. **Fuzzy** match on `consignee_phone` last-7-digits → `customers.phone` + recent `orders`.
4. **No match** → row stays orphaned; admin can manually link from the shipment detail page.

The matcher's job is idempotent: re-running it on the same shipment never re-links to a different order.

---

## 8. UI

**New "Shipments" sidebar entry** under the existing `Insights` group (next to Reports). Single page with:

- **Provider health row** — per enabled provider: name, status, last synced, last error, shipment count today.
- **Filters** — provider, status, date range, search by tracking #/order #/phone.
- **Shipments table** — tracking #, provider, status pill, consignee, last event, last update.
- **Shipment detail** (slide-over or new route) — full event timeline, raw payload (collapsible), order link, "Re-match" / "Unlink" actions, "Refresh from provider" button.

**Orders page** (existing, gets one new column) — `Shipment` cell shows: tracking number + carrier badge + status pill. If a shipment exists but isn't auto-matched, the cell is still filled; if no shipment, it shows "Not shipped".

**Audit page** (the one we just built) — no change in phase 1. Phase 2: surface `SUM(shipments.cost)` in the date range as a shipping-cost line so true net profit deducts courier fees.

---

## 9. Files to add / change (Phase 1)

| Path | Purpose |
|---|---|
| `database/migrations/2026_08_21_000001_create_courier_providers_table.php` | providers table |
| `database/migrations/2026_08_21_000002_create_shipments_table.php` | shipments + indexes |
| `database/migrations/2026_08_21_000003_create_shipment_events_table.php` | events |
| `database/seeders/CourierProvidersSeeder.php` | seeds `manual` always; `leopards` / `tcs` if env creds present |
| `app/Models/CourierProvider.php` | config-row model with `encrypted` cast on `credentials` |
| `app/Models/Shipment.php` | model + relations + `refresh()` method |
| `app/Models/ShipmentEvent.php` | append-only model |
| `app/Enums/Courier/Capability.php` | string-backed enum |
| `app/Enums/Courier/ShipmentStatus.php` | string-backed enum |
| `app/Services/Courier/CourierProvider.php` | the interface |
| `app/Services/Courier/Exceptions/CapabilityUnsupportedException.php` | |
| `app/Services/Courier/CourierProviderRegistry.php` | key → instance resolver |
| `app/Services/Courier/Normalization/RawShipment.php` | DTO |
| `app/Services/Courier/Normalization/RawEvent.php` | DTO |
| `app/Services/Courier/OrderAutoMatcher.php` | match logic |
| `app/Services/Courier/Providers/LeopardsProvider.php` | first real integration |
| `app/Services/Courier/Providers/TcsProvider.php` | structure, stubbed methods |
| `app/Services/Courier/Providers/ManualProvider.php` | admin-paste fallback |
| `app/Services/Courier/Providers/FakeProvider.php` | test double |
| `app/Console/Commands/CourierSync.php` | artisan entry point |
| `app/Jobs/SyncCourierProviderJob.php` | queueable per-provider sync |
| `app/Http/Controllers/ShipmentController.php` | index + show + manual-link |
| `app/Http/Controllers/CourierProviderController.php` | admin toggle / re-sync |
| `resources/views/shipments/index.blade.php` | list page |
| `resources/views/shipments/_row.blade.php` | table row partial |
| `resources/views/shipments/show.blade.php` | detail + event timeline |
| `resources/views/components/dashboard/courier-status-pill.blade.php` | status pill |
| `resources/views/components/dashboard/courier-icon.blade.php` | small badge per provider |
| `resources/views/orders/_row.blade.php` (edit) | add the new "Shipment" cell |
| `resources/views/components/dashboard/sidebar.blade.php` (edit) | add Shipments item |
| `routes/web.php` (edit) | new routes |
| `routes/console.php` (edit) | schedule |
| `config/couriers.php` | defaults (poll intervals, enabled flags) |
| `bootstrap/app.php` (edit) | schedule binding if not already there |
| `tests/Feature/Courier/LeopardsProviderTest.php` | HTTP-fake read tests |
| `tests/Feature/Courier/ManualProviderTest.php` | unit |
| `tests/Feature/Courier/OrderAutoMatcherTest.php` | matching rules |
| `tests/Feature/Courier/ShipmentControllerTest.php` | list + detail + manual link |

Roughly 30 new files, 4 edits. No changes to existing models, controllers, or the Audit page.

---

## 10. Phase-2 hooks (already in place after Phase 1)

- The interface declares `createLabel`, `cancelShipment`, `schedulePickup`, `rateQuote`. Throwing `CapabilityUnsupportedException` is a valid implementation.
- The `Capability` enum is the single source of truth: a button in the UI checks `$provider->supports(Capability::CreateLabel)` before showing. The controller checks the same before calling.
- `raw_payload` is preserved forever, so we can re-derive any field if our schema evolves.
- `courier_providers` is a config table, so flipping a provider from "read-only" to "read+write" is one row update, not a deploy.
- The `OrderAutoMatcher` already works on `reference` — when phase 2 books a shipment with our order number as the reference, auto-matching is exact.
- A `pending_write_operations` table is the natural addition when phase 2 starts (intent log for retries).

---

## 11. Three decisions I need from you before I build

1. **Which couriers first?** My default: Leopards (full read), TCS (stub), Manual (always). Add DHL/FedEx if international; M&P, BlueEx, etc. as you need them.
2. **Auto-match to orders?** My default: best-effort (exact tracking → reference → fuzzy phone), with a manual re-link button on the shipment detail. Unmatched rows still visible, just orphaned.
3. **Capture courier cost and COD?** My default: yes from day one. Both are first-class columns. The Audit page can later surface shipping cost to give a true net-profit number. Skipping them now means a phase-2 migration.

If those three are right, the plan is ready to build. If any of them should change, say which and I'll revise before writing a line of code.
