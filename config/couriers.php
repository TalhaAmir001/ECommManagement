<?php

use App\Services\Courier\Providers\GenericHttpProvider;
use App\Services\Courier\Providers\LeopardsProvider;
use App\Services\Courier\Providers\ManualProvider;
use App\Services\Courier\Providers\ShopifyFulfillmentProvider;
use App\Services\Courier\Providers\TcsProvider;

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
            'driver_class' => ManualProvider::class,
            'enabled' => true,
            'poll_interval_minutes' => 0,        // manual — only updated when an admin adds an event
            'capabilities' => [
                'read_shipments',
                'read_events',
                'cod_support',
            ],
        ],
        'shopify' => [
            'display_name' => 'Shopify Fulfillment',
            'driver_class' => ShopifyFulfillmentProvider::class,
            'enabled' => (bool) env('SHOPIFY_SHOP'),
            'poll_interval_minutes' => 0,        // populated by ShopifySync, not polled
            'capabilities' => [
                'read_shipments',
                'read_events',
            ],
        ],
        'leopards' => [
            'display_name' => 'Leopards Courier',
            'driver_class' => LeopardsProvider::class,
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
            'driver_class' => TcsProvider::class,
            'enabled' => false,                  // flip to true once TCS account is wired
            'poll_interval_minutes' => 15,
            'capabilities' => [
                'read_shipments',
                'read_events',
                'cod_support',
            ],
        ],
        'mp' => [
            'display_name' => 'M&P Express',
            'driver_class' => GenericHttpProvider::class,
            'enabled' => false,                  // flip to true once the API is configured below
            'poll_interval_minutes' => 15,
            'capabilities' => [
                'read_shipments',
                'read_events',
                'cod_support',
            ],
            'settings' => [
                // Fill these in from your M&P merchant account via
                // Settings → Couriers. The generic driver needs a base URL
                // and a track endpoint (with {tracking_number}) to go live.
                'base_url' => '',
                'track_endpoint' => '',
                'method' => 'GET',
                'auth_type' => 'none',
                'timeout_seconds' => 20,
            ],
        ],
        'trax' => [
            'display_name' => 'Trax.pk',
            'driver_class' => GenericHttpProvider::class,
            'enabled' => false,                  // flip to true once the API is configured below
            'poll_interval_minutes' => 15,
            'capabilities' => [
                'read_shipments',
                'read_events',
                'cod_support',
            ],
            'settings' => [
                // Fill these in from your Trax merchant account via
                // Settings → Couriers.
                'base_url' => '',
                'track_endpoint' => '',
                'method' => 'GET',
                'auth_type' => 'none',
                'timeout_seconds' => 20,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking-link resolution
    |--------------------------------------------------------------------------
    |
    | When a Shopify fulfillment comes with a tracking URL (e.g. "track this
    | parcel at https://leopardscourier.com/?cn=LP123…"), the
    | TrackingLinkResolver looks at the URL's hostname to decide how to
    | refresh the shipment's status.
    |
    | `tracking_hosts` maps a hostname suffix (lowercased) to a courier key.
    | When a URL matches one of these, the resolver routes the refresh to
    | that courier's structured API (when it has one — see
    | `provider_keys_with_structured_api`); otherwise it falls back to the
    | GenericWebTracker that scrapes the public tracking page.
    |
    | Keep this list small. Every entry is a promise that the matching
    | provider can actually produce a status from the URL — too many
    | entries and we'll confidently route to the wrong courier.
    |
    */

    'tracking_hosts' => [
        // Pakistan
        'leopardscourier.com' => 'leopards',
        'tcs.com.pk' => 'tcs',
        'tcscourier.com' => 'tcs',
        'trax.pk' => 'trax',
        // 'mpost.pk' => 'mp',  // M&P Express tracking host — confirm the exact
        //                        domain from your M&P account, then uncomment.
        'mulphilog.com' => 'manual',
        'pakistanpost.gov.pk' => 'manual',

        // Common international carriers — we don't have APIs for these,
        // but recognizing the host means the resolver will at least try
        // the page instead of giving up.
        'dhl.com' => 'manual',
        'fedex.com' => 'manual',
        'ups.com' => 'manual',
        'usps.com' => 'manual',
        'aramex.com' => 'manual',
        'dpd.com' => 'manual',
        'yodel.co.uk' => 'manual',
        'royalmail.com' => 'manual',
        'canadapost.ca' => 'manual',
        'australiapost.com.au' => 'manual',
        'india.post' => 'manual',
        'delhivery.com' => 'manual',
        'bluedart.com' => 'manual',
        'dtdc.com' => 'manual',
        'sf-express.com' => 'manual',
    ],

    // Courier keys that have a structured tracking API. The resolver uses
    // these to decide whether to call the provider's getShipment() first
    // (more accurate) or go straight to the web scraper.
    //
    // mp and trax run on the configurable GenericHttpProvider: when their
    // endpoint/auth aren't configured yet the driver throws and the resolver
    // falls back to the web tracker automatically.
    'provider_keys_with_structured_api' => ['leopards', 'tcs', 'mp', 'trax'],

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

    /*
    |--------------------------------------------------------------------------
    | Tracking-link refresh
    |--------------------------------------------------------------------------
    |
    | The `couriers:refresh-links` command walks non-terminal shipments that
    | came in from Shopify and asks the TrackingLinkResolver for a fresh
    | status. Tune the per-run batch and HTTP timeout here.
    |
    */

    'link_refresh' => [
        // Maximum number of shipments refreshed in a single run. Keeps the
        // command (and its HTTP traffic) bounded.
        'batch_size' => (int) env('COURIER_LINK_REFRESH_BATCH', 50),

        // How long the resolver waits per courier page. Pages that take
        // longer than this are skipped for the current run.
        'http_timeout_seconds' => (int) env('COURIER_LINK_REFRESH_TIMEOUT', 20),

        // How old a non-terminal shipment can be before we skip it.
        // Shipments that haven't moved in this long are probably dead and
        // re-hitting their URL just burns the courier's rate limit.
        'max_age_days' => (int) env('COURIER_LINK_REFRESH_MAX_AGE_DAYS', 30),
    ],

];
