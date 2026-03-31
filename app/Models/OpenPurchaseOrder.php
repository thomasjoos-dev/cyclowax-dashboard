<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpenPurchaseOrder extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:2',
            'quantity_received' => 'decimal:2',
            'quantity_open' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'date_order' => 'date',
            'date_planned' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForProduct(Builder $query, int $productId): void
    {
        $query->where('product_id', $productId);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeExpectedBefore(Builder $query, string $date): void
    {
        $query->where('date_planned', '<=', $date);
    }
}
