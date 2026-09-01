# Plan — Associating Orders with Shipment Providers

> Goal: every order in this app is trackable, and the operations team can
> ship an order (assign provider, paste tracking, fire off a label) without
> leaving the order page.
>
> All changes below are additive on top of the courier system we already
> shipped. The existing shipment-first model stays; we add an order-first
> layer on top.

---

## 1. What's already there

| | |
|---|---|
| `shipments.order_id` | nullable FK to orders. A shipment knows its order. |
| `shipments.courier_provider_id` | FK. A shipment knows its provider. |
| `OrderAutoMatcher` | auto-matches incoming shipments to orders by `reference` → phone tail. |
| `Order.shipments()` | hasMany relation, eager-loaded in the Orders index. |
| Orders page "Shipment" column | shows the latest shipment's tracking # + status pill, or "Not shipped". |
| Shipment detail page | full event timeline, manual-link / unlink / re-match. |
| Shipment sync engine | polls enabled providers every 5 min, upserts + appends events. |

**So the link itself exists. The user already sees tracking on the Orders page.** What is missing is the operational flow and the early-binding layer.

---

## 2. The actual gap

The current model is **shipment-first**: a shipment is created (manually or by a sync), and it gets linked to an order afterwards. The order never decides *which courier* will handle it — it only learns once a shipment links back to it.

Concretely, today you cannot:

1. **Assign a courier to an order before shipping.** No `orders.courier_provider_id` column. The order has no opinion about who will move it.
2. **Trigger shipping from the order page.** To create a shipment today you go to `/shipments → New shipment`. There's no "Ship this order" button.
3. **Paste a tracking number for a specific order directly.** The current flow is "create shipment → auto-match by reference." The reverse — "I'm processing #1001, here's its tracking #" — is not a first-class action.
4. **See all shipments for an order at a glance.** The Orders page only eager-loads the *latest* shipment. A two-parcel order shows only the most recent box.
5. **Have the order's fulfillment status react to a delivered shipment.** `orders.fulfillment_status` is still denormalized from Shopify. A manually-entered delivery doesn't propagate up.
6. **Fire a Create Label from the order page.** Phase-2 action slots exist in the interface but no order-page trigger for them.

---

## 3. The plan

Six small additions, ordered so each one is independently shippable and useful.

### 3.1 Add `orders.courier_provider_id` (nullable FK)

One column, nullable, `nullOnDelete`. This is the *early binding* — "this order will ship via Leopards" — that today's schema can't represent.

```php
// migration
$table->foreignId('courier_provider_id')
    ->nullable()
    ->after('fulfillment_status')
    ->constrained('courier_providers')
    ->nullOnDelete();
```

The Order model gains:

```php
public function assignedProvider(): BelongsTo
{
    return $this->belongsTo(CourierProvider::class, 'courier_provider_id');
}
```

### 3.2 New "Shipment" cell on the Orders page (replaces the current single-line cell)

The current cell shows one tracking #. The new cell shows:

- **No provider assigned, no shipments:** `Not shipped` + an "Assign & ship" link that opens a small popover.
- **Provider assigned, no shipments yet:** the provider name + a "Paste tracking #" inline form.
- **Has shipments:** a compact list (status pill + tracking # per shipment, with a "View all" link if more than 3), plus a "Refresh from courier" link.

This is one table-row cell, no new route. The "paste tracking" form is a tiny inline form that POSTs to the existing `shipments.store` with `order_number` auto-filled.

### 3.3 New `attach` action: link an existing shipment to an order

Already exposed on the shipment detail page (the `link` route). The order page just needs a way to *discover* an existing shipment and link it. Add a "Find existing shipment" form on the order page: type a tracking #, the controller looks it up by `(courier_provider_id, tracking_number)`, and links it via the same `link` route.

### 3.4 "Add tracking number" quick action on the order

A direct path: pick a provider (or use the assigned one) → enter tracking # → POST creates a `ManualProvider` shipment with `order_id` set, runs the auto-matcher (no-op in this case because reference = order number), and redirects to the new shipment's detail page.

This is the "operations team" happy path. It works without a courier API and without leaving the order.

### 3.5 Fulfillment propagation observer

When a shipment's status moves to `delivered`:

1. If the shipment is linked to an order and the order's `fulfillment_status` is not already `FULFILLED`, set it to `FULFILLED`.
2. If all of an order's shipments are in a terminal state (`delivered`, `returned`, `cancelled`), sync the order's `status` accordingly.

Implemented as a single Eloquent observer on `Shipment`. No UI change.

### 3.6 Phase-2 hooks on the order page

Already declared in the `CourierProvider` interface. Once a provider's capabilities include `CreateLabel`, the order page shows a "Book shipment" button next to the assigned provider. The button only renders when `$provider->supports(Capability::CreateLabel)` is true. No new schema; just one new controller method + one route.

---

## 4. Schema diff

Exactly one migration:

```sql
ALTER TABLE orders
    ADD COLUMN courier_provider_id BIGINT UNSIGNED NULL
        AFTER fulfillment_status,
    ADD CONSTRAINT orders_courier_provider_id_foreign
        FOREIGN KEY (courier_provider_id) REFERENCES courier_providers(id)
        ON DELETE SET NULL;
```

No other schema changes. The shipments table already has everything needed.

---

## 5. Files to touch

| File | Change |
|---|---|
| `database/migrations/2026_08_25_000001_add_courier_provider_id_to_orders_table.php` | new migration |
| `app/Models/Order.php` | add `courier_provider_id` to `$fillable`, add `assignedProvider()` relation |
| `app/Observers/ShipmentObserver.php` (new) | observe saved shipment, propagate `delivered` to order's `fulfillment_status` |
| `app/Providers/AppServiceProvider.php` | register the observer on `Shipment::class` |
| `app/Http/Controllers/OrderController.php` | eager-load `assignedProvider` and `shipments.provider` (drop the `->limit(1)`) |
| `app/Http/Controllers/ShipmentController.php` | add `attachToOrder` and `createForOrder` methods; accept `order_id` in `store` |
| `resources/views/orders/_row.blade.php` | expand the Shipment cell to the new shape |
| `resources/views/orders/index.blade.php` | new "Has shipment" / "No shipment" / "Provider" filters in the filter popover |
| `resources/views/orders/show.blade.php` (new) | optional order detail page (if you want it) — the row cell is enough for now |
| `routes/web.php` | add `POST /orders/{order}/shipments` → `shipments.store` with order binding; `POST /orders/{order}/shipments/attach` for find-and-link |
| `tests/Feature/OrderShipmentAssociationTest.php` (new) | 5–6 tests: assign provider, paste tracking, multi-shipment, propagation, idempotency |
| `docs/order-courier-association-plan.md` | this file |

Roughly 1 new migration, 1 new observer, 1 new test, 4–5 edits, 1 new route group.

---

## 6. What this plan does NOT do

- **Does not replace the shipment-first model.** The auto-matcher stays. Inbound data from any courier sync still flows through it.
- **Does not enable phase-2 actions for non-capable providers.** A "Book shipment" button only renders for providers that advertise `Capability::CreateLabel`. The current Manual / Leopards / TCS providers don't yet.
- **Does not redesign the Shipments page.** It stays the operations console for tracking-event work.
- **Does not touch the Audit page.** True net profit can later subtract `SUM(shipments.cost)` for the date range; that's a separate, smaller change.

---

## 7. Two things I need from you before I start

1. **Multi-parcel orders.** Do real orders sometimes ship in more than one box (multiple shipments per order)? If yes, I'll also add `shipments.parent_shipment_id` (nullable self-FK) for grouping. If no, the existing one-to-many is enough.

2. **Fulfillment propagation.** Do you want a delivered shipment to automatically mark the order as `FULFILLED`? It's the most useful default, but if you'd rather keep `fulfillment_status` as a Shopify-only signal, we skip the observer. I lean strongly toward yes — the data is more honest.

If both are "yes (default)", the plan is ready to build. Tell me which and I'll start.
