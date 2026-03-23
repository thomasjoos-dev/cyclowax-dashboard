<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): Response
    {
        $period = $request->query('period', 'mtd');

        return Inertia::render('dashboard', [
            'period' => $period,
            'kpi' => $dashboard->kpiMetrics($period),
            'acquisitionTrend' => Inertia::defer(fn () => $dashboard->acquisitionTrend()),
            'acquisitionByRegion' => Inertia::defer(fn () => $dashboard->acquisitionByRegion()),
            'regionGrowthRates' => Inertia::defer(fn () => $dashboard->regionGrowthRates()),
            'orderTypeSplit' => Inertia::defer(fn () => $dashboard->orderTypeSplit()),
            'revenueSplit' => Inertia::defer(fn () => $dashboard->revenueSplit()),
            'cohortRetention' => Inertia::defer(fn () => $dashboard->cohortRetention()),
            'timeToSecondOrder' => Inertia::defer(fn () => $dashboard->timeToSecondOrder()),
            'retentionByRegion' => Inertia::defer(fn () => $dashboard->retentionByRegion()),
            'aovTrend' => Inertia::defer(fn () => $dashboard->aovTrend()),
            'topProductsFirst' => Inertia::defer(fn () => $dashboard->topProductsFirstOrder()),
            'topProductsReturning' => Inertia::defer(fn () => $dashboard->topProductsReturning()),
        ]);
    }
}
