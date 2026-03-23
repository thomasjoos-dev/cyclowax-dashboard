<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShopifyCustomerResource;
use App\Models\ShopifyCustomer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ShopifyCustomer::query()
            ->orderByDesc('last_order_at');

        if ($request->has('from')) {
            $query->where('first_order_at', '>=', $request->query('from'));
        }

        if ($request->has('to')) {
            $query->where('first_order_at', '<=', $request->query('to'));
        }

        if ($request->has('country_code')) {
            $query->where('country_code', $request->query('country_code'));
        }

        if ($request->has('min_orders')) {
            $query->where('orders_count', '>=', $request->integer('min_orders'));
        }

        return ShopifyCustomerResource::collection(
            $query->paginate($request->integer('per_page', 50))
        );
    }

    public function show(ShopifyCustomer $customer): ShopifyCustomerResource
    {
        return new ShopifyCustomerResource($customer);
    }
}
