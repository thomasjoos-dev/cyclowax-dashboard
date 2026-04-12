<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScenarioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $this->label,
            'year' => $this->year,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'retention_curve_adjustment' => (float) $this->retention_curve_adjustment,
            'assumptions' => $this->whenLoaded('assumptions'),
            'product_mixes' => $this->whenLoaded('productMixes'),
        ];
    }
}
