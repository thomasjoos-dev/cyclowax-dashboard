<?php

use App\Http\Controllers\Api\V1\Analytics\AcquisitionAnalyticsController;
use App\Http\Controllers\Api\V1\Analytics\ProductAnalyticsController;
use App\Http\Controllers\Api\V1\Analytics\RetentionAnalyticsController;
use App\Http\Controllers\Api\V1\Analytics\RevenueAnalyticsController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardApiController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ScenarioController;
use App\Http\Controllers\Api\V1\SyncStatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->as('api.v1.')->group(function () {
    Route::get('dashboard', DashboardApiController::class)->name('dashboard');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');

    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');

    Route::get('sync/status', SyncStatusController::class)->name('sync.status');

    Route::prefix('analytics')->as('analytics.')->group(function () {
        Route::get('revenue', RevenueAnalyticsController::class)->name('revenue');
        Route::get('acquisition', AcquisitionAnalyticsController::class)->name('acquisition');
        Route::get('retention', RetentionAnalyticsController::class)->name('retention');
        Route::get('products', ProductAnalyticsController::class)->name('products');
    });

    Route::get('scenarios', [ScenarioController::class, 'index'])->name('scenarios.index');
    Route::get('scenarios/{scenario}', [ScenarioController::class, 'show'])->name('scenarios.show');
    Route::get('scenarios/{scenario}/forecast', [ScenarioController::class, 'forecast'])->name('scenarios.forecast');
});
