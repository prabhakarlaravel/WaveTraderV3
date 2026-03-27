<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fetch candles every 30 seconds for all active symbols
Schedule::command('candles:fetch --all')->everyThirtySeconds();

// Zerodha token renewal reminder — daily at 8:45 AM IST (before market open 9:15)
// Note: actual renewal requires manual request_token from browser login
Schedule::command('zerodha:renew-token')
    ->dailyAt('03:15') // 8:45 AM IST = 3:15 AM UTC
    ->weekdays();
