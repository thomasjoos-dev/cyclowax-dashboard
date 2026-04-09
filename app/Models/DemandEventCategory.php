<?php

namespace App\Models;

use App\Enums\ProductCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemandEventCategory extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'demand_event_id',
        'product_category',
        'expected_uplift_units',
        'pull_forward_pct',
        'is_incremental',
        'product_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_category' => ProductCategory::class,
            'expected_uplift_units' => 'integer',
            'pull_forward_pct' => 'decimal:2',
            'is_incremental' => 'boolean',
            'product_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DemandEvent, $this>
     */
    public function demandEvent(): BelongsTo
    {
        return $this->belongsTo(DemandEvent::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Whether this category effect targets a specific product (SKU-level).
     */
    public function isProductTargeted(): bool
    {
        return $this->product_id !== null;
    }
}
