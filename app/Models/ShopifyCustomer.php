<?php

namespace App\Models;

use App\Enums\CustomerSegment;
use Database\Factories\ShopifyCustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ShopifyCustomer extends Model
{
    /** @use HasFactory<ShopifyCustomerFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return HasMany<ShopifyOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(ShopifyOrder::class, 'customer_id');
    }

    /**
     * @return HasOne<RiderProfile, $this>
     */
    public function riderProfile(): HasOne
    {
        return $this->hasOne(RiderProfile::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeReturning(Builder $query): void
    {
        $query->where('orders_count', '>', 1);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orders_count' => 'integer',
            'total_spent' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'first_order_at' => 'datetime',
            'last_order_at' => 'datetime',
            'r_score' => 'integer',
            'f_score' => 'integer',
            'm_score' => 'integer',
            'rfm_segment' => CustomerSegment::class,
            'previous_rfm_segment' => CustomerSegment::class,
            'rfm_scored_at' => 'datetime',
            'shopify_created_at' => 'datetime',
            'gender_probability' => 'float',
        ];
    }
}
