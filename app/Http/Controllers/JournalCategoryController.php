<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreJournalCategoryRequest;
use App\Http\Requests\UpdateJournalCategoryRequest;
use App\Models\JournalAccount;
use App\Models\JournalCategory;
use App\Models\JournalEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class JournalCategoryController extends Controller
{
    /**
     * Manage categories: list, create form, edit form, delete.
     */
    public function index(Request $request): View
    {
        $categories = JournalCategory::query()
            ->with('defaultAccount')
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        $expenseAccounts = JournalAccount::active()->ofType('expense')->orderBy('name')->get();
        $incomeAccounts = JournalAccount::active()->ofType('income')->orderBy('name')->get();

        return view('journal.categories', [
            'categories' => $categories,
            'expenseAccounts' => $expenseAccounts,
            'incomeAccounts' => $incomeAccounts,
        ]);
    }

    public function store(StoreJournalCategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(4));

        JournalCategory::create($data);

        return redirect()
            ->route('journal.categories')
            ->with('status', "Category \"{$data['name']}\" created.");
    }

    public function update(UpdateJournalCategoryRequest $request, JournalCategory $category): RedirectResponse
    {
        $category->update($request->validated());

        return redirect()
            ->route('journal.categories')
            ->with('status', "Category \"{$category->name}\" updated.");
    }

    public function destroy(JournalCategory $category): RedirectResponse
    {
        $inUse = JournalEntry::where('category_id', $category->id)->exists();
        if ($inUse) {
            return redirect()
                ->route('journal.categories')
                ->withErrors(['category' => "Cannot delete \"{$category->name}\" — it is used by one or more journal entries. Archive it instead."]);
        }

        $name = $category->name;
        $category->delete();

        return redirect()
            ->route('journal.categories')
            ->with('status', "Category \"{$name}\" deleted.");
    }
}
