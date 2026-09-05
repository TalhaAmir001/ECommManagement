<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'number',
        'status',
        'financial_status',
        'fulfillment_status',
        'shipping_name',
        'shipping_address1',
        'shipping_address2',
        'shipping_city',
        'shipping_province',
        'shipping_zip',
        'shipping_country',
        'shipping_phone',
        'courier_provider_id',
        'total',
        'created_at',
        'shopify_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
        ];
    }

    /**
     * An order belongs to a customer.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * An order has many items.
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Courier shipments linked to this order.
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * The courier this order is pre-assigned to ship via. Null when no
     * specific courier has been chosen (e.g. waiting for a fulfillment).
     */
    public function assignedProvider(): BelongsTo
    {
        return $this->belongsTo(CourierProvider::class, 'courier_provider_id');
    }

    /**
     * Total weight (kg) of everything on the order, computed from the
     * products behind each line item (quantity × product weight). Null when
     * none of the order's items carry a product weight yet.
     *
     * Shipments linked to this order inherit this figure as their default
     * weight (see ShipmentObserver), which the DeliveryRateCalculator then
     * prices against the courier's zone/weight rate card.
     */
    public function totalWeightKg(): ?float
    {
        $total = 0.0;

        foreach ($this->items as $item) {
            $weightKg = $item->product?->weight_kg;
            if ($weightKg === null) {
                continue;
            }

            $total += (float) $weightKg * (int) ($item->quantity ?: 1);
        }

        // A zero total means nothing on the order actually carries a real
        // weight yet — Shopify reports unset product weights as 0. Treat
        // that as "unknown" rather than a genuine 0 kg parcel, so callers
        // keep deriving instead of settling on a meaningless zero.
        return $total > 0 ? round($total, 3) : null;
    }

    /**
     * Total number of units on the order — the sum of every line item's
     * quantity (e.g. 2× Shirt + 1× Cap → 3). Used as the default "pieces"
     * figure when a shipment for this order is created from the UI.
     */
    public function totalItemQuantity(): int
    {
        return (int) $this->items->sum('quantity');
    }

    /**
     * The numeric Shopify resource id of this order, or null when there is
     * none.
     *
     * `orders.shopify_id` stores the GraphQL global id
     * ("gid://shopify/Order/123456") for orders pulled from the store. The
     * Shopify REST API and admin UI address an order by its trailing
     * numeric resource id, so the "gid://shopify/Order/" prefix is stripped
     * here. Accepts a bare numeric id too, and returns null for anything
     * that doesn't end in digits (or for locally-created orders with no
     * shopify_id).
     */
    public function shopifyNumericId(): ?string
    {
        $shopifyId = $this->shopify_id;

        if ($shopifyId === null || $shopifyId === '') {
            return null;
        }

        $numericId = str_contains($shopifyId, '/')
            ? substr((string) strrchr($shopifyId, '/'), 1)
            : $shopifyId;

        return $numericId !== '' && ctype_digit($numericId) ? $numericId : null;
    }

    /**
     * The Shopify admin detail-page URL for this order, or null when there
     * is nothing to link to. Locally-created orders (no shopify_id) and
     * stores without a configured shop handle yield null so callers never
     * render a broken link.
     */
    public function shopifyAdminUrl(): ?string
    {
        $numericId = $this->shopifyNumericId();

        if ($numericId === null) {
            return null;
        }

        $shop = config('shopify.shop');

        if (empty($shop)) {
            return null;
        }

        return 'https://admin.shopify.com/store/'.$shop.'/orders/'.$numericId;
    }
}
