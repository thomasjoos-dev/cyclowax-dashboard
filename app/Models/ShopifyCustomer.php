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

    /** @var list<string> */
    protected $fillable = [
        'shopify_id',
        'email',
        'first_name',
        'last_name',
        'locale',
        'tags',
        'country_code',
        'email_marketing_consent',
        'orders_count',
        'local_orders_count',
        'total_spent',
        'total_cost',
        'first_order_at',
        'last_order_at',
        'first_order_channel',
        'shopify_created_at',
        'gender',
        'gender_probability',
        'r_score',
        'f_score',
        'm_score',
        'rfm_segment',
        'previous_rfm_segment',
        'rfm_scored_at',
        'segment_synced_at',
    ];

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
