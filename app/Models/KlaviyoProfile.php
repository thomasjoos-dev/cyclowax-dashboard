<?php

namespace App\Models;

use Database\Factories\KlaviyoProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class KlaviyoProfile extends Model
{
    /** @use HasFactory<KlaviyoProfileFactory> */
    use HasFactory;

    protected $guarded = [];

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
            'properties' => 'array',
            'historic_clv' => 'decimal:2',
            'predicted_clv' => 'decimal:2',
            'total_clv' => 'decimal:2',
            'historic_number_of_orders' => 'integer',
            'predicted_number_of_orders' => 'integer',
            'average_order_value' => 'decimal:2',
            'churn_probability' => 'decimal:4',
            'average_days_between_orders' => 'decimal:2',
            'expected_date_of_next_order' => 'datetime',
            'last_event_date' => 'datetime',
            'klaviyo_created_at' => 'datetime',
            'klaviyo_updated_at' => 'datetime',
            'emails_received' => 'integer',
            'emails_opened' => 'integer',
            'emails_clicked' => 'integer',
            'engagement_synced_at' => 'datetime',
            'is_suspect' => 'boolean',
            'site_visits' => 'integer',
            'product_views' => 'integer',
            'cart_adds' => 'integer',
            'checkouts_started' => 'integer',
        ];
    }
}
