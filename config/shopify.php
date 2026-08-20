<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shopify API Configuration
    |--------------------------------------------------------------------------
    |
    | Credentials for connecting this application to a single Shopify store.
    | Access tokens are obtained using the client credentials grant, Shopify's
    | current recommended flow for apps built for your own store.
    |
    */

    'shop' => env('SHOPIFY_SHOP'),

    'client_id' => env('SHOPIFY_CLIENT_ID'),

    'client_secret' => env('SHOPIFY_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Admin API Version
    |--------------------------------------------------------------------------
    |
    | The versioned Admin API endpoints will use this version (e.g. 2026-07).
    |
    */

    'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),

    /*
    |--------------------------------------------------------------------------
    | Access Scopes
    |--------------------------------------------------------------------------
    |
    | Scopes are configured on the app in the Shopify Dev Dashboard. This value
    | documents them and is available to any future sync/read code so it knows
    | what data the token is permitted to access.
    |
    */

    'scopes' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('SHOPIFY_SCOPES', 'read_products,read_orders,read_customers,read_inventory')))
    )),

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | The base URL where Shopify will send webhook payloads. This should be
    | the public-facing URL of your application (not localhost). For local
    | development, use an ngrok tunnel URL.
    |
    */

    'webhook_base_url' => env('SHOPIFY_APP_URL'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Secret
    |--------------------------------------------------------------------------
    |
    | Used to verify the authenticity of incoming webhook requests via the
    | X-Shopify-Hmac-Sha256 header. Per Shopify's current guidance, HTTPS
    | webhook deliveries are signed with the app's client secret, so for a
    | custom app this should be the same value as SHOPIFY_CLIENT_SECRET.
    |
    */

    'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Order Webhook Topics
    |--------------------------------------------------------------------------
    |
    | Shopify webhook topics to subscribe to for real-time order updates.
    |
    */

    'order_topics' => [
        'orders/create',
        'orders/updated',
        'orders/paid',
        'orders/fulfilled',
        'orders/cancelled',
    ],

];
