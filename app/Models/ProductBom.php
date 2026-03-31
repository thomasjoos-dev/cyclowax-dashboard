<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductBom extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bom_type' => 'string',
            'product_qty' => 'decimal:4',
            'assembly_lead_time_days' => 'float',
            'assembly_time_samples' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeNormal(Builder $query): void
    {
        $query->where('bom_type', 'normal');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePhantom(Builder $query): void
    {
        $query->where('bom_type', 'phantom');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<ProductBomLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ProductBomLine::class, 'bom_id');
    }
}
