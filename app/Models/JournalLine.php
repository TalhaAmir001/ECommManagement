<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side (debit or credit) of a {@see JournalEntry}.
 *
 * @property int $id
 * @property int $entry_id
 * @property int $account_id
 * @property float $debit
 * @property float $credit
 * @property string|null $memo
 */
class JournalLine extends Model
{
    /** @use HasFactory<\Database\Factories\JournalLineFactory> */
    use HasFactory;

    protected $fillable = [
        'entry_id',
        'account_id',
        'debit',
        'credit',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<JournalEntry, JournalLine>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }

    /**
     * @return BelongsTo<JournalAccount, JournalLine>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(JournalAccount::class, 'account_id');
    }

    /**
     * Signed amount: positive on the debit side, negative on the credit side.
     */
    public function signedAmount(): float
    {
        return round((float) $this->debit - (float) $this->credit, 2);
    }
}
