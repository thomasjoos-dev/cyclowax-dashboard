<?php

namespace App\Models;

use Database\Factories\CustomerProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProfile extends Model
{
    /** @use HasFactory<CustomerProfileFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return BelongsTo<ShopifyCustomer, $this>
     */
    public function shopifyCustomer(): BelongsTo
    {
        return $this->belongsTo(ShopifyCustomer::class);
    }

    /**
     * @return BelongsTo<KlaviyoProfile, $this>
     */
    public function klaviyoProfile(): BelongsTo
    {
        return $this->belongsTo(KlaviyoProfile::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'engagement_score' => 'integer',
            'intent_score' => 'integer',
            'linked_at' => 'datetime',
        ];
    }
}
