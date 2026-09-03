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
}
