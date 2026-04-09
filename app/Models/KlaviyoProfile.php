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

    /** @var list<string> */
    protected $fillable = [
        'klaviyo_id',
        'email',
        'phone_number',
        'external_id',
        'first_name',
        'last_name',
        'organization',
        'city',
        'region',
        'country',
        'zip',
        'timezone',
        'properties',
        'historic_clv',
        'predicted_clv',
        'total_clv',
        'historic_number_of_orders',
        'predicted_number_of_orders',
        'average_order_value',
        'churn_probability',
        'average_days_between_orders',
        'expected_date_of_next_order',
        'last_event_date',
        'klaviyo_created_at',
        'klaviyo_updated_at',
        'emails_received',
        'emails_opened',
        'emails_clicked',
        'engagement_synced_at',
        'site_visits',
        'product_views',
        'cart_adds',
        'checkouts_started',
        'is_suspect',
        'suspect_reason',
    ];

    /**
     * @return HasOne<RiderProfile, $this>
     */
    public function riderProfile(): HasOne
    {
        return $this->hasOne(RiderProfile::class);
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
            'historic_number_of_orders' => 'decimal:2',
            'predicted_number_of_orders' => 'decimal:2',
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
