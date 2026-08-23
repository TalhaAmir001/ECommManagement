<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Courier provider configuration
    |--------------------------------------------------------------------------
    |
    | Per-provider defaults for poll cadence, default settings, and which
    | capabilities are advertised on first install. The courier_providers
    | table is the runtime source of truth — these values are only used to
    | seed it.
    |
    | Capabilities are App\Enums\Courier\Capability values. Anything declared
    | here is recorded when the row is seeded; you can change it from the
    | admin UI afterwards.
    |
    */

    'providers' => [
        'manual' => [
            'display_name' => 'Manual Entry',
            'driver_class' => \App\Services\Courier\Providers\ManualProvider::class,
            'enabled' => true,
            'poll_interval_minutes' => 0,        // manual — only updated when an admin adds an event
            'capabilities' => [
                'read_shipments',
                'read_events',
                'cod_support',
            ],
        ],
        'leopards' => [
            'display_name' => 'Leopards Courier',
            'driver_class' => \App\Services\Courier\Providers\LeopardsProvider::class,
            'enabled' => (bool) env('LEOPARDS_API_KEY'),
            'poll_interval_minutes' => 15,
            'capabilities' => [
                'read_shipments',
                'read_events',
                'cod_support',
            ],
            'settings' => [
                'base_url' => env('LEOPARDS_BASE_URL', 'https://merchantapi.leopardscourier.com'),
                'timeout_seconds' => 20,
            ],
        ],
        'tcs' => [
            'display_name' => 'TCS',
            'driver_class' => \App\Services\Courier\Providers\TcsProvider::class,
            'enabled' => false,                  // flip to true once TCS account is wired
            'poll_interval_minutes' => 15,
            'capabilities' => [
                'read_shipments',
                'read_events',
                'cod_support',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-matching policy
    |--------------------------------------------------------------------------
    |
    | The OrderAutoMatcher runs on every new shipment. Keys mirror the
    | matched_method column on shipments so reports can group by strategy.
    |
    */

    'auto_match' => [
        'enabled' => true,
        // Order of strategies — first match wins.
        'strategies' => ['reference', 'phone'],
        // When true, re-running the matcher can break a manual link. When
        // false (default), manual links are sticky and only the auto
        // strategies can be overwritten on a re-run.
        'overwrite_manual' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    */

    'default_currency' => env('COURIER_DEFAULT_CURRENCY', 'PKR'),

];
