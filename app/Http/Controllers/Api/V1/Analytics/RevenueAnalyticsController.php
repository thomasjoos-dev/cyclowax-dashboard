<?php

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analysis\RevenueAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevenueAnalyticsController extends Controller
{
    public function __invoke(Request $request, RevenueAnalyticsService $service): JsonResponse
    {
        $period = $request->query('period', 'mtd');

        return response()->json([
            'period' => $period,
            'kpi' => $service->kpiMetrics($period),
            'revenue_split' => $service->revenueSplit(),
            'aov_trend' => $service->aovTrend(),
        ]);
    }
}
