<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopifyCustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shopify_id' => $this->shopify_id,
            'email' => $this->email,
            'orders_count' => $this->orders_count,
            'total_spent' => (float) $this->total_spent,
            'first_order_at' => $this->first_order_at?->toIso8601String(),
            'last_order_at' => $this->last_order_at?->toIso8601String(),
            'country_code' => $this->country_code,
        ];
    }
}
