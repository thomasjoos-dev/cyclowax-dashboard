<?php

namespace App\Models;

use Database\Factories\ShopifyOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyOrder extends Model
{
    /** @use HasFactory<ShopifyOrderFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return BelongsTo<ShopifyCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(ShopifyCustomer::class, 'customer_id');
    }

    /**
     * @return HasMany<ShopifyLineItem, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(ShopifyLineItem::class, 'order_id');
    }

    /**
     * Exclude voided and refunded orders — the most common filter in the codebase.
     *
     * @param  Builder<self>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->whereNotIn('financial_status', ['voided', 'refunded', 'VOIDED', 'REFUNDED']);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePaid(Builder $query): void
    {
        $query->whereIn('financial_status', ['paid', 'PAID']);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'total_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'shipping' => 'decimal:2',
            'tax' => 'decimal:2',
            'discounts' => 'decimal:2',
            'refunded' => 'decimal:2',
            'net_revenue' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'payment_fee' => 'decimal:2',
            'gross_margin' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'shipping_margin' => 'decimal:2',
            'is_first_order' => 'boolean',
            'shipping_cost_estimated' => 'boolean',
        ];
    }
}
