<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopifyLineItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_title' => $this->product_title,
            'product_type' => $this->product_type,
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'price' => (float) $this->price,
        ];
    }
}
