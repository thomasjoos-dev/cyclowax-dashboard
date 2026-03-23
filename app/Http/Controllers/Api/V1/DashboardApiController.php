<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardApiController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): JsonResponse
    {
        $period = $request->query('period', 'mtd');

        return response()->json([
            'period' => $period,
            'kpi' => $dashboard->kpiMetrics($period),
            'acquisition_trend' => $dashboard->acquisitionTrend(),
            'acquisition_by_region' => $dashboard->acquisitionByRegion(),
            'region_growth_rates' => $dashboard->regionGrowthRates(),
            'order_type_split' => $dashboard->orderTypeSplit(),
            'revenue_split' => $dashboard->revenueSplit(),
            'cohort_retention' => $dashboard->cohortRetention(),
            'time_to_second_order' => $dashboard->timeToSecondOrder(),
            'retention_by_region' => $dashboard->retentionByRegion(),
            'aov_trend' => $dashboard->aovTrend(),
            'top_products_first' => $dashboard->topProductsFirstOrder(),
            'top_products_returning' => $dashboard->topProductsReturning(),
        ]);
    }
}
