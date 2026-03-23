<?php

namespace App\Models;

use Database\Factories\ShopifyOrderFactory;
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
        ];
    }
}
