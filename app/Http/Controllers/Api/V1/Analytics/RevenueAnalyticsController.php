<?php

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RevenueAnalyticsRequest;
use App\Services\Analysis\RevenueAnalyticsService;
use Illuminate\Http\JsonResponse;

class RevenueAnalyticsController extends Controller
{
    public function __invoke(RevenueAnalyticsRequest $request, RevenueAnalyticsService $service): JsonResponse
    {
        $period = $request->validated('period', 'mtd');

        return response()->json([
            'period' => $period,
            'kpi' => $service->kpiMetrics($period),
            'revenue_split' => $service->revenueSplit(),
            'aov_trend' => $service->aovTrend(),
        ]);
    }
}
