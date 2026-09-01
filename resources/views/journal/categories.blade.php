@extends('layouts.dashboard')

@section('title', 'Journal Categories')

@section('content')
    @php
        $expenseCats = $categories->where('type', 'expense')->values();
        $incomeCats = $categories->where('type', 'income')->values();
    @endphp

    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <a href="{{ route('journal.index') }}" class="inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-ink">
                    <x-dashboard.icon name="chevron-left" class="h-3.5 w-3.5" />
                    Back to journal entries
                </a>
                <h1 class="mt-2 text-[22px] font-semibold tracking-tight text-ink">Journal categories</h1>
                <p class="mt-1 text-sm text-muted">
                    Categories are quick labels that pair an entry with a default P&amp;L account. You can add, rename, or archive them here.
                </p>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-4 rounded-lg border border-line bg-positive-soft px-4 py-3 text-sm text-positive">
                {{ session('status') }}
            </div>
        @endif
        @if ($errors->any())
            <div class="mt-4 rounded-lg border border-negative/30 bg-negative-soft px-4 py-3 text-sm text-negative">
                <ul class="list-disc space-y-0.5 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Expense categories --}}
            <section class="rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
                <div class="border-b border-line p-5">
                    <h2 class="text-sm font-semibold text-ink">Expense categories</h2>
                    <p class="mt-0.5 text-xs text-muted">Used for costs that cut from net profit.</p>
                </div>
                <div class="divide-y divide-line">
                    @forelse ($expenseCats as $cat)
                        <div class="flex items-center justify-between p-5">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $cat->color ?? '#ff750f' }}"></span>
                                    <p class="truncate font-medium text-ink">{{ $cat->name }}</p>
                                </div>
                                <p class="mt-1 text-xs text-muted">Default account: {{ $cat->defaultAccount?->name ?? '—' }}</p>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button"
                                    class="rounded-md p-1.5 text-muted hover:bg-canvas hover:text-ink"
                                    data-edit-category
                                    data-id="{{ $cat->id }}"
                                    data-name="{{ $cat->name }}"
                                    data-type="{{ $cat->type }}"
                                    data-account="{{ $cat->default_account_id }}"
                                    data-color="{{ $cat->color ?? '' }}">
                                    <x-dashboard.icon name="edit" class="h-4 w-4" />
                                </button>
                                <form method="POST" action="{{ route('journal.categories.destroy', $cat) }}"
                                    onsubmit="return confirm('Delete this category? Existing journal entries that use it will keep their reference but lose this label.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-md p-1.5 text-muted hover:bg-negative-soft hover:text-negative">
                                        <x-dashboard.icon name="trash" class="h-4 w-4" />
                                    </button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="px-5 py-8 text-center text-sm text-muted">No expense categories yet.</div>
                    @endforelse
                </div>

                {{-- Add expense --}}
                <form method="POST" action="{{ route('journal.categories.store') }}" class="border-t border-line bg-canvas/30 p-5">
                    @csrf
                    <input type="hidden" name="type" value="expense" />
                    <p class="text-xs font-medium uppercase tracking-wider text-faint">Add expense category</p>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <input type="text" name="name" required maxlength="120" placeholder="e.g. Facebook Ads"
                            class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint focus:outline-none focus:ring-1 focus:ring-ink sm:col-span-1" />
                        <select name="default_account_id" required
                            class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-ink sm:col-span-2">
                            <option value="">— Default expense account —</option>
                            @foreach ($expenseAccounts as $acct)
                                <option value="{{ $acct->id }}">{{ $acct->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-xs font-medium text-surface transition-colors hover:bg-ink/90">
                        <x-dashboard.icon name="plus" class="h-3.5 w-3.5" />
                        Add category
                    </button>
                </form>
            </section>

            {{-- Income categories --}}
            <section class="rounded-2xl border border-line bg-surface shadow-sm shadow-ink/[0.02]">
                <div class="border-b border-line p-5">
                    <h2 class="text-sm font-semibold text-ink">Income categories</h2>
                    <p class="mt-0.5 text-xs text-muted">Used for non-Shopify inflows (e.g. supplier credits).</p>
                </div>
                <div class="divide-y divide-line">
                    @forelse ($incomeCats as $cat)
                        <div class="flex items-center justify-between p-5">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $cat->color ?? '#0a8f5c' }}"></span>
                                    <p class="truncate font-medium text-ink">{{ $cat->name }}</p>
                                </div>
                                <p class="mt-1 text-xs text-muted">Default account: {{ $cat->defaultAccount?->name ?? '—' }}</p>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button"
                                    class="rounded-md p-1.5 text-muted hover:bg-canvas hover:text-ink"
                                    data-edit-category
                                    data-id="{{ $cat->id }}"
                                    data-name="{{ $cat->name }}"
                                    data-type="{{ $cat->type }}"
                                    data-account="{{ $cat->default_account_id }}"
                                    data-color="{{ $cat->color ?? '' }}">
                                    <x-dashboard.icon name="edit" class="h-4 w-4" />
                                </button>
                                <form method="POST" action="{{ route('journal.categories.destroy', $cat) }}"
                                    onsubmit="return confirm('Delete this category? Existing journal entries that use it will keep their reference but lose this label.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-md p-1.5 text-muted hover:bg-negative-soft hover:text-negative">
                                        <x-dashboard.icon name="trash" class="h-4 w-4" />
                                    </button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="px-5 py-8 text-center text-sm text-muted">No income categories yet.</div>
                    @endforelse
                </div>

                <form method="POST" action="{{ route('journal.categories.store') }}" class="border-t border-line bg-canvas/30 p-5">
                    @csrf
                    <input type="hidden" name="type" value="income" />
                    <p class="text-xs font-medium uppercase tracking-wider text-faint">Add income category</p>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <input type="text" name="name" required maxlength="120" placeholder="e.g. Supplier Credit"
                            class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint focus:outline-none focus:ring-1 focus:ring-ink sm:col-span-1" />
                        <select name="default_account_id" required
                            class="rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-ink sm:col-span-2">
                            <option value="">— Default income account —</option>
                            @foreach ($incomeAccounts as $acct)
                                <option value="{{ $acct->id }}">{{ $acct->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-xs font-medium text-surface transition-colors hover:bg-ink/90">
                        <x-dashboard.icon name="plus" class="h-3.5 w-3.5" />
                        Add category
                    </button>
                </form>
            </section>
        </div>

        {{-- Edit modal (uses a native <dialog> for zero-JS dependencies) --}}
        <dialog id="edit-category-dialog" class="w-full max-w-md rounded-2xl border border-line bg-surface p-0 shadow-xl backdrop:bg-ink/40">
            <form id="edit-category-form" method="POST" class="p-5">
                @csrf
                @method('PUT')
                <p class="text-sm font-semibold text-ink">Edit category</p>
                <div class="mt-4 space-y-3">
                    <div>
                        <label class="text-xs font-medium text-muted">Name</label>
                        <input id="edit-name" type="text" name="name" required maxlength="120"
                            class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-ink" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-muted">Type</label>
                        <input id="edit-type-display" type="text" disabled
                            class="mt-1 w-full rounded-lg border border-line bg-canvas/60 px-3 py-2 text-sm text-muted" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-muted">Default account</label>
                        <select id="edit-account" name="default_account_id" required
                            class="mt-1 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-ink"></select>
                    </div>
                </div>
                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" data-cancel-edit class="rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium text-ink hover:bg-canvas">
                        Cancel
                    </button>
                    <button type="submit" class="rounded-lg bg-ink px-3.5 py-2 text-sm font-medium text-surface hover:bg-ink/90">
                        Save
                    </button>
                </div>
            </form>
        </dialog>

        <script>
            (function () {
                const dialog = document.getElementById('edit-category-dialog');
                const form = document.getElementById('edit-category-form');
                const nameInput = document.getElementById('edit-name');
                const typeDisplay = document.getElementById('edit-type-display');
                const accountSelect = document.getElementById('edit-account');
                const expenseAccounts = @json($expenseAccounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values());
                const incomeAccounts = @json($incomeAccounts->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values());

                document.querySelectorAll('[data-edit-category]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const type = btn.dataset.type;
                        const accounts = type === 'income' ? incomeAccounts : expenseAccounts;

                        form.action = '/journal/categories/' + btn.dataset.id;
                        nameInput.value = btn.dataset.name;
                        typeDisplay.value = type.charAt(0).toUpperCase() + type.slice(1);
                        accountSelect.innerHTML = accounts
                            .map((a) => `<option value="${a.id}" ${String(a.id) === btn.dataset.account ? 'selected' : ''}>${a.name}</option>`)
                            .join('');

                        dialog.showModal();
                    });
                });

                document.querySelectorAll('[data-cancel-edit]').forEach((btn) => {
                    btn.addEventListener('click', () => dialog.close());
                });

                // Close when clicking outside the dialog content.
                dialog.addEventListener('click', (e) => {
                    if (e.target === dialog) dialog.close();
                });
            })();
        </script>
    </div>
@endsection
