<?php

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analysis\AcquisitionAnalyticsService;
use Illuminate\Http\JsonResponse;

class AcquisitionAnalyticsController extends Controller
{
    public function __invoke(AcquisitionAnalyticsService $service): JsonResponse
    {
        return response()->json([
            'acquisition_trend' => $service->acquisitionTrend(),
            'acquisition_by_region' => $service->acquisitionByRegion(),
            'region_growth_rates' => $service->regionGrowthRates(),
        ]);
    }
}
