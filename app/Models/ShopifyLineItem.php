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

    protected $guarded = [];

    /**
     * @return BelongsTo<ShopifyOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopifyOrder::class, 'order_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }
}
