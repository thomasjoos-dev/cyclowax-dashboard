<?php

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analysis\RetentionAnalyticsService;
use Illuminate\Http\JsonResponse;

class RetentionAnalyticsController extends Controller
{
    public function __invoke(RetentionAnalyticsService $service): JsonResponse
    {
        return response()->json([
            'order_type_split' => $service->orderTypeSplit(),
            'cohort_retention' => $service->cohortRetention(),
            'time_to_second_order' => $service->timeToSecondOrder(),
            'retention_by_region' => $service->retentionByRegion(),
        ]);
    }
}
