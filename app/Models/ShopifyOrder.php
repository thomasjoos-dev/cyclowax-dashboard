<?php

namespace App\Models;

use Database\Factories\ShopifyOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyOrder extends Model
{
    /** @use HasFactory<ShopifyOrderFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'shopify_id',
        'name',
        'ordered_at',
        'total_price',
        'subtotal',
        'shipping',
        'tax',
        'discounts',
        'refunded',
        'net_revenue',
        'financial_status',
        'fulfillment_status',
        'customer_id',
        'billing_country_code',
        'billing_province_code',
        'billing_postal_code',
        'shipping_country_code',
        'shipping_province_code',
        'shipping_postal_code',
        'currency',
        'total_cost',
        'gross_margin',
        'is_first_order',
        'local_orders_count',
        'first_order_channel',
        'landing_page_url',
        'referrer_url',
        'source_name',
        'ft_source',
        'ft_source_type',
        'ft_utm_source',
        'ft_utm_medium',
        'ft_utm_campaign',
        'ft_utm_content',
        'ft_utm_term',
        'ft_landing_page',
        'ft_referrer_url',
        'lt_source',
        'lt_source_type',
        'lt_utm_source',
        'lt_utm_medium',
        'lt_utm_campaign',
        'lt_utm_content',
        'lt_utm_term',
        'lt_landing_page',
        'lt_referrer_url',
        'discount_codes',
        'payment_fee',
        'shipping_cost',
        'shipping_carrier',
        'shipping_cost_estimated',
        'shipping_margin',
        'channel_type',
        'refined_channel',
    ];

    /**
     * @return BelongsTo<ShopifyCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(ShopifyCustomer::class, 'customer_id');
    }

    /**
     * @return HasMany<ShopifyLineItem, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(ShopifyLineItem::class, 'order_id');
    }

    /**
     * Exclude voided and refunded orders — the most common filter in the codebase.
     *
     * @param  Builder<self>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->whereNotIn('financial_status', ['voided', 'refunded', 'VOIDED', 'REFUNDED']);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePaid(Builder $query): void
    {
        $query->whereIn('financial_status', ['paid', 'PAID']);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'total_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'shipping' => 'decimal:2',
            'tax' => 'decimal:2',
            'discounts' => 'decimal:2',
            'refunded' => 'decimal:2',
            'net_revenue' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'payment_fee' => 'decimal:2',
            'gross_margin' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'shipping_margin' => 'decimal:2',
            'is_first_order' => 'boolean',
            'shipping_cost_estimated' => 'boolean',
        ];
    }
}
