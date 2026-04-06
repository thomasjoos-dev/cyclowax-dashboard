<?php

namespace App\Models;

use App\Enums\ProductCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseCalendarEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'product_category' => ProductCategory::class,
            'quantity' => 'decimal:2',
            'gross_quantity' => 'decimal:2',
            'net_quantity' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PurchaseCalendarRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PurchaseCalendarRun::class, 'run_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @param  Builder<PurchaseCalendarEvent>  $query
     */
    public function scopePurchases(Builder $query): void
    {
        $query->where('event_type', 'purchase');
    }

    /**
     * @param  Builder<PurchaseCalendarEvent>  $query
     */
    public function scopeReceipts(Builder $query): void
    {
        $query->where('event_type', 'receipt');
    }

    /**
     * @param  Builder<PurchaseCalendarEvent>  $query
     */
    public function scopeProductionStarts(Builder $query): void
    {
        $query->where('event_type', 'production_start');
    }

    /**
     * @param  Builder<PurchaseCalendarEvent>  $query
     */
    public function scopeForMonth(Builder $query, string $monthLabel): void
    {
        $query->where('month_label', $monthLabel);
    }

    /**
     * @param  Builder<PurchaseCalendarEvent>  $query
     */
    public function scopeForCategory(Builder $query, ProductCategory $category): void
    {
        $query->where('product_category', $category->value);
    }
}
