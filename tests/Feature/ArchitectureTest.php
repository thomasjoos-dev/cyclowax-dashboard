<?php

arch('controllers do not use DB facade directly')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('services use constructor injection, not app() or resolve()')
    ->expect('App\Services')
    ->not->toUse(['app', 'resolve']);

arch('application code does not call env() directly')
    ->expect('App')
    ->not->toUse('env');

arch('models do not contain business logic methods')
    ->expect('App\Models')
    ->not->toUse('Illuminate\Support\Facades\Http');

arch('enums are string-backed')
    ->expect('App\Enums')
    ->toBeStringBackedEnums();
