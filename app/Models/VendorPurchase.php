<?php

namespace App\Models;

use Database\Factories\VendorPurchaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Goods or raw materials received from a {@see Vendor}.
 *
 * `total_cost` is a snapshot of quantity × unit_cost at the time the
 * purchase was recorded, so history stays accurate even if a unit cost is
 * later edited elsewhere.
 *
 * @property int $id
 * @property int $vendor_id
 * @property string|null $reference
 * @property string $item_description
 * @property string $quantity
 * @property string|null $unit
 * @property string $unit_cost
 * @property string $total_cost
 * @property Carbon $purchase_date
 * @property string $currency
 * @property string|null $notes
 */
class VendorPurchase extends Model
{
    /** @use HasFactory<VendorPurchaseFactory> */
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'reference',
        'item_description',
        'quantity',
        'unit',
        'unit_cost',
        'total_cost',
        'purchase_date',
        'currency',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'purchase_date' => 'date',
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
