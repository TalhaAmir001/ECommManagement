<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard operator account
    |--------------------------------------------------------------------------
    |
    | Single hard-coded set of credentials that unlocks the dashboard. The
    | source of truth is the environment (ADMIN_NAME / ADMIN_EMAIL /
    | ADMIN_PASSWORD), so the values never live in the codebase itself.
    |
    | On a successful login these credentials are mirrored into the `users`
    | table (a User record is created/updated on first sign-in) so the
    | rest of the app can rely on the standard session guard and
    | auth()->user() without a separate auth backend.
    |
    */

    'name' => env('ADMIN_NAME', 'Alex Kim'),

    'email' => env('ADMIN_EMAIL', 'alex@storefront.app'),

    'password' => env('ADMIN_PASSWORD', 'password'),
];
