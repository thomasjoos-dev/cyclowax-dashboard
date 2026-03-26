<?php

namespace App\Models;

use Database\Factories\ShopifyCustomerFactory;
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
     * @return HasOne<CustomerProfile, $this>
     */
    public function customerProfile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_spent' => 'decimal:2',
            'first_order_at' => 'datetime',
            'last_order_at' => 'datetime',
            'r_score' => 'integer',
            'f_score' => 'integer',
            'm_score' => 'integer',
            'rfm_scored_at' => 'datetime',
        ];
    }
}
