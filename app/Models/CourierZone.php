<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named zone for a courier provider — a collection of cities that share
 * one delivery price. Shipments are mapped onto a zone by their consignor /
 * consignee city, with a "default" zone catching anything unlisted.
 *
 * @property int $id
 * @property int $courier_provider_id
 * @property string $name
 * @property list<string> $cities
 * @property bool $is_default
 */
class CourierZone extends Model
{
    protected $fillable = [
        'courier_provider_id',
        'name',
        'cities',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'cities' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CourierProvider::class, 'courier_provider_id');
    }

    public function originRates(): HasMany
    {
        return $this->hasMany(CourierRate::class, 'origin_zone_id');
    }

    public function destinationRates(): HasMany
    {
        return $this->hasMany(CourierRate::class, 'destination_zone_id');
    }
}
