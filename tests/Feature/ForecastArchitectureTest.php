<?php

arch('forecast demand services belong to the Demand namespace')
    ->expect('App\Services\Forecast\Demand')
    ->toBeClasses();

arch('forecast supply services belong to the Supply namespace')
    ->expect('App\Services\Forecast\Supply')
    ->toBeClasses();

arch('forecast tracking services belong to the Tracking namespace')
    ->expect('App\Services\Forecast\Tracking')
    ->toBeClasses();

arch('SkuMixService stays in the Forecast root namespace')
    ->expect('App\Services\Forecast\SkuMixService')
    ->toBeClasses();

arch('no services remain directly in the Forecast root namespace except SkuMixService')
    ->expect('App\Services\Forecast')
    ->not->toBeClasses()
    ->ignoring('App\Services\Forecast\SkuMixService')
    ->ignoring('App\Services\Forecast\Demand')
    ->ignoring('App\Services\Forecast\Supply')
    ->ignoring('App\Services\Forecast\Tracking');
