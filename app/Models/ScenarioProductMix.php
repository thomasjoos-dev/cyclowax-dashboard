<?php

namespace App\Models;

use App\Enums\ForecastRegion;
use App\Enums\ProductCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScenarioProductMix extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'scenario_id',
        'product_category',
        'region',
        'product_id',
        'sku_share',
        'acq_share',
        'repeat_share',
        'avg_unit_price',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_category' => ProductCategory::class,
            'region' => ForecastRegion::class,
            'acq_share' => 'decimal:4',
            'repeat_share' => 'decimal:4',
            'avg_unit_price' => 'decimal:2',
            'product_id' => 'integer',
            'sku_share' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Scenario, $this>
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Scope to SKU-level override rows for a given category.
     *
     * @param  Builder<ScenarioProductMix>  $query
     * @return Builder<ScenarioProductMix>
     */
    public function scopeSkuOverrides(Builder $query, ProductCategory $category): Builder
    {
        return $query->where('product_category', $category)
            ->whereNotNull('product_id');
    }
}
