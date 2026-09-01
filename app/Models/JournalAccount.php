<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Chart of accounts used by the Journal Entry module.
 *
 * Types:
 *  - asset:     things the business owns (Cash, Bank, Inventory).
 *               Normal balance: DEBIT. Increases with a debit.
 *  - liability: things the business owes (Accounts Payable).
 *               Normal balance: CREDIT. Increases with a credit.
 *  - equity:    owner's stake (Owner's Capital, Retained Earnings).
 *               Normal balance: CREDIT.
 *  - income:    revenue outside of Shopify sales (e.g. Refunds Recovered,
 *               Other Income). Normal balance: CREDIT. Increases with a credit.
 *  - expense:   costs outside of COGS (Shipping, Marketing, Rent, ...).
 *               Normal balance: DEBIT. Increases with a debit.
 *
 * Only `income` and `expense` accounts hit the P&L.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $type
 * @property bool $is_payment
 * @property bool $is_system
 * @property string|null $color
 * @property bool $archived
 */
class JournalAccount extends Model
{
    /** @use HasFactory<\Database\Factories\JournalAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'is_payment',
        'is_system',
        'color',
        'archived',
    ];

    protected function casts(): array
    {
        return [
            'is_payment' => 'boolean',
            'is_system' => 'boolean',
            'archived' => 'boolean',
        ];
    }

    /**
     * @return HasMany<JournalLine>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }

    /**
     * Active (non-archived) accounts of a given type.
     *
     * @param  Builder<JournalAccount>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('archived', false);
    }

    /**
     * @param  Builder<JournalAccount>  $query
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @param  Builder<JournalAccount>  $query
     */
    public function scopePaymentAccounts(Builder $query): Builder
    {
        return $query->where('is_payment', true);
    }

    /**
     * @param  Builder<JournalAccount>  $query
     */
    public function scopePnlAccounts(Builder $query): Builder
    {
        return $query->whereIn('type', ['income', 'expense']);
    }
}
