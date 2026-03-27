<?php

namespace App\Models;

use App\Enums\LifecycleStage;
use Database\Factories\RiderProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiderProfile extends Model
{
    /** @use HasFactory<RiderProfileFactory> */
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
     * @return HasMany<SegmentTransition, $this>
     */
    public function segmentTransitions(): HasMany
    {
        return $this->hasMany(SegmentTransition::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lifecycle_stage' => LifecycleStage::class,
            'engagement_score' => 'integer',
            'intent_score' => 'integer',
            'linked_at' => 'datetime',
            'segment_changed_at' => 'datetime',
            'klaviyo_synced_at' => 'datetime',
            'shopify_synced_at' => 'datetime',
        ];
    }
}
