<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdSpend extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'date',
        'platform',
        'campaign_name',
        'campaign_id',
        'country_code',
        'channel_type',
        'spend',
        'impressions',
        'clicks',
        'conversions',
        'conversions_value',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'spend' => 'decimal:2',
            'conversions' => 'decimal:2',
            'conversions_value' => 'decimal:2',
        ];
    }
}
