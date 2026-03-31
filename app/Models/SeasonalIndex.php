<?php

namespace App\Models;

use App\Enums\ForecastGroup;
use App\Enums\ProductCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeasonalIndex extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'integer',
            'index_value' => 'decimal:4',
            'product_category' => ProductCategory::class,
            'forecast_group' => ForecastGroup::class,
        ];
    }

    /**
     * @param  Builder<SeasonalIndex>  $query
     */
    public function scopeGlobal(Builder $query): void
    {
        $query->whereNull('region');
    }

    /**
     * @param  Builder<SeasonalIndex>  $query
     */
    public function scopeForRegion(Builder $query, string $region): void
    {
        $query->where('region', $region);
    }

    /**
     * @param  Builder<SeasonalIndex>  $query
     */
    public function scopeForCategory(Builder $query, ProductCategory $category): void
    {
        $query->where('product_category', $category->value);
    }

    /**
     * @param  Builder<SeasonalIndex>  $query
     */
    public function scopeForGroup(Builder $query, ForecastGroup $group): void
    {
        $query->where('forecast_group', $group->value);
    }
}
