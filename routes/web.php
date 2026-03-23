<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::get('dashboard', DashboardController::class)->name('dashboard');

Route::prefix('docs')->name('docs.')->group(function () {
    Route::inertia('api', 'docs/api')->name('api');
    Route::inertia('architecture', 'docs/architecture')->name('architecture');
    Route::inertia('styleguide', 'docs/styleguide')->name('styleguide');
});

require __DIR__.'/settings.php';
