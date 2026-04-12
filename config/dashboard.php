<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard Cache TTL
    |--------------------------------------------------------------------------
    |
    | How long dashboard analytics are cached, in seconds.
    | Default: 3600 (1 hour). Set to 0 to disable caching.
    |
    */

    'cache_ttl' => (int) env('DASHBOARD_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Sync Scheduler
    |--------------------------------------------------------------------------
    |
    | Controls whether the automatic sync schedule is enabled and at what time
    | the daily sync runs. The enrichment job runs 1 hour after the daily sync.
    |
    */

    'sync_schedule_enabled' => (bool) env('SYNC_SCHEDULE_ENABLED', false),

    'sync_daily_at' => env('SYNC_DAILY_AT', '06:00'),

];
