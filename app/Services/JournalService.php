<?php

namespace App\Services;

use App\Models\JournalAccount;
use App\Models\JournalCategory;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pure-logic helpers for the Journal Entry module.
 *
 * - Builds balanced double-entry lines from a friendly form payload.
 * - Aggregates posted entries into P&L totals for Reports and Dashboard.
 *
 * The service is stateless; all configuration is passed in as arguments.
 */
class JournalService
{
    /**
     * Build and persist a journal entry (header + lines) from the friendly
     * form fields.
     *
     * Expected payload:
     *  - entry_date       string (Y-m-d)
     *  - direction        'expense' | 'income'
     *  - amount           positive numeric
     *  - category_id      int   (must be of the matching direction)
     *  - payment_account_id int (must be an asset with is_payment=true)
     *  - description      string|null
     *  - status           'draft' | 'posted' (defaults to 'posted')
     *  - reference        string|null  (auto-generated if missing)
     *
     * Returns the persisted {@see JournalEntry} with lines eagerly loaded.
     *
     * @param  array<string, mixed>  $data
     */
    public function createEntry(array $data): JournalEntry
    {
        return DB::transaction(function () use ($data) {
            $direction = $data['direction'] === 'income' ? 'income' : 'expense';
            $amount = round((float) $data['amount'], 2);
            $status = $data['status'] ?? 'posted';

            /** @var JournalCategory $category */
            $category = JournalCategory::active()
                ->ofType($direction)
                ->findOrFail($data['category_id']);

            /** @var JournalAccount $paymentAccount */
            $paymentAccount = JournalAccount::active()
                ->paymentAccounts()
                ->findOrFail($data['payment_account_id']);

            /** @var JournalAccount $pnlAccount */
            $pnlAccount = $category->defaultAccount;

            $entry = JournalEntry::create([
                'entry_date' => Carbon::parse($data['entry_date'])->toDateString(),
                'reference' => $data['reference'] ?? $this->generateReference(),
                'category_id' => $category->id,
                'description' => $data['description'] ?? null,
                'status' => $status,
            ]);

            if ($direction === 'expense') {
                // Debit the expense account, credit the payment account.
                $entry->lines()->createMany([
                    [
                        'account_id' => $pnlAccount->id,
                        'debit' => $amount,
                        'credit' => 0,
                        'memo' => $data['description'] ?? null,
                    ],
                    [
                        'account_id' => $paymentAccount->id,
                        'debit' => 0,
                        'credit' => $amount,
                        'memo' => $data['description'] ?? null,
                    ],
                ]);
            } else {
                // Debit the payment account, credit the income account.
                $entry->lines()->createMany([
                    [
                        'account_id' => $paymentAccount->id,
                        'debit' => $amount,
                        'credit' => 0,
                        'memo' => $data['description'] ?? null,
                    ],
                    [
                        'account_id' => $pnlAccount->id,
                        'debit' => 0,
                        'credit' => $amount,
                        'memo' => $data['description'] ?? null,
                    ],
                ]);
            }

            return $entry->refresh()->load('lines.account', 'category');
        });
    }

    /**
     * Replace the lines on an existing entry from a new payload.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateEntry(JournalEntry $entry, array $data): JournalEntry
    {
        return DB::transaction(function () use ($entry, $data) {
            $direction = $data['direction'] === 'income' ? 'income' : 'expense';
            $amount = round((float) $data['amount'], 2);
            $status = $data['status'] ?? $entry->status;

            /** @var JournalCategory $category */
            $category = JournalCategory::active()
                ->ofType($direction)
                ->findOrFail($data['category_id']);

            /** @var JournalAccount $paymentAccount */
            $paymentAccount = JournalAccount::active()
                ->paymentAccounts()
                ->findOrFail($data['payment_account_id']);

            $entry->forceFill([
                'entry_date' => Carbon::parse($data['entry_date'])->toDateString(),
                'category_id' => $category->id,
                'description' => $data['description'] ?? null,
                'status' => $status,
            ])->save();

            $entry->lines()->delete();

            $pnlAccount = $category->defaultAccount;

            if ($direction === 'expense') {
                $entry->lines()->createMany([
                    [
                        'account_id' => $pnlAccount->id,
                        'debit' => $amount,
                        'credit' => 0,
                        'memo' => $data['description'] ?? null,
                    ],
                    [
                        'account_id' => $paymentAccount->id,
                        'debit' => 0,
                        'credit' => $amount,
                        'memo' => $data['description'] ?? null,
                    ],
                ]);
            } else {
                $entry->lines()->createMany([
                    [
                        'account_id' => $paymentAccount->id,
                        'debit' => $amount,
                        'credit' => 0,
                        'memo' => $data['description'] ?? null,
                    ],
                    [
                        'account_id' => $pnlAccount->id,
                        'debit' => 0,
                        'credit' => $amount,
                        'memo' => $data['description'] ?? null,
                    ],
                ]);
            }

            return $entry->refresh()->load('lines.account', 'category');
        });
    }

    /**
     * P&L totals from posted journal entries inside an optional date range.
     *
     * Returns:
     *  - total_expense:    sum of (debit - credit) on expense accounts (positive number)
     *  - total_income:     sum of (credit - debit) on income accounts (positive number)
     *  - net_adjustment:   total_income - total_expense (positive = profit lift)
     *  - by_category:      collection grouped by category_id with totals
     *  - by_account:       collection grouped by account_id with totals
     *
     * @return array{
     *     total_expense: float,
     *     total_income: float,
     *     net_adjustment: float,
     *     by_category: \Illuminate\Support\Collection<int, object>,
     *     by_account: \Illuminate\Support\Collection<int, object>
     * }
     */
    public function pnlTotals(?Carbon $start = null, ?Carbon $end = null): array
    {
        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->join('journal_accounts as ja', 'ja.id', '=', 'jl.account_id')
            ->where('je.status', 'posted')
            ->whereIn('ja.type', ['income', 'expense']);

        if ($start !== null) {
            $query->where('je.entry_date', '>=', $start->toDateString());
        }
        if ($end !== null) {
            $query->where('je.entry_date', '<=', $end->toDateString());
        }

        $rows = $query
            ->selectRaw('ja.id as account_id, ja.name as account_name, ja.type as account_type,
                         je.category_id,
                         SUM(jl.debit) as debit, SUM(jl.credit) as credit')
            ->groupBy('ja.id', 'ja.name', 'ja.type', 'je.category_id')
            ->get();

        $totalExpense = 0.0;
        $totalIncome = 0.0;
        $byCategory = [];
        $byAccount = [];

        foreach ($rows as $row) {
            $debit = (float) $row->debit;
            $credit = (float) $row->credit;

            if ($row->account_type === 'expense') {
                $expenseDelta = $debit - $credit; // normal debit balance
                $totalExpense += $expenseDelta;
            } else {
                $incomeDelta = $credit - $debit; // normal credit balance
                $totalIncome += $incomeDelta;
            }

            $key = $row->account_id;
            if (! isset($byAccount[$key])) {
                $byAccount[$key] = (object) [
                    'account_id' => (int) $row->account_id,
                    'account_name' => $row->account_name,
                    'account_type' => $row->account_type,
                    'amount' => 0.0,
                ];
            }
            $byAccount[$key]->amount += $row->account_type === 'expense'
                ? ($debit - $credit)
                : ($credit - $debit);

            $catKey = $row->category_id ?? 0;
            if (! isset($byCategory[$catKey])) {
                $byCategory[$catKey] = (object) [
                    'category_id' => $row->category_id !== null ? (int) $row->category_id : null,
                    'amount' => 0.0,
                ];
            }
            $byCategory[$catKey]->amount += $row->account_type === 'expense'
                ? -($debit - $credit)
                : ($credit - $debit);
        }

        return [
            'total_expense' => round($totalExpense, 2),
            'total_income' => round($totalIncome, 2),
            'net_adjustment' => round($totalIncome - $totalExpense, 2),
            'by_category' => collect(array_values($byCategory)),
            'by_account' => collect(array_values($byAccount)),
        ];
    }

    /**
     * P&L totals bucketed by month. Used to overlay the audit monthly chart.
     *
     * @return array<int, array{key: string, label: string, expense: float, income: float, net: float}>
     */
    public function pnlMonthly(?Carbon $start, ?Carbon $end): array
    {
        $today = Carbon::now()->endOfDay();
        $windowEnd = $end !== null ? $end->copy()->endOfDay() : $today;
        $windowStart = $start !== null ? $start->copy()->startOfDay() : $today->copy()->subMonths(11)->startOfMonth();

        $cursor = $windowStart->copy()->startOfMonth();
        $endCursor = $windowEnd->copy()->startOfMonth();

        $months = [];
        while ($cursor->lte($endCursor)) {
            $key = $cursor->format('Y-m');
            $months[$key] = [
                'key' => $key,
                'label' => $cursor->format('M Y'),
                'expense' => 0.0,
                'income' => 0.0,
                'net' => 0.0,
            ];
            $cursor->addMonth();
        }

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.entry_id')
            ->join('journal_accounts as ja', 'ja.id', '=', 'jl.account_id')
            ->where('je.status', 'posted')
            ->whereIn('ja.type', ['income', 'expense'])
            ->whereBetween('je.entry_date', [
                $windowStart->toDateString(),
                $windowEnd->toDateString(),
            ])
            ->selectRaw('DATE_FORMAT(je.entry_date, "%Y-%m") as month_key,
                         ja.type as account_type,
                         SUM(jl.debit) as debit, SUM(jl.credit) as credit')
            ->groupBy('month_key', 'ja.type')
            ->get();

        foreach ($rows as $row) {
            $key = (string) $row->month_key;
            if (! isset($months[$key])) {
                continue;
            }
            $debit = (float) $row->debit;
            $credit = (float) $row->credit;

            if ($row->account_type === 'expense') {
                $months[$key]['expense'] = round($debit - $credit, 2);
            } else {
                $months[$key]['income'] = round($credit - $debit, 2);
            }
            $months[$key]['net'] = round(
                $months[$key]['income'] - $months[$key]['expense'],
                2
            );
        }

        return array_values($months);
    }

    /**
     * Generate the next human-readable reference, e.g. "JE-000123".
     */
    public function generateReference(): string
    {
        $latest = JournalEntry::query()
            ->where('reference', 'like', 'JE-%')
            ->orderByDesc('id')
            ->value('reference');

        $next = 1;
        if ($latest !== null && preg_match('/JE-(\d+)/', $latest, $m) === 1) {
            $next = ((int) $m[1]) + 1;
        }

        return 'JE-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Quick helper: net P&L effect of all posted entries up to and
     * including the given date. Used by the Dashboard's headline number.
     */
    public function netAdjustmentTo(Carbon $end): float
    {
        $totals = $this->pnlTotals(null, $end);

        return (float) $totals['net_adjustment'];
    }
}
