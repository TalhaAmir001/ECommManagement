<?php

namespace App\Models;

use Database\Factories\VendorPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money sent to a {@see Vendor}, reducing what we owe them.
 *
 * @property int $id
 * @property int $vendor_id
 * @property string $amount
 * @property Carbon $payment_date
 * @property string|null $method
 * @property string|null $reference
 * @property string|null $notes
 * @property string $currency
 */
class VendorPayment extends Model
{
    /** @use HasFactory<VendorPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'amount',
        'payment_date',
        'method',
        'reference',
        'notes',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
