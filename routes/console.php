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
