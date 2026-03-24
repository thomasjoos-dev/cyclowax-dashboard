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
            'net_revenue' => (float) ($this->total_price - $this->tax - $this->refunded),
            'financial_status' => $this->financial_status,
            'fulfillment_status' => $this->fulfillment_status,
            'billing_country_code' => $this->billing_country_code,
            'billing_province_code' => $this->billing_province_code,
            'billing_postal_code' => $this->billing_postal_code,
            'shipping_country_code' => $this->shipping_country_code,
            'shipping_province_code' => $this->shipping_province_code,
            'shipping_postal_code' => $this->shipping_postal_code,
            'currency' => $this->currency,
            'discount_codes' => $this->discount_codes,
            'total_cost' => $this->total_cost ? (float) $this->total_cost : null,
            'payment_fee' => $this->payment_fee ? (float) $this->payment_fee : null,
            'gross_margin' => $this->gross_margin ? (float) $this->gross_margin : null,
            'is_first_order' => $this->is_first_order,
            'attribution' => [
                'source_name' => $this->source_name,
                'landing_page_url' => $this->landing_page_url,
                'referrer_url' => $this->referrer_url,
                'first_touch' => [
                    'source' => $this->ft_source,
                    'source_type' => $this->ft_source_type,
                    'utm_source' => $this->ft_utm_source,
                    'utm_medium' => $this->ft_utm_medium,
                    'utm_campaign' => $this->ft_utm_campaign,
                    'utm_content' => $this->ft_utm_content,
                    'utm_term' => $this->ft_utm_term,
                    'landing_page' => $this->ft_landing_page,
                    'referrer_url' => $this->ft_referrer_url,
                ],
                'last_touch' => [
                    'source' => $this->lt_source,
                    'source_type' => $this->lt_source_type,
                    'utm_source' => $this->lt_utm_source,
                    'utm_medium' => $this->lt_utm_medium,
                    'utm_campaign' => $this->lt_utm_campaign,
                    'utm_content' => $this->lt_utm_content,
                    'utm_term' => $this->lt_utm_term,
                    'landing_page' => $this->lt_landing_page,
                    'referrer_url' => $this->lt_referrer_url,
                ],
            ],
            'customer' => new ShopifyCustomerResource($this->whenLoaded('customer')),
            'line_items' => ShopifyLineItemResource::collection($this->whenLoaded('lineItems')),
        ];
    }
}
