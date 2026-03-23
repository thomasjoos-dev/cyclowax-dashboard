<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Shopify Store Domain
    |--------------------------------------------------------------------------
    |
    | The myshopify.com domain for your store, e.g. "my-store.myshopify.com".
    |
    */

    'store' => env('SHOPIFY_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Admin API Access Token
    |--------------------------------------------------------------------------
    |
    | The offline access token from your Custom App in the Shopify admin.
    |
    */

    'access_token' => env('SHOPIFY_ACCESS_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | API Version
    |--------------------------------------------------------------------------
    |
    | The Shopify Admin API version to use for all GraphQL requests.
    |
    */

    'api_version' => env('SHOPIFY_API_VERSION', '2025-04'),

    /*
    |--------------------------------------------------------------------------
    | OAuth Credentials (only needed for shopify:auth command)
    |--------------------------------------------------------------------------
    |
    | Client ID and Secret from the Shopify Partners dev dashboard.
    | Only used during the one-time OAuth token exchange.
    |
    */

    'client_id' => env('SHOPIFY_CLIENT_ID'),

    'client_secret' => env('SHOPIFY_CLIENT_SECRET'),

];
