<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Klaviyo Private API Key
    |--------------------------------------------------------------------------
    |
    | The private API key from your Klaviyo account settings.
    | Required for all server-side API requests.
    |
    */

    'api_key' => env('KLAVIYO_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | API Revision
    |--------------------------------------------------------------------------
    |
    | The Klaviyo API revision date. Controls which version of the API
    | is used for all requests.
    |
    */

    'revision' => env('KLAVIYO_API_REVISION', '2024-10-15'),

    /*
    |--------------------------------------------------------------------------
    | Time Budgets (seconds)
    |--------------------------------------------------------------------------
    |
    | Maximum execution time per syncer before pausing and saving cursor.
    | Campaigns default to 900s because enrichment is rate-limited (2 req/min)
    | and the time is spent sleeping, not consuming CPU or memory.
    |
    */

    'time_budget' => [
        'profiles' => (int) env('KLAVIYO_TIME_BUDGET_PROFILES', 210),
        'campaigns' => (int) env('KLAVIYO_TIME_BUDGET_CAMPAIGNS', 900),
        'engagement' => (int) env('KLAVIYO_TIME_BUDGET_ENGAGEMENT', 210),
    ],

];
