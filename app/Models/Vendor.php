<?php

namespace App\Models;

use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A supplier we buy products or raw materials from.
 *
 * Balances are derived, never stored: total purchased comes from
 * {@see self::$purchases}, total paid from {@see self::$payments}, and
 * `balance()` is the difference (positive = money owed to the vendor,
 * negative = credit in our favour).
 *
 * @property int $id
 * @property string $name
 * @property string|null $contact_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $notes
 * @property string $currency
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'contact_name',
        'email',
        'phone',
        'address',
        'notes',
        'currency',
    ];

    /**
     * @return HasMany<VendorPurchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(VendorPurchase::class);
    }

    /**
     * @return HasMany<VendorPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(VendorPayment::class);
    }

    /**
     * Total value of goods/raw material bought from this vendor.
     */
    public function totalPurchased(): float
    {
        return (float) $this->purchases()->sum('total_cost');
    }

    /**
     * Total amount of money sent to this vendor.
     */
    public function totalPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    /**
     * Running balance: purchases minus payments.
     *
     * Positive = the vendor is owed money (accounts payable). Negative =
     * we overpaid / the vendor is in credit.
     */
    public function balance(): float
    {
        return round($this->totalPurchased() - $this->totalPaid(), 2);
    }
}
