<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * User-defined category for journal entries (e.g. "Shipping", "Marketing").
 *
 * Each category points at a default P&L account so the friendly form can
 * produce a balanced double-entry pair without exposing debit/credit to the
 * user.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $type
 * @property int $default_account_id
 * @property string|null $color
 * @property bool $archived
 */
class JournalCategory extends Model
{
    /** @use HasFactory<\Database\Factories\JournalCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'default_account_id',
        'color',
        'archived',
    ];

    protected function casts(): array
    {
        return [
            'archived' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<JournalAccount, JournalCategory>
     */
    public function defaultAccount(): BelongsTo
    {
        return $this->belongsTo(JournalAccount::class, 'default_account_id');
    }

    /**
     * @return HasMany<JournalEntry>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'category_id');
    }

    /**
     * @param  Builder<JournalCategory>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('archived', false);
    }

    /**
     * @param  Builder<JournalCategory>  $query
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
