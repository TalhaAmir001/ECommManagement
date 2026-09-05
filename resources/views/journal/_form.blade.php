@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    /** @var \App\Models\JournalEntry|null $entry */
    $entry = $entry ?? null;
    $oldDirection = old('direction', $entry?->category?->type ?? 'expense');
    $oldCategoryId = (int) old('category_id', $entry?->category_id ?? 0);
    $oldAmount = old('amount', $entry?->amount() ?? '');
    $oldDate = old('entry_date', $entry?->entry_date?->format('Y-m-d') ?? Carbon::now()->toDateString());
    $oldDescription = old('description', $entry?->description ?? '');
    $oldPayment = (int) old('payment_account_id', $paymentAccountId ?? 0);
    $oldStatus = old('status', $entry?->status ?? 'posted');
    $oldReference = old('reference', $entry?->reference ?? ($nextReference ?? ''));
@endphp

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    {{-- Main form --}}
    <div class="lg:col-span-2 space-y-6">
        {{-- Direction --}}
        <div class="rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
            <p class="text-sm font-medium text-ink">Direction</p>
            <p class="mt-1 text-xs text-muted">Expenses reduce profit; income entries add to it.</p>
            <div class="mt-4 inline-flex rounded-lg border border-line bg-canvas p-0.5">
                <label class="cursor-pointer">
                    <input type="radio" name="direction" value="expense" class="peer sr-only" {{ $oldDirection === 'expense' ? 'checked' : '' }}>
                    <span class="block rounded-md px-4 py-2 text-xs font-medium text-muted transition-colors peer-checked:bg-ink peer-checked:text-surface peer-hover:text-ink">
                        Expense
                    </span>
                </label>
                <label class="cursor-pointer">
                    <input type="radio" name="direction" value="income" class="peer sr-only" {{ $oldDirection === 'income' ? 'checked' : '' }}>
                    <span class="block rounded-md px-4 py-2 text-xs font-medium text-muted transition-colors peer-checked:bg-ink peer-checked:text-surface peer-hover:text-ink">
                        Income
                    </span>
                </label>
            </div>
        </div>

        {{-- Amount, date, description --}}
        <div class="rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="entry-amount" class="text-sm font-medium text-ink">Amount</label>
                    <div class="mt-2 flex rounded-lg border border-line bg-canvas focus-within:ring-1 focus-within:ring-ink">
                        <span class="inline-flex items-center px-3 text-sm text-muted">{{ currency_symbol() }}</span>
                        <input id="entry-amount" type="number" step="0.01" min="0.01" name="amount" value="{{ $oldAmount }}" required
                            class="w-full border-0 bg-transparent py-2 text-sm text-ink placeholder:text-faint focus:outline-none focus:ring-0"
                            placeholder="0.00" />
                    </div>
                </div>
                <div>
                    <label for="entry-date" class="text-sm font-medium text-ink">Date</label>
                    <input id="entry-date" type="date" name="entry_date" value="{{ $oldDate }}" required
                        class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-ink" />
                </div>
            </div>

            <div class="mt-4">
                <label for="entry-description" class="text-sm font-medium text-ink">Description</label>
                <input id="entry-description" type="text" name="description" value="{{ $oldDescription }}" maxlength="255"
                    class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint focus:outline-none focus:ring-1 focus:ring-ink"
                    placeholder="What was this for? (optional)" />
            </div>
        </div>

        {{-- Category + payment account --}}
        <div class="rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="entry-category" class="text-sm font-medium text-ink">Category</label>
                    <select id="entry-category" name="category_id" required
                        data-default-expense="{{ ($categories['expense'] ?? collect())->first()?->id ?? '' }}"
                        data-default-income="{{ ($categories['income'] ?? collect())->first()?->id ?? '' }}"
                        class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-ink">
                        @foreach (['expense', 'income'] as $type)
                            @if (isset($categories[$type]))
                                <optgroup label="{{ ucfirst($type) }}" data-type="{{ $type }}">
                                    @foreach ($categories[$type] as $cat)
                                        <option value="{{ $cat->id }}" {{ $oldCategoryId === (int) $cat->id ? 'selected' : '' }} data-type="{{ $cat->type }}">
                                            {{ $cat->name }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-faint">Used as a quick label and to pick the default P&amp;L account.</p>
                </div>
                <div>
                    <label for="entry-payment" class="text-sm font-medium text-ink">Payment account</label>
                    <select id="entry-payment" name="payment_account_id" required
                        class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-ink">
                        @foreach ($paymentAccounts as $acct)
                            <option value="{{ $acct->id }}" {{ $oldPayment === (int) $acct->id ? 'selected' : '' }}>
                                {{ $acct->name }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-faint">Cash or Bank — where the money came from / went to.</p>
                </div>
            </div>
        </div>

        {{-- Reference + status --}}
        <div class="rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="entry-reference" class="text-sm font-medium text-ink">Reference</label>
                    <input id="entry-reference" type="text" name="reference" value="{{ $oldReference }}" readonly
                        class="mt-2 w-full rounded-lg border border-line bg-canvas/60 px-3 py-2 font-mono text-sm text-muted focus:outline-none" />
                </div>
                <div>
                    <label for="entry-status" class="text-sm font-medium text-ink">Status</label>
                    <select id="entry-status" name="status"
                        class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-ink">
                        <option value="posted" {{ $oldStatus === 'posted' ? 'selected' : '' }}>Posted (affects P&amp;L)</option>
                        <option value="draft" {{ $oldStatus === 'draft' ? 'selected' : '' }}>Draft (does not affect P&amp;L)</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex items-center justify-end gap-2">
            <a href="{{ $entry ? route('journal.show', $entry) : route('journal.index') }}"
                class="rounded-lg border border-line bg-surface px-4 py-2 text-sm font-medium text-ink transition-colors hover:bg-canvas">
                Cancel
            </a>
            <button type="submit"
                class="inline-flex items-center gap-2 rounded-lg bg-ink px-4 py-2 text-sm font-medium text-surface transition-colors hover:bg-ink/90">
                {{ $entry ? 'Save changes' : 'Post entry' }}
            </button>
        </div>
    </div>

    {{-- Side panel: how it works + live preview --}}
    <aside class="space-y-4">
        <div class="rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
            <p class="text-sm font-semibold text-ink">How this posts</p>
            <p class="mt-1 text-xs leading-relaxed text-muted">
                Each entry is recorded as a balanced journal pair. The system picks the debit and credit
                accounts for you based on the direction and category.
            </p>
            <div class="mt-4 space-y-2 text-xs">
                <div class="rounded-lg bg-canvas p-3">
                    <p class="font-semibold text-ink">Expense entry</p>
                    <p class="mt-1 text-muted">Dr. <span data-preview-expense>{{ ($categories['expense'] ?? collect())->first()?->name ?? '—' }}</span> &nbsp;/&nbsp; Cr. <span data-preview-payment>{{ $paymentAccounts->first()?->name ?? 'Cash' }}</span></p>
                </div>
                <div class="rounded-lg bg-canvas p-3">
                    <p class="font-semibold text-ink">Income entry</p>
                    <p class="mt-1 text-muted">Dr. <span data-preview-payment>{{ $paymentAccounts->first()?->name ?? 'Cash' }}</span> &nbsp;/&nbsp; Cr. <span data-preview-income>{{ ($categories['income'] ?? collect())->first()?->name ?? '—' }}</span></p>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-line bg-canvas/60 p-5">
            <p class="text-sm font-semibold text-ink">Effect on profit</p>
            <p class="mt-1 text-xs text-muted">
                <span data-effect-text>Expenses subtract from net profit; income entries add to it.</span>
            </p>
        </div>
    </aside>
</div>

@push('scripts')
    {{-- Empty: scripts are inlined in create/edit pages to avoid layout changes. --}}
@endpush

<script>
    (function () {
        const directionRadios = document.querySelectorAll('input[name="direction"]');
        const categorySelect = document.getElementById('entry-category');
        const effectText = document.querySelector('[data-effect-text]');
        const previewExpense = document.querySelector('[data-preview-expense]');
        const previewIncome = document.querySelector('[data-preview-income]');
        const previewPayment = document.querySelectorAll('[data-preview-payment]');
        const paymentSelect = document.getElementById('entry-payment');

        function syncDirection() {
            const dir = document.querySelector('input[name="direction"]:checked')?.value || 'expense';

            // Filter the category options to the active direction.
            Array.from(categorySelect.options).forEach((opt) => {
                const optType = opt.dataset.type;
                const isMatch = optType === dir;
                opt.hidden = !isMatch;
                opt.disabled = !isMatch;
            });

            // Pick a sensible default if the current selection is now hidden.
            const currentOpt = categorySelect.options[categorySelect.selectedIndex];
            if (!currentOpt || currentOpt.disabled) {
                const fallback = categorySelect.querySelector('option[data-type="' + dir + '"]:not([disabled])');
                if (fallback) categorySelect.value = fallback.value;
            }

            if (effectText) {
                effectText.textContent = dir === 'expense'
                    ? 'Expenses subtract from net profit on the Reports page.'
                    : 'Income entries add to net profit on the Reports page.';
            }
        }

        directionRadios.forEach((r) => r.addEventListener('change', syncDirection));
        syncDirection();

        // Keep the "payment" preview in sync with the selected account.
        function syncPaymentPreview() {
            const label = paymentSelect.options[paymentSelect.selectedIndex]?.text?.trim();
            if (label) previewPayment.forEach((el) => el.textContent = label);
        }
        paymentSelect?.addEventListener('change', syncPaymentPreview);
        syncPaymentPreview();
    })();
</script>
