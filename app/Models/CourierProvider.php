<?php

namespace App\Models;

use App\Enums\Courier\Capability;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $display_name
 * @property string $driver_class
 * @property bool $enabled
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property list<string>|null $capabilities
 * @property int $poll_interval_minutes
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property string|null $last_sync_status
 * @property string|null $last_sync_error
 */
class CourierProvider extends Model
{
    /** @use HasFactory<\Database\Factories\CourierProviderFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'display_name',
        'driver_class',
        'enabled',
        'credentials',
        'settings',
        'capabilities',
        'poll_interval_minutes',
        'last_synced_at',
        'last_sync_status',
        'last_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'capabilities' => 'array',
            'poll_interval_minutes' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Shipments that have been imported from this provider.
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /**
     * Delivery zones defined for this provider's rate card.
     *
     * @return HasMany<CourierZone, $this>
     */
    public function zones(): HasMany
    {
        return $this->hasMany(CourierZone::class);
    }

    /**
     * Rate matrix cells for this provider.
     *
     * @return HasMany<CourierRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(CourierRate::class);
    }

    /**
     * Whether this provider can perform a given capability.
     */
    public function can(Capability $capability): bool
    {
        $caps = $this->capabilities ?? [];

        return in_array($capability->value, $caps, true);
    }

    /**
     * Whether the provider is enabled AND a sync is due.
     */
    public function isDueForSync(): bool
    {
        if (! $this->enabled) {
            return false;
        }
        if ($this->last_synced_at === null) {
            return true;
        }

        return $this->last_synced_at->lte(now()->subMinutes($this->poll_interval_minutes));
    }
}
