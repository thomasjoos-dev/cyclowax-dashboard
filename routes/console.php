<?php

use Carbon\Carbon;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (config('dashboard.sync_schedule_enabled')) {
    $dailyAt = config('dashboard.sync_daily_at', '06:00');
    $enrichAt = Carbon::parse($dailyAt)->addHour()->format('H:i');

    Schedule::command('sync:all --skip-enrichment')->dailyAt($dailyAt)->withoutOverlapping(10);
    Schedule::command('sync:all --full --skip-enrichment')->weeklyOn(0, '04:00')->withoutOverlapping(60);
    Schedule::command('klaviyo:enrich-campaigns --limit=20')->dailyAt($enrichAt)->withoutOverlapping(15);
}
