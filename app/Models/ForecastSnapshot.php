<?php

namespace App\Models;

use App\Enums\ForecastRegion;
use App\Enums\ProductCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForecastSnapshot extends Model
{
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'scenario_id',
        'year_month',
        'product_category',
        'region',
        'forecasted_units',
        'forecasted_revenue',
        'actual_units',
        'actual_revenue',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_category' => ProductCategory::class,
            'region' => ForecastRegion::class,
            'forecasted_units' => 'integer',
            'forecasted_revenue' => 'decimal:2',
            'actual_units' => 'integer',
            'actual_revenue' => 'decimal:2',
            'created_at' => 'datetime',
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
     * @param  Builder<ForecastSnapshot>  $query
     */
    public function scopeForMonth(Builder $query, string $yearMonth): void
    {
        $query->where('year_month', $yearMonth);
    }

    /**
     * @param  Builder<ForecastSnapshot>  $query
     */
    public function scopeTotals(Builder $query): void
    {
        $query->whereNull('product_category');
    }
}
