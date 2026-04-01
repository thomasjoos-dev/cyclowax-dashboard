<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (env('SYNC_SCHEDULE_ENABLED', false)) {
    Schedule::command('sync:all --skip-enrichment')->dailyAt('06:00')->withoutOverlapping(10);
    Schedule::command('sync:all --full --skip-enrichment')->weeklyOn(0, '04:00')->withoutOverlapping(60);
    Schedule::command('klaviyo:enrich-campaigns --limit=20')->dailyAt('07:00')->withoutOverlapping(15);
}
