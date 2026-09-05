@php
    /** @var \App\Models\Vendor|null $vendor */
    $vendor = $vendor ?? null;
    $oldField = function (string $key, string $default = '') use ($vendor) {
        return old($key, (string) ($vendor->{$key} ?? $default));
    };
@endphp

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-6">
        {{-- Details --}}
        <div class="rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
            <p class="text-sm font-medium text-ink">Details</p>
            <p class="mt-1 text-xs text-muted">Who you buy product or raw material from.</p>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="vendor-name" class="text-sm font-medium text-ink">Name</label>
                    <input id="vendor-name" type="text" name="name" value="{{ $oldField('name') }}" required maxlength="255"
                        placeholder="e.g. Faisal Fabrics"
                        class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink" />
                </div>
                <div>
                    <label for="vendor-contact" class="text-sm font-medium text-ink">Contact person</label>
                    <input id="vendor-contact" type="text" name="contact_name" value="{{ $oldField('contact_name') }}" maxlength="255"
                        placeholder="Optional"
                        class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink" />
                </div>
                <div>
                    <label for="vendor-phone" class="text-sm font-medium text-ink">Phone</label>
                    <input id="vendor-phone" type="text" name="phone" value="{{ $oldField('phone') }}" maxlength="40"
                        placeholder="03xx xxxxxxx"
                        class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink" />
                </div>
                <div class="sm:col-span-2">
                    <label for="vendor-email" class="text-sm font-medium text-ink">Email</label>
                    <input id="vendor-email" type="email" name="email" value="{{ $oldField('email') }}" maxlength="255"
                        placeholder="vendor@example.com"
                        class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink" />
                </div>
                <div class="sm:col-span-2">
                    <label for="vendor-address" class="text-sm font-medium text-ink">Address</label>
                    <textarea id="vendor-address" name="address" rows="2" maxlength="1000"
                        placeholder="Optional"
                        class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink">{{ $oldField('address') }}</textarea>
                </div>
                <div class="sm:col-span-2">
                    <label for="vendor-notes" class="text-sm font-medium text-ink">Notes</label>
                    <textarea id="vendor-notes" name="notes" rows="3" maxlength="2000"
                        placeholder="Payment terms, lead times, anything worth remembering…"
                        class="mt-2 w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-1 focus:ring-ink">{{ $oldField('notes') }}</textarea>
                </div>
            </div>
        </div>
    </div>

    <aside class="space-y-6">
        <div class="rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
            <p class="text-sm font-medium text-ink">Currency</p>
            <p class="mt-1 text-xs text-muted">Balances are shown in the app currency (PKR).</p>
            <p class="mt-3 inline-flex items-center gap-1.5 rounded-full border border-line bg-canvas px-2.5 py-1 text-xs font-medium text-muted">
                <span class="h-1.5 w-1.5 rounded-full bg-accent"></span>
                {{ currency_code() }} · {{ currency_symbol() }}
            </p>
        </div>

        <div class="rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
            <p class="text-sm font-medium text-ink">Save</p>
            <p class="mt-1 text-xs text-muted">Purchases and payments are recorded on the vendor page after saving.</p>
            <button type="submit"
                class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-ink px-3.5 py-2.5 text-sm font-medium text-surface transition-colors hover:bg-ink/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-ink/20">
                {{ isset($vendor) ? 'Save changes' : 'Add vendor' }}
            </button>
        </div>
    </aside>
</div>
