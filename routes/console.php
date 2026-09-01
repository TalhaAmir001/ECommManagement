<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('shopify:sync')->everyFifteenMinutes()->withoutOverlapping();

// Poll every enabled courier provider on a tight loop. Providers that aren't
// due (per their poll_interval_minutes) early-return so this stays cheap.
Schedule::command('couriers:sync --queue')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

// Walk non-terminal Shopify-originated shipments and follow their tracking
// URLs through the TrackingLinkResolver. This is what actually fetches a
// delivery status from the courier — the `couriers:sync` loop only knows
// about the structured API providers.
Schedule::command('couriers:refresh-links --queue')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
