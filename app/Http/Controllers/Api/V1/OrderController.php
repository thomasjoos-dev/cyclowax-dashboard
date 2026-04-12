<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListOrdersRequest;
use App\Http\Resources\ShopifyOrderResource;
use App\Models\ShopifyOrder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function index(ListOrdersRequest $request): AnonymousResourceCollection
    {
        $query = ShopifyOrder::query()
            ->with(['customer', 'lineItems'])
            ->applyFilters($request->validated())
            ->orderByDesc('ordered_at');

        return ShopifyOrderResource::collection(
            $query->paginate($request->integer('per_page', 50))
        );
    }

    public function show(ShopifyOrder $order): ShopifyOrderResource
    {
        return new ShopifyOrderResource($order->load(['customer', 'lineItems']));
    }
}
