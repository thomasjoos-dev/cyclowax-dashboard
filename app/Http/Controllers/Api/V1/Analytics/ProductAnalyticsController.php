<?php

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analysis\ProductAnalyticsService;
use Illuminate\Http\JsonResponse;

class ProductAnalyticsController extends Controller
{
    public function __invoke(ProductAnalyticsService $service): JsonResponse
    {
        return response()->json([
            'top_products_first' => $service->topProductsFirstOrder(),
            'top_products_returning' => $service->topProductsReturning(),
        ]);
    }
}
