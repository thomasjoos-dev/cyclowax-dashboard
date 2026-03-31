<?php

namespace App\Models;

use App\Enums\ProductCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandEventCategory extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_category' => ProductCategory::class,
            'expected_uplift_units' => 'integer',
            'pull_forward_pct' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<DemandEvent, $this>
     */
    public function demandEvent(): BelongsTo
    {
        return $this->belongsTo(DemandEvent::class);
    }
}
