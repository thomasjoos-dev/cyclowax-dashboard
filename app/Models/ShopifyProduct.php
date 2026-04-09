<?php

namespace App\Models;

use Database\Factories\ShopifyProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ShopifyProduct extends Model
{
    /** @use HasFactory<ShopifyProductFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'shopify_id',
        'title',
        'product_type',
        'status',
    ];

    /**
     * @return HasOne<Product, $this>
     */
    public function product(): HasOne
    {
        return $this->hasOne(Product::class, 'shopify_product_id', 'shopify_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shopify_id' => 'integer',
        ];
    }
}
