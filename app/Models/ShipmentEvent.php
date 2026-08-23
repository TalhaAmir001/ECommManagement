<?php

namespace App\Models;

use App\Enums\Courier\ShipmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $shipment_id
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property ShipmentStatus $status
 * @property string|null $location
 * @property string|null $description
 */
class ShipmentEvent extends Model
{
    /** @use HasFactory<\Database\Factories\ShipmentEventFactory> */
    use HasFactory;

    protected $fillable = [
        'shipment_id',
        'occurred_at',
        'status',
        'location',
        'description',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'status' => ShipmentStatus::class,
            'raw_payload' => 'array',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
