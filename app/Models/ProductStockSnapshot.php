<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStockSnapshot extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'product_id',
        'qty_on_hand',
        'qty_forecasted',
        'qty_free',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty_on_hand' => 'decimal:2',
            'qty_forecasted' => 'decimal:2',
            'qty_free' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
