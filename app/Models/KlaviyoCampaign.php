<?php

namespace App\Models;

use Database\Factories\KlaviyoCampaignFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KlaviyoCampaign extends Model
{
    /** @use HasFactory<KlaviyoCampaignFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'klaviyo_id',
        'name',
        'channel',
        'status',
        'archived',
        'send_strategy',
        'is_tracking_opens',
        'is_tracking_clicks',
        'recipients',
        'delivered',
        'bounced',
        'opens',
        'opens_unique',
        'clicks',
        'clicks_unique',
        'unsubscribes',
        'conversions',
        'conversion_value',
        'revenue_per_recipient',
        'scheduled_at',
        'send_time',
        'klaviyo_created_at',
        'klaviyo_updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived' => 'boolean',
            'is_tracking_opens' => 'boolean',
            'is_tracking_clicks' => 'boolean',
            'recipients' => 'integer',
            'delivered' => 'integer',
            'bounced' => 'integer',
            'opens' => 'integer',
            'opens_unique' => 'integer',
            'clicks' => 'integer',
            'clicks_unique' => 'integer',
            'unsubscribes' => 'integer',
            'conversions' => 'integer',
            'conversion_value' => 'decimal:2',
            'revenue_per_recipient' => 'decimal:4',
            'scheduled_at' => 'datetime',
            'send_time' => 'datetime',
            'klaviyo_created_at' => 'datetime',
            'klaviyo_updated_at' => 'datetime',
        ];
    }
}
