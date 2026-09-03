<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell in a courier provider's delivery rate matrix:
 * origin zone × destination zone × weight range → price.
 *
 * @property int $id
 * @property int $courier_provider_id
 * @property int $origin_zone_id
 * @property int $destination_zone_id
 * @property float $weight_from_kg
 * @property float|null $weight_to_kg
 * @property float $price
 * @property float|null $cod_fee
 * @property string $currency
 * @property bool $is_active
 */
class CourierRate extends Model
{
    protected $fillable = [
        'courier_provider_id',
        'origin_zone_id',
        'destination_zone_id',
        'weight_from_kg',
        'weight_to_kg',
        'price',
        'cod_fee',
        'currency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weight_from_kg' => 'decimal:3',
            'weight_to_kg' => 'decimal:3',
            'price' => 'decimal:2',
            'cod_fee' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CourierProvider::class, 'courier_provider_id');
    }

    public function originZone(): BelongsTo
    {
        return $this->belongsTo(CourierZone::class, 'origin_zone_id');
    }

    public function destinationZone(): BelongsTo
    {
        return $this->belongsTo(CourierZone::class, 'destination_zone_id');
    }
}
