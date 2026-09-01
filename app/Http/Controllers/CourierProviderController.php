<?php

namespace App\Http\Controllers;

use App\Enums\Courier\Capability;
use App\Jobs\SyncCourierProviderJob;
use App\Models\CourierProvider as CourierProviderModel;
use App\Services\Courier\CourierSettingsSchema;
use App\Services\Courier\Providers\GenericHttpProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Settings → Couriers. This is where courier service provider APIs are
 * added and configured:
 *
 *  - index()  lists every provider with its health / last-sync status,
 *  - create() / store()  add a brand-new configurable courier (GenericHttpProvider),
 *  - edit() / update()   manage credentials + settings per provider,
 *  - destroy()           removes a custom courier,
 *  - toggle() / syncNow() are the existing quick actions.
 *
 * Credentials are stored encrypted on the courier_providers row; the edit
 * form never reveals them — blank password fields keep the current value.
 */
class CourierProviderController extends Controller
{
    /**
     * Built-in providers that are structural and can never be deleted.
     */
    private const PROTECTED_KEYS = ['manual', 'shopify'];

    public function index(): View
    {
        return view('couriers.index', [
            'providers' => CourierProviderModel::query()
                ->orderBy('display_name')
                ->get(),
            'capabilities' => Capability::cases(),
        ]);
    }

    public function create(CourierSettingsSchema $schema): View
    {
        return view('couriers.create', [
            'provider' => new CourierProviderModel,
            'schema' => $schema->generic(),
            'capabilities' => Capability::cases(),
        ]);
    }

    public function store(Request $request, CourierSettingsSchema $schema): RedirectResponse
    {
        $data = $this->validateProvider($request, isCreate: true);

        $jsonError = $this->validateJsonSettings($request);
        if ($jsonError !== null) {
            return back()->withErrors($jsonError)->withInput();
        }

        CourierProviderModel::query()->create([
            'key' => Str::slug($data['key'], '-'),
            'display_name' => $data['display_name'],
            'driver_class' => GenericHttpProvider::class,
            'enabled' => $data['enabled'],
            'credentials' => $this->buildCredentials($request, new CourierProviderModel, $schema->generic()['credentials']),
            'settings' => $this->buildSettings($request),
            'capabilities' => $data['capabilities'],
            'poll_interval_minutes' => $data['poll_interval_minutes'],
        ]);

        return redirect()->route('couriers.settings')
            ->with('status', 'Courier provider added. Open it to enter the API credentials and endpoint details.');
    }

    public function edit(CourierProviderModel $provider, CourierSettingsSchema $schema): View
    {
        return view('couriers.edit', [
            'provider' => $provider,
            'schema' => $schema->fieldsFor($provider),
            'capabilities' => Capability::cases(),
        ]);
    }

    public function update(Request $request, CourierProviderModel $provider, CourierSettingsSchema $schema): RedirectResponse
    {
        $data = $this->validateProvider($request, isCreate: false);

        $jsonError = $this->validateJsonSettings($request);
        if ($jsonError !== null) {
            return back()->withErrors($jsonError)->withInput();
        }

        $provider->forceFill([
            'display_name' => $data['display_name'],
            'enabled' => $data['enabled'],
            'credentials' => $this->buildCredentials($request, $provider, $schema->fieldsFor($provider)['credentials']),
            'settings' => $this->buildSettings($request),
            'capabilities' => $data['capabilities'],
            'poll_interval_minutes' => $data['poll_interval_minutes'],
        ])->save();

        return redirect()->route('couriers.edit', $provider)
            ->with('status', $provider->display_name.' updated.');
    }

    public function destroy(CourierProviderModel $provider): RedirectResponse
    {
        if (in_array($provider->key, self::PROTECTED_KEYS, true)) {
            return back()->withErrors(['status' => $provider->display_name.' is built-in and cannot be deleted.']);
        }

        if ($provider->shipments()->exists()) {
            return back()->withErrors([
                'status' => $provider->display_name.' still has shipments linked to it — remove those first.',
            ]);
        }

        $name = $provider->display_name;
        $provider->delete();

        return redirect()->route('couriers.settings')->with('status', $name.' removed.');
    }

    /**
     * Toggle a provider on/off. Manual is intentionally not toggleable here
     * — the admin always has the manual fallback available.
     */
    public function toggle(Request $request, CourierProviderModel $provider): RedirectResponse
    {
        if ($provider->key === 'manual') {
            return back()->withErrors(['status' => 'The manual provider is always available.']);
        }

        $provider->forceFill(['enabled' => ! $provider->enabled])->save();

        return back()->with('status', $provider->display_name.' is now '.($provider->enabled ? 'enabled' : 'disabled').'.');
    }

    /**
     * Force a sync of one provider right now (queues the job).
     */
    public function syncNow(CourierProviderModel $provider): RedirectResponse
    {
        SyncCourierProviderJob::dispatch($provider->id);

        return back()->with('status', 'Sync queued for '.$provider->display_name.'.');
    }

    /**
     * Shared validation for the create + update forms.
     *
     * @return array{key: string, display_name: string, enabled: bool, poll_interval_minutes: int, capabilities: list<string>}
     */
    private function validateProvider(Request $request, bool $isCreate): array
    {
        $rules = [
            'display_name' => ['required', 'string', 'max:100'],
            'enabled' => ['nullable', 'boolean'],
            'poll_interval_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['required', 'string', Rule::in(array_column(Capability::cases(), 'value'))],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:512'],
            'settings' => ['nullable', 'array'],
            'settings.base_url' => ['nullable', 'url', 'max:255'],
            'settings.timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:300'],
        ];

        if ($isCreate) {
            $rules['key'] = [
                'required', 'string', 'max:64',
                'regex:/^[a-z0-9][a-z0-9_\-]*$/',
                'unique:courier_providers,key',
            ];
        }

        $data = $request->validate($rules);

        return [
            'key' => (string) ($data['key'] ?? ''),
            'display_name' => $data['display_name'],
            'enabled' => $request->boolean('enabled'),
            'poll_interval_minutes' => (int) ($data['poll_interval_minutes'] ?? 15),
            'capabilities' => array_values($data['capabilities'] ?? []),
        ];
    }

    /**
     * The generic driver stores some settings as JSON documents typed into
     * a textarea. Validate them before they reach the database.
     *
     * @return array<string, string>|null field → error, or null when all good
     */
    private function validateJsonSettings(Request $request): ?array
    {
        $errors = [];
        foreach (['headers', 'status_map', 'field_map'] as $jsonField) {
            $raw = trim((string) $request->input('settings.'.$jsonField));
            if ($raw !== '' && json_decode($raw, true) === null) {
                $errors['settings.'.$jsonField] = 'Must be a valid JSON object.';
            }
        }

        return $errors === [] ? null : $errors;
    }

    /**
     * Flatten the submitted settings inputs into the JSON stored on the
     * provider row. JSON-textarea fields are decoded; everything else is
     * kept as a scalar and empty values are dropped.
     *
     * @return array<string, mixed>
     */
    private function buildSettings(Request $request): array
    {
        $settings = (array) $request->input('settings', []);

        foreach (['headers', 'status_map', 'field_map'] as $jsonField) {
            $raw = trim((string) ($settings[$jsonField] ?? ''));
            unset($settings[$jsonField]);
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $settings[$jsonField] = $decoded;
                }
            }
        }

        return array_filter($settings, fn (mixed $value) => $value !== '' && $value !== null);
    }

    /**
     * Merge submitted credential fields with the encrypted values already on
     * the row. A blank field keeps the current value, so the edit form can
     * safely render secrets as empty password inputs.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, string>
     */
    private function buildCredentials(Request $request, CourierProviderModel $provider, array $fields): array
    {
        $submitted = (array) $request->input('credentials', []);
        $current = (array) $provider->credentials;
        $merged = [];

        foreach ($fields as $field) {
            $key = (string) $field['key'];
            $value = trim((string) ($submitted[$key] ?? ''));
            if ($value !== '') {
                $merged[$key] = $value;
            } elseif (isset($current[$key]) && is_string($current[$key]) && $current[$key] !== '') {
                $merged[$key] = $current[$key];
            }
        }

        // Preserve any credentials already stored that aren't in the form
        // schema (e.g. fields added by a future driver).
        foreach ($current as $key => $value) {
            if (! isset($merged[$key]) && is_string($value) && $value !== '') {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
