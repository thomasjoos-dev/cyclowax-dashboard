<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data Since
    |--------------------------------------------------------------------------
    |
    | The start date for analysis queries. All analysis services use this as
    | the default lower bound for their data window. Adjust when historical
    | data grows and older data becomes less relevant.
    |
    */

    'data_since' => env('ANALYTICS_DATA_SINCE', '2024-01-01'),

];
