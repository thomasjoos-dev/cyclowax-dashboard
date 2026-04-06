<?php

use App\Services\Forecast\Demand\CategorySeasonalCalculator;
use App\Services\Forecast\Demand\CohortProjectionService;
use App\Services\Forecast\Demand\DemandEventService;
use App\Services\Forecast\Demand\DemandForecastService;
use App\Services\Forecast\Demand\RegionalCostService;
use App\Services\Forecast\Demand\RegionalForecastAggregator;
use App\Services\Forecast\Demand\SalesBaselineService;
use App\Services\Forecast\Demand\SeasonalIndexCalculator;
use App\Services\Forecast\SkuMixService;
use App\Services\Forecast\Supply\BomExplosionService;
use App\Services\Forecast\Supply\ComponentNettingService;
use App\Services\Forecast\Supply\InventoryHealthService;
use App\Services\Forecast\Supply\ProductionTimelineService;
use App\Services\Forecast\Supply\PurchaseCalendarService;
use App\Services\Forecast\Supply\PurchaseCalendarTrackingService;
use App\Services\Forecast\Supply\SupplyProfileAnalyzer;
use App\Services\Forecast\Tracking\ForecastTrackingService;
use App\Services\Forecast\Tracking\GoalService;
use App\Services\Forecast\Tracking\ScenarioService;

$forecastServices = [
    SalesBaselineService::class,
    DemandForecastService::class,
    CategorySeasonalCalculator::class,
    SeasonalIndexCalculator::class,
    DemandEventService::class,
    CohortProjectionService::class,
    RegionalForecastAggregator::class,
    RegionalCostService::class,
    ComponentNettingService::class,
    ProductionTimelineService::class,
    BomExplosionService::class,
    PurchaseCalendarService::class,
    InventoryHealthService::class,
    SupplyProfileAnalyzer::class,
    ForecastTrackingService::class,
    ScenarioService::class,
    GoalService::class,
    SkuMixService::class,
    PurchaseCalendarTrackingService::class,
];

it('resolves all forecast services from the container', function () use ($forecastServices) {
    foreach ($forecastServices as $serviceClass) {
        $instance = app($serviceClass);

        expect($instance)->toBeInstanceOf($serviceClass);
    }
});

it('has exactly 19 forecast services registered', function () use ($forecastServices) {
    expect($forecastServices)->toHaveCount(19);
});
