@php
    $isCreate = ! $provider->exists;
    $storedSettings = $provider->settings ?? [];
    $storedCredentials = $provider->credentials ?? [];
    $storedCaps = $provider->capabilities ?? [];
    $jsonFields = ['headers', 'status_map', 'field_map'];
    $inputClass = 'w-full rounded-lg border border-line bg-canvas px-2.5 py-2 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10';

    $capValue = function (string $value) use ($storedCaps) {
        $old = old('capabilities');
        if (is_array($old)) {
            return in_array($value, $old, true);
        }

        return in_array($value, $storedCaps, true);
    };

    $settingValue = function (array $field) use ($storedSettings, $jsonFields) {
        $value = old("settings.{$field['key']}") ?? $storedSettings[$field['key']] ?? $field['default'] ?? null;
        if (in_array($field['key'], $jsonFields, true) && is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return $value;
    };

    $hasCredential = function (string $key) use ($storedCredentials) {
        return isset($storedCredentials[$key]) && $storedCredentials[$key] !== '';
    };
@endphp

{{-- Flash + validation --}}
@if (session('status'))
    <div class="rounded-xl border border-line bg-positive-soft px-4 py-3 text-sm text-positive">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="rounded-xl border border-line bg-negative-soft px-4 py-3 text-sm text-negative">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

<div class="mt-6 space-y-6">
    {{-- Identity + behaviour --}}
    <section class="rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
        <h2 class="text-sm font-semibold text-ink">Identity & behaviour</h2>
        <p class="mt-0.5 text-xs text-muted">How this courier is shown around the app and how often it is polled.</p>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if ($isCreate)
                <div class="sm:col-span-2">
                    <label for="key" class="mb-1.5 block text-xs font-medium text-muted">Key <span class="text-negative">*</span></label>
                    <input id="key" name="key" value="{{ old('key') }}" placeholder="e.g. mp or my-courier"
                        class="{{ $inputClass }}" />
                    <p class="mt-1 text-xs text-muted">Lowercase letters, numbers, dashes. Used to link tracking URLs to this courier — pick it once.</p>
                    @error('key')
                        <p class="mt-1 text-xs text-negative">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            <div>
                <label for="display_name" class="mb-1.5 block text-xs font-medium text-muted">Display name <span class="text-negative">*</span></label>
                <input id="display_name" name="display_name" value="{{ old('display_name', $provider->display_name) }}" placeholder="e.g. M&P Express"
                    class="{{ $inputClass }}" />
                @error('display_name')
                    <p class="mt-1 text-xs text-negative">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="poll_interval_minutes" class="mb-1.5 block text-xs font-medium text-muted">Poll interval (minutes)</label>
                <input id="poll_interval_minutes" name="poll_interval_minutes" type="number" min="0" max="10080"
                    value="{{ old('poll_interval_minutes', $provider->poll_interval_minutes ?? 15) }}" class="{{ $inputClass }}" />
                <p class="mt-1 text-xs text-muted">0 disables scheduled polling (manual sync only).</p>
                @error('poll_interval_minutes')
                    <p class="mt-1 text-xs text-negative">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex items-center gap-2.5 rounded-lg border border-line bg-canvas/40 px-3 py-2.5 text-sm">
                <input type="checkbox" name="enabled" value="1"
                    {{ old('enabled', $provider->enabled) ? 'checked' : '' }}
                    class="h-4 w-4 rounded border-line text-ink focus:ring-ink/20" />
                <span class="font-medium text-ink">Enabled</span>
                <span class="text-xs text-muted">Disabled providers are skipped by the sync engine.</span>
            </label>
        </div>
    </section>

    {{-- Capabilities --}}
    <section class="rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
        <h2 class="text-sm font-semibold text-ink">Capabilities</h2>
        <p class="mt-0.5 text-xs text-muted">Which actions the app is allowed to offer for this courier.</p>

        <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($capabilities as $capability)
                <label class="flex items-center gap-2.5 rounded-lg border border-line bg-canvas/40 px-3 py-2 text-sm">
                    <input type="checkbox" name="capabilities[]" value="{{ $capability->value }}"
                        {{ $capValue($capability->value) ? 'checked' : '' }}
                        class="h-4 w-4 rounded border-line text-ink focus:ring-ink/20" />
                    <span class="font-medium text-ink">{{ $capability->label() }}</span>
                </label>
            @endforeach
        </div>
        @error('capabilities')
            <p class="mt-1 text-xs text-negative">{{ $message }}</p>
        @enderror
    </section>

    {{-- Credentials (encrypted) --}}
    <section class="rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
        <h2 class="text-sm font-semibold text-ink">API credentials</h2>
        <p class="mt-0.5 text-xs text-muted">Stored encrypted at rest and never shown again — leave a field blank to keep its current value.</p>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            @forelse ($schema['credentials'] as $field)
                <div>
                    <label for="credential_{{ $field['key'] }}" class="mb-1.5 block text-xs font-medium text-muted">
                        {{ $field['label'] }}
                        @if (! empty($field['required']))
                            <span class="text-negative">*</span>
                        @endif
                    </label>
                    <input id="credential_{{ $field['key'] }}" name="credentials[{{ $field['key'] }}]"
                        type="{{ $field['type'] }}"
                        placeholder="{{ $hasCredential($field['key']) ? '••••••••  (leave blank to keep)' : '' }}"
                        class="{{ $inputClass }}" autocomplete="off" />
                    @if (! empty($field['hint']))
                        <p class="mt-1 text-xs text-muted">{{ $field['hint'] }}</p>
                    @endif
                    @if ($hasCredential($field['key']))
                        <p class="mt-1 text-xs text-positive">Saved</p>
                    @endif
                    @error("credentials.{$field['key']}")
                        <p class="mt-1 text-xs text-negative">{{ $message }}</p>
                    @enderror
                </div>
            @empty
                <p class="text-sm text-muted sm:col-span-2">This courier does not require API credentials.</p>
            @endforelse
        </div>
    </section>

    {{-- Settings (generic driver) --}}
    @if ($schema['settings'] !== [])
        <section class="rounded-2xl border border-line bg-surface p-5 shadow-sm shadow-ink/[0.02]">
            <h2 class="text-sm font-semibold text-ink">API settings</h2>
            <p class="mt-0.5 text-xs text-muted">The endpoints, auth and response mapping this courier uses.</p>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach ($schema['settings'] as $field)
                    <div class="{{ $field['type'] === 'textarea' ? 'sm:col-span-2' : '' }}">
                        <label for="setting_{{ $field['key'] }}" class="mb-1.5 block text-xs font-medium text-muted">
                            {{ $field['label'] }}
                        </label>

                        @if ($field['type'] === 'select')
                            <select id="setting_{{ $field['key'] }}" name="settings[{{ $field['key'] }}]" class="{{ $inputClass }}">
                                @foreach ($field['options'] as $optionValue => $optionLabel)
                                    <option value="{{ $optionValue }}" {{ (string) $settingValue($field) === (string) $optionValue ? 'selected' : '' }}>
                                        {{ $optionLabel }}
                                    </option>
                                @endforeach
                            </select>
                        @elseif ($field['type'] === 'textarea')
                            <textarea id="setting_{{ $field['key'] }}" name="settings[{{ $field['key'] }}]" rows="5"
                                class="{{ $inputClass }} font-mono text-xs">{{ $settingValue($field) }}</textarea>
                        @else
                            <input id="setting_{{ $field['key'] }}" name="settings[{{ $field['key'] }}]"
                                type="{{ $field['type'] }}" value="{{ $settingValue($field) }}" class="{{ $inputClass }}" />
                        @endif

                        @if (! empty($field['hint']))
                            <p class="mt-1 text-xs text-muted">{{ $field['hint'] }}</p>
                        @endif
                        @error("settings.{$field['key']}")
                            <p class="mt-1 text-xs text-negative">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>

<div class="mt-6 flex items-center justify-end gap-3">
    <a href="{{ route('couriers.settings') }}"
        class="inline-flex items-center gap-2 rounded-lg border border-line bg-surface px-3.5 py-2 text-sm font-medium text-ink transition-colors hover:bg-canvas">
        Cancel
    </a>
    <button type="submit"
        class="inline-flex items-center gap-2 rounded-lg bg-ink px-3.5 py-2 text-sm font-medium text-surface transition-colors hover:bg-ink/90">
        <x-dashboard.icon name="{{ $isCreate ? 'plus' : 'edit' }}" class="h-4 w-4" />
        {{ $isCreate ? 'Add courier' : 'Save changes' }}
    </button>
</div>

