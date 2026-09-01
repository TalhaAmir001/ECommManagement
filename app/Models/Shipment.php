<?php

namespace App\Models;

use App\Enums\Courier\ShipmentStatus;
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
        'consignee_address',
        'consignee_city',
        'weight_kg',
        'pieces',
        'cod_amount',
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
}
