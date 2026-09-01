<?php

namespace Database\Seeders;

use App\Models\JournalAccount;
use App\Models\JournalCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a minimal chart of accounts and matching categories for the
 * Journal Entry module. Idempotent: re-running will update names/colors
 * for existing rows but never duplicate them.
 */
class JournalAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Chart of accounts: a single source of truth for account ids
        // (so the categories below can reference them).
        $accounts = [
            // Asset / payment accounts the user picks in the form.
            ['name' => 'Cash', 'slug' => 'cash', 'type' => 'asset', 'is_payment' => true, 'color' => '#1b1b18'],
            ['name' => 'Bank', 'slug' => 'bank', 'type' => 'asset', 'is_payment' => true, 'color' => '#1b1b18'],

            // Equity (kept for completeness; not surfaced in the friendly form).
            ['name' => "Owner's Equity", 'slug' => 'owners-equity', 'type' => 'equity', 'is_payment' => false, 'color' => '#1b1b18'],

            // Income accounts: other non-Shopify inflows.
            ['name' => 'Refunds Recovered', 'slug' => 'refunds-recovered', 'type' => 'income', 'is_payment' => false, 'color' => '#0a8f5c'],
            ['name' => 'Other Income', 'slug' => 'other-income', 'type' => 'income', 'is_payment' => false, 'color' => '#0a8f5c'],

            // Expense accounts: the bulk of what users will record.
            ['name' => 'Shipping', 'slug' => 'shipping', 'type' => 'expense', 'is_payment' => false, 'color' => '#ff750f'],
            ['name' => 'Packaging', 'slug' => 'packaging', 'type' => 'expense', 'is_payment' => false, 'color' => '#ff750f'],
            ['name' => 'Marketing', 'slug' => 'marketing', 'type' => 'expense', 'is_payment' => false, 'color' => '#ff750f'],
            ['name' => 'Salaries', 'slug' => 'salaries', 'type' => 'expense', 'is_payment' => false, 'color' => '#ff750f'],
            ['name' => 'Rent', 'slug' => 'rent', 'type' => 'expense', 'is_payment' => false, 'color' => '#ff750f'],
            ['name' => 'Utilities', 'slug' => 'utilities', 'type' => 'expense', 'is_payment' => false, 'color' => '#ff750f'],
            ['name' => 'Office Supplies', 'slug' => 'office-supplies', 'type' => 'expense', 'is_payment' => false, 'color' => '#ff750f'],
            ['name' => 'Other Expenses', 'slug' => 'other-expenses', 'type' => 'expense', 'is_payment' => false, 'color' => '#ff750f'],
        ];

        $accountIds = [];
        foreach ($accounts as $row) {
            $account = JournalAccount::updateOrCreate(
                ['slug' => $row['slug']],
                array_merge($row, [
                    'is_system' => true,
                    'archived' => false,
                    'updated_at' => $now,
                    'created_at' => $now,
                ])
            );
            $accountIds[$row['slug']] = $account->id;
        }

        // Default categories: one per P&L account so the friendly form has
        // a sensible starting selection. Users can rename or add more.
        $categories = [
            ['name' => 'Shipping',      'type' => 'expense', 'account' => 'shipping',          'color' => '#ff750f'],
            ['name' => 'Packaging',     'type' => 'expense', 'account' => 'packaging',         'color' => '#ff750f'],
            ['name' => 'Marketing',     'type' => 'expense', 'account' => 'marketing',         'color' => '#ff750f'],
            ['name' => 'Salaries',      'type' => 'expense', 'account' => 'salaries',          'color' => '#ff750f'],
            ['name' => 'Rent',          'type' => 'expense', 'account' => 'rent',              'color' => '#ff750f'],
            ['name' => 'Utilities',     'type' => 'expense', 'account' => 'utilities',         'color' => '#ff750f'],
            ['name' => 'Office',        'type' => 'expense', 'account' => 'office-supplies',   'color' => '#ff750f'],
            ['name' => 'Miscellaneous', 'type' => 'expense', 'account' => 'other-expenses',    'color' => '#ff750f'],
            ['name' => 'Refunds',       'type' => 'income',  'account' => 'refunds-recovered', 'color' => '#0a8f5c'],
            ['name' => 'Other Income',  'type' => 'income',  'account' => 'other-income',      'color' => '#0a8f5c'],
        ];

        foreach ($categories as $row) {
            $slug = Str::slug($row['name']);
            JournalCategory::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'default_account_id' => $accountIds[$row['account']],
                    'color' => $row['color'],
                    'archived' => false,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }
}
