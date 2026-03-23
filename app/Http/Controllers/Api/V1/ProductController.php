<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShopifyProductResource;
use App\Models\ShopifyProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ShopifyProduct::query()
            ->orderBy('title');

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('product_type')) {
            $query->where('product_type', $request->query('product_type'));
        }

        return ShopifyProductResource::collection(
            $query->paginate($request->integer('per_page', 50))
        );
    }

    public function show(ShopifyProduct $product): ShopifyProductResource
    {
        return new ShopifyProductResource($product);
    }
}
