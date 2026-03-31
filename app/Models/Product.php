<?php

namespace App\Models;

use App\Enums\HeaterGeneration;
use App\Enums\JourneyPhase;
use App\Enums\PortfolioRole;
use App\Enums\ProductCategory;
use App\Enums\WaxRecipe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->where('is_discontinued', false);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeDiscontinued(Builder $query): void
    {
        $query->where('is_discontinued', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeByCategory(Builder $query, ProductCategory $category): void
    {
        $query->where('product_category', $category);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:4',
            'list_price' => 'decimal:2',
            'weight' => 'decimal:3',
            'is_active' => 'boolean',
            'is_discontinued' => 'boolean',
            'discontinued_at' => 'date',
            'last_synced_at' => 'datetime',
            'product_category' => ProductCategory::class,
            'portfolio_role' => PortfolioRole::class,
            'journey_phase' => JourneyPhase::class,
            'wax_recipe' => WaxRecipe::class,
            'heater_generation' => HeaterGeneration::class,
        ];
    }

    /**
     * @return HasMany<ShopifyLineItem, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(ShopifyLineItem::class);
    }

    /**
     * @return HasMany<ProductStockSnapshot, $this>
     */
    public function stockSnapshots(): HasMany
    {
        return $this->hasMany(ProductStockSnapshot::class);
    }

    /**
     * @return HasOne<ProductBom, $this>
     */
    public function bom(): HasOne
    {
        return $this->hasOne(ProductBom::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function successor(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'successor_product_id');
    }
}
