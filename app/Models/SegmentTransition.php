<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SegmentTransition extends Model
{
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'rider_profile_id',
        'type',
        'from_lifecycle',
        'to_lifecycle',
        'from_segment',
        'to_segment',
        'occurred_at',
    ];

    /**
     * @return BelongsTo<RiderProfile, $this>
     */
    public function riderProfile(): BelongsTo
    {
        return $this->belongsTo(RiderProfile::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }
}
