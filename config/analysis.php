<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Output Path
    |--------------------------------------------------------------------------
    |
    | Directory where finalized PDF reports are copied to. Defaults to the
    | user's Desktop. Override in CI/server environments.
    |
    */

    'output_path' => env('ANALYSIS_OUTPUT_PATH', ($_SERVER['HOME'] ?? '/tmp').'/Desktop'),

];
