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
            ->orderByDesc('ordered_at');

        if ($request->has('from')) {
            $query->where('ordered_at', '>=', $request->validated('from'));
        }

        if ($request->has('to')) {
            $query->where('ordered_at', '<=', $request->validated('to'));
        }

        if ($request->has('shipping_country')) {
            $query->where('shipping_country_code', $request->validated('shipping_country'));
        }

        if ($request->has('billing_country')) {
            $query->where('billing_country_code', $request->validated('billing_country'));
        }

        if ($request->has('financial_status')) {
            $query->where('financial_status', $request->validated('financial_status'));
        }

        return ShopifyOrderResource::collection(
            $query->paginate($request->integer('per_page', 50))
        );
    }

    public function show(ShopifyOrder $order): ShopifyOrderResource
    {
        return new ShopifyOrderResource($order->load(['customer', 'lineItems']));
    }
}
