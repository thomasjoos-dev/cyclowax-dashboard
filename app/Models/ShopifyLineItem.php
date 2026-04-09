<?php

namespace App\Models;

use Database\Factories\ShopifyLineItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyLineItem extends Model
{
    /** @use HasFactory<ShopifyLineItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'order_id',
        'product_title',
        'product_type',
        'sku',
        'quantity',
        'price',
        'product_id',
        'cost_price',
    ];

    /**
     * @return BelongsTo<ShopifyOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopifyOrder::class, 'order_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost_price' => 'decimal:4',
        ];
    }
}
