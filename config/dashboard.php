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

];
