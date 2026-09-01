<?php

namespace App\Services\Courier;

use App\Models\CourierProvider as CourierProviderModel;
use App\Services\Courier\Providers\GenericHttpProvider;
use App\Services\Courier\Providers\LeopardsProvider;
use App\Services\Courier\Providers\TcsProvider;

/**
 * Tells the Settings → Couriers page which credential / setting fields a
 * provider's edit form should render. Each field is a small associative
 * array:
 *
 *   [
 *     'key'      => string,   // stored key (credentials[api_key], settings[base_url]…)
 *     'label'    => string,   // UI label
 *     'type'     => string,   // text | password | url | number | select | textarea
 *     'required' => bool,     // optional
 *     'default'  => mixed,    // optional
 *     'options'  => array,    // for select
 *     'hint'     => string,   // optional helper text
 *   ]
 *
 * The generic provider drives everything from these fields, so adding a
 * courier (M&P, Trax.pk, …) is purely a data-entry exercise in the UI.
 */
final class CourierSettingsSchema
{
    /**
     * Fields for a given provider row, matched on its driver class.
     *
     * @return array{credentials: list<array<string, mixed>>, settings: list<array<string, mixed>>}
     */
    public function fieldsFor(CourierProviderModel $provider): array
    {
        return match ($provider->driver_class) {
            GenericHttpProvider::class => $this->generic(),
            LeopardsProvider::class, TcsProvider::class => $this->keyedApi(),
            default => $this->none(),
        };
    }

    /**
     * Full schema for the configurable GenericHttpProvider — used for both
     * the "add courier" form and every row that runs on the generic driver.
     *
     * @return array{credentials: list<array<string, mixed>>, settings: list<array<string, mixed>>}
     */
    public function generic(): array
    {
        return [
            'credentials' => [
                [
                    'key' => 'api_key',
                    'label' => 'API key',
                    'type' => 'password',
                    'hint' => 'Sent as a header (default X-API-Key) or query param depending on the auth type below.',
                ],
                [
                    'key' => 'bearer_token',
                    'label' => 'Bearer token',
                    'type' => 'password',
                    'hint' => 'Used when auth type is "Bearer token".',
                ],
                [
                    'key' => 'username',
                    'label' => 'Username',
                    'type' => 'text',
                    'hint' => 'Used together with the password for basic auth.',
                ],
                [
                    'key' => 'password',
                    'label' => 'Password',
                    'type' => 'password',
                    'hint' => 'Used together with the username for basic auth.',
                ],
            ],
            'settings' => [
                [
                    'key' => 'base_url',
                    'label' => 'Base URL',
                    'type' => 'url',
                    'hint' => 'e.g. https://merchantapi.courier.com — issued with your courier account.',
                ],
                [
                    'key' => 'track_endpoint',
                    'label' => 'Track endpoint',
                    'type' => 'text',
                    'hint' => 'Path appended to the base URL (or a full URL). {tracking_number} is replaced, e.g. /api/track?cn={tracking_number}',
                ],
                [
                    'key' => 'method',
                    'label' => 'HTTP method',
                    'type' => 'select',
                    'options' => ['GET' => 'GET', 'POST' => 'POST'],
                    'default' => 'GET',
                ],
                [
                    'key' => 'auth_type',
                    'label' => 'Authentication',
                    'type' => 'select',
                    'options' => [
                        'none' => 'None',
                        'header' => 'Header API key',
                        'bearer' => 'Bearer token',
                        'basic' => 'Basic (username + password)',
                        'query' => 'Query param API key',
                    ],
                    'default' => 'none',
                ],
                [
                    'key' => 'auth_header_name',
                    'label' => 'API key header name',
                    'type' => 'text',
                    'default' => 'X-API-Key',
                    'hint' => 'Used when auth type is "Header API key".',
                ],
                [
                    'key' => 'auth_query_param',
                    'label' => 'API key query parameter',
                    'type' => 'text',
                    'default' => 'api_key',
                    'hint' => 'Used when auth type is "Query param API key".',
                ],
                [
                    'key' => 'request_body',
                    'label' => 'POST body (JSON template)',
                    'type' => 'textarea',
                    'hint' => 'Used for POST requests. {tracking_number} and {credential:name} are replaced. Blank sends {"tracking_number": "…"}.',
                ],
                [
                    'key' => 'headers',
                    'label' => 'Extra headers (JSON)',
                    'type' => 'textarea',
                    'hint' => 'JSON object of header → value. Values may reference secrets as {credential:api_key}.',
                ],
                [
                    'key' => 'track_result_path',
                    'label' => 'Track result path',
                    'type' => 'text',
                    'hint' => 'Dot path to the shipment object inside the track response, e.g. data.result. Blank means the response body is the shipment.',
                ],
                [
                    'key' => 'list_endpoint',
                    'label' => 'List endpoint (optional)',
                    'type' => 'text',
                    'hint' => 'If the courier exposes a "list my shipments" endpoint, set it here to enable scheduled sync. Leave blank for track-by-number only.',
                ],
                [
                    'key' => 'list_result_path',
                    'label' => 'List result path',
                    'type' => 'text',
                    'hint' => 'Dot path to the array of shipments inside the list response, e.g. data.shipments.',
                ],
                [
                    'key' => 'status_map',
                    'label' => 'Status keywords (JSON)',
                    'type' => 'textarea',
                    'hint' => 'JSON object of keyword → status value: created, picked_up, in_transit, out_for_delivery, delivered, exception, returned, cancelled, unknown. e.g. {"rts": "returned"}',
                ],
                [
                    'key' => 'field_map',
                    'label' => 'Field map (JSON)',
                    'type' => 'textarea',
                    'hint' => 'JSON object mapping fields → dot paths in the shipment object. Leave blank to use the defaults (tracking_number, status, events, consignee.*, …).',
                ],
                [
                    'key' => 'timeout_seconds',
                    'label' => 'Timeout (seconds)',
                    'type' => 'number',
                    'default' => 20,
                ],
            ],
        ];
    }

    /**
     * Simple schema for the built-in keyed couriers (Leopards, TCS) whose
     * endpoint shape is fixed in code.
     *
     * @return array{credentials: list<array<string, mixed>>, settings: list<array<string, mixed>>}
     */
    private function keyedApi(): array
    {
        return [
            'credentials' => [
                [
                    'key' => 'api_key',
                    'label' => 'API key',
                    'type' => 'password',
                    'hint' => 'Issued by the courier when the merchant account is created.',
                ],
            ],
            'settings' => [
                [
                    'key' => 'base_url',
                    'label' => 'Base URL',
                    'type' => 'url',
                ],
                [
                    'key' => 'timeout_seconds',
                    'label' => 'Timeout (seconds)',
                    'type' => 'number',
                    'default' => 20,
                ],
            ],
        ];
    }

    /**
     * @return array{credentials: list<array<string, mixed>>, settings: list<array<string, mixed>>}
     */
    private function none(): array
    {
        return ['credentials' => [], 'settings' => []];
    }
}
