<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopifyOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shopify_id' => $this->shopify_id,
            'name' => $this->name,
            'ordered_at' => $this->ordered_at?->toIso8601String(),
            'total_price' => (float) $this->total_price,
            'subtotal' => (float) $this->subtotal,
            'shipping' => (float) $this->shipping,
            'tax' => (float) $this->tax,
            'discounts' => (float) $this->discounts,
            'refunded' => (float) $this->refunded,
            'net_revenue' => (float) ($this->total_price - $this->tax),
            'financial_status' => $this->financial_status,
            'fulfillment_status' => $this->fulfillment_status,
            'billing_country_code' => $this->billing_country_code,
            'billing_province_code' => $this->billing_province_code,
            'billing_postal_code' => $this->billing_postal_code,
            'shipping_country_code' => $this->shipping_country_code,
            'shipping_province_code' => $this->shipping_province_code,
            'shipping_postal_code' => $this->shipping_postal_code,
            'currency' => $this->currency,
            'customer' => new ShopifyCustomerResource($this->whenLoaded('customer')),
            'line_items' => ShopifyLineItemResource::collection($this->whenLoaded('lineItems')),
        ];
    }
}
