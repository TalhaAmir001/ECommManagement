<?php

namespace App\Models;

use App\Enums\Courier\ShipmentStatus;
use App\Services\Courier\DeliveryRateCalculator;
use Database\Factories\ShipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $courier_provider_id
 * @property string $external_id
 * @property string $tracking_number
 * @property string|null $reference
 * @property ShipmentStatus $status
 * @property string|null $status_detail
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $last_event_at
 * @property int|null $order_id
 * @property string|null $matched_method
 * @property Carbon|null $matched_at
 * @property string|null $consignee_email
 */
class Shipment extends Model
{
    /** @use HasFactory<ShipmentFactory> */
    use HasFactory;

    protected $fillable = [
        'courier_provider_id',
        'external_id',
        'tracking_number',
        'carrier_name',
        'tracking_url',
        'reference',
        'status',
        'status_detail',
        'shipped_at',
        'delivered_at',
        'last_event_at',
        'consignor_name',
        'consignor_phone',
        'consignor_address',
        'consignor_city',
        'consignee_name',
        'consignee_phone',
        'consignee_email',
        'consignee_address',
        'consignee_city',
        'weight_kg',
        'pieces',
        'cod_amount',
        'shipping_charged',
        'cost',
        'currency',
        'raw_payload',
        'order_id',
        'matched_at',
        'matched_method',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'last_event_at' => 'datetime',
            'weight_kg' => 'decimal:3',
            'cod_amount' => 'decimal:2',
            'shipping_charged' => 'decimal:2',
            'cost' => 'decimal:2',
            'raw_payload' => 'array',
            'matched_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CourierProvider::class, 'courier_provider_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('occurred_at');
    }

    /**
     * The weight figure cost estimates should use: the actual recorded
     * courier weight when present, otherwise the order-derived weight.
     * This is what feeds the rate-card lookup.
     */
    public function effectiveWeightKg(): ?float
    {
        if ($this->weight_kg !== null && (float) $this->weight_kg > 0) {
            return (float) $this->weight_kg;
        }

        return $this->derivedWeightKg();
    }

    /**
     * Weight derived from the linked order's line items
     * (quantity × product weight). Null when the shipment has no order, or
     * when none of the order's products carry a weight yet.
     */
    public function derivedWeightKg(): ?float
    {
        return $this->order?->totalWeightKg();
    }

    /**
     * The cost figure reports should use: the actual recorded courier cost
     * when present, otherwise the provider's rate card estimate. This is
     * what the Audit's courier-cost line aggregates.
     */
    public function effectiveCost(): ?float
    {
        if ($this->cost !== null) {
            return (float) $this->cost;
        }

        return $this->estimatedCost();
    }

    /**
     * Rate-card estimate only (ignores the recorded cost). Null when the
     * provider has no matching zone/weight-band rate.
     */
    public function estimatedCost(): ?float
    {
        return app(DeliveryRateCalculator::class)->estimateForShipment($this);
    }

    /**
     * Whether this shipment's effective cost came from a rate card rather
     * than a real billed amount.
     */
    public function costIsEstimated(): bool
    {
        return $this->cost === null && $this->estimatedCost() !== null;
    }
}
