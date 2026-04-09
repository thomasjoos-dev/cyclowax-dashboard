<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpenPurchaseOrder extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'odoo_po_line_id',
        'po_reference',
        'product_id',
        'odoo_product_id',
        'product_name',
        'quantity_ordered',
        'quantity_received',
        'quantity_open',
        'unit_price',
        'date_order',
        'date_planned',
        'supplier_name',
        'state',
    ];

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
