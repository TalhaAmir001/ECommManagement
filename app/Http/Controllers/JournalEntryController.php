<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreJournalEntryRequest;
use App\Http\Requests\UpdateJournalEntryRequest;
use App\Models\JournalAccount;
use App\Models\JournalCategory;
use App\Models\JournalEntry;
use App\Services\JournalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class JournalEntryController extends Controller
{
    public function __construct(private readonly JournalService $journal)
    {
    }

    /**
     * List journal entries with filters.
     */
    public function index(Request $request): View
    {
        $filters = $request->query();

        $query = JournalEntry::query()
            ->with(['category', 'lines.account'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        if (! empty($filters['direction'])) {
            $query->whereHas('category', function ($q) use ($filters) {
                $q->where('type', $filters['direction']);
            });
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $search = trim((string) $filters['q']);
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('reference', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }
        }
        if (! empty($filters['date'])) {
            $query->when($filters['date'] === 'today', fn ($q) => $q->whereDate('entry_date', Carbon::today()))
                ->when($filters['date'] === '7d', fn ($q) => $q->whereDate('entry_date', '>=', Carbon::today()->subDays(6)))
                ->when($filters['date'] === '30d', fn ($q) => $q->whereDate('entry_date', '>=', Carbon::today()->subDays(29)))
                ->when($filters['date'] === '90d', fn ($q) => $q->whereDate('entry_date', '>=', Carbon::today()->subDays(89)));
        }

        $entries = $query->paginate(20)->withQueryString();

        $totals = $this->journal->pnlTotals(
            $this->resolveRangeStart($filters),
            $this->resolveRangeEnd($filters)
        );

        return view('journal.index', [
            'entries' => $entries,
            'categories' => JournalCategory::active()->orderBy('name')->get(),
            'paymentAccounts' => JournalAccount::active()->paymentAccounts()->orderBy('name')->get(),
            'filters' => $filters,
            'totals' => $totals,
        ]);
    }

    /**
     * Show the create form.
     */
    public function create(): View
    {
        return view('journal.create', [
            // Normalize so the form can always rely on both type keys
            // existing, even when no category of a type is active yet.
            'categories' => $this->categoriesByType(),
            'paymentAccounts' => JournalAccount::active()->paymentAccounts()->orderBy('name')->get(),
            'nextReference' => $this->journal->generateReference(),
        ]);
    }

    /**
     * Persist a new entry.
     */
    public function store(StoreJournalEntryRequest $request): RedirectResponse
    {
        $entry = $this->journal->createEntry($request->validated());

        return redirect()
            ->route('journal.show', $entry)
            ->with('status', "Journal entry {$entry->reference} posted.");
    }

    /**
     * Detail page: header + balanced lines.
     */
    public function show(JournalEntry $entry): View
    {
        $entry->load(['category.defaultAccount', 'lines.account']);

        return view('journal.show', [
            'entry' => $entry,
        ]);
    }

    /**
     * Edit form.
     */
    public function edit(JournalEntry $entry): View
    {
        $entry->load(['lines.account', 'category']);

        $debitLine = $entry->lines->firstWhere('debit', '>', 0);
        $creditLine = $entry->lines->firstWhere('credit', '>', 0);
        $paymentAccountId = $debitLine && $debitLine->account?->is_payment
            ? $debitLine->account_id
            : ($creditLine?->account_id);

        return view('journal.edit', [
            'entry' => $entry,
            'categories' => $this->categoriesByType(),
            'paymentAccounts' => JournalAccount::active()->paymentAccounts()->orderBy('name')->get(),
            'paymentAccountId' => $paymentAccountId,
        ]);
    }

    /**
     * Update an existing entry.
     */
    public function update(UpdateJournalEntryRequest $request, JournalEntry $entry): RedirectResponse
    {
        $this->journal->updateEntry($entry, $request->validated());

        return redirect()
            ->route('journal.show', $entry)
            ->with('status', "Journal entry {$entry->reference} updated.");
    }

    /**
     * Remove an entry (and its lines, via FK cascade).
     */
    public function destroy(JournalEntry $entry): RedirectResponse
    {
        $reference = $entry->reference;
        $entry->delete();

        return redirect()
            ->route('journal.index')
            ->with('status', "Journal entry {$reference} deleted.");
    }

    private function resolveRangeStart(array $filters): ?Carbon
    {
        if (! empty($filters['date'])) {
            return match ($filters['date']) {
                'today' => Carbon::today(),
                '7d' => Carbon::today()->subDays(6),
                '30d' => Carbon::today()->subDays(29),
                '90d' => Carbon::today()->subDays(89),
                default => null,
            };
        }

        return null;
    }

    private function resolveRangeEnd(array $filters): ?Carbon
    {
        if (! empty($filters['date'])) {
            return Carbon::today();
        }

        return null;
    }

    /**
     * Active categories grouped by type (expense | income), with empty
     * placeholder groups merged in so both keys always exist. Without this,
     * the grouped collection only contains keys for types that have at least
     * one active category, and an unguarded `$categories['expense']` in the
     * shared form crashes the page when no expense category is configured.
     *
     * @return Collection<int, Collection<int, JournalCategory>>
     */
    private function categoriesByType(): Collection
    {
        return collect(['expense' => collect(), 'income' => collect()])
            ->merge(JournalCategory::active()->orderBy('name')->get()->groupBy('type'));
    }
}
