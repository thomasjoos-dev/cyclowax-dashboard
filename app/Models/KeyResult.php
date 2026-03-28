<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class KeyResult extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_value' => 'decimal:2',
            'current_value' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Objective, $this>
     */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    /**
     * The team objective that cascades from this company key result.
     *
     * @return HasOne<Objective, $this>
     */
    public function childObjective(): HasOne
    {
        return $this->hasOne(Objective::class, 'parent_key_result_id');
    }

    /**
     * Progress as a fraction (0.0 – 1.0).
     */
    public function progress(): float
    {
        if ($this->target_value == 0) {
            return 0.0;
        }

        return min(1.0, max(0.0, (float) $this->current_value / (float) $this->target_value));
    }

    public function isAutoTracked(): bool
    {
        return $this->tracking_mode === 'auto' && $this->metric_key !== null;
    }
}
