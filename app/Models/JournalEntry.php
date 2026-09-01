<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Header of a journal entry. Each entry has at least two {@see JournalLine}
 * rows whose debits and credits balance.
 *
 * @property int $id
 * @property Carbon $entry_date
 * @property string $reference
 * @property int|null $category_id
 * @property string|null $description
 * @property string $status  draft|posted
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class JournalEntry extends Model
{
    /** @use HasFactory<\Database\Factories\JournalEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'entry_date',
        'reference',
        'category_id',
        'description',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<JournalCategory, JournalEntry>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(JournalCategory::class, 'category_id');
    }

    /**
     * @return HasMany<JournalLine>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'entry_id');
    }

    /**
     * @param  Builder<JournalEntry>  $query
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', 'posted');
    }

    /**
     * Total debits on the entry (sum of all line debits).
     */
    public function totalDebit(): float
    {
        return (float) $this->lines()->sum('debit');
    }

    /**
     * Total credits on the entry.
     */
    public function totalCredit(): float
    {
        return (float) $this->lines()->sum('credit');
    }

    /**
     * The amount of the entry (debit or credit — they're equal on a balanced
     * entry).
     */
    public function amount(): float
    {
        return (float) $this->totalDebit();
    }

    /**
     * Net effect of the entry on the P&L.
     *
     * Income accounts contribute positively (credit-normal), expense accounts
     * negatively (debit-normal).
     */
    public function pnlEffect(): float
    {
        $effect = 0.0;

        foreach ($this->lines()->with('account')->get() as $line) {
            $type = $line->account?->type;
            $delta = (float) $line->debit - (float) $line->credit;

            if ($type === 'expense') {
                $effect -= $delta; // debit increases expense, cuts profit
            } elseif ($type === 'income') {
                $effect += -$delta; // credit increases income, lifts profit
            }
        }

        return round($effect, 2);
    }
}
