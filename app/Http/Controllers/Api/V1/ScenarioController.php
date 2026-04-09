<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Scenario;
use App\Services\Forecast\Demand\DemandForecastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScenarioController extends Controller
{
    public function index(): JsonResponse
    {
        $scenarios = Scenario::query()
            ->active()
            ->get(['id', 'name', 'label', 'year', 'description', 'is_active']);

        return response()->json(['data' => $scenarios]);
    }

    public function show(Scenario $scenario): JsonResponse
    {
        $scenario->load(['assumptions', 'productMixes']);

        return response()->json(['data' => $scenario]);
    }

    public function forecast(Request $request, Scenario $scenario, DemandForecastService $service): JsonResponse
    {
        $year = $request->integer('year', $scenario->year ?? (int) date('Y'));

        $total = $service->totalForecast($scenario, $year);
        $categories = $service->forecastYear($scenario, $year);

        return response()->json([
            'data' => [
                'scenario_id' => $scenario->id,
                'year' => $year,
                'total' => $total,
                'categories' => $categories,
            ],
        ]);
    }
}
