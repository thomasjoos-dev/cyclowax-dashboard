<?php

namespace App\Models;

use App\Enums\DemandEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemandEvent extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DemandEventType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'is_historical' => 'boolean',
        ];
    }

    /**
     * @return HasMany<DemandEventCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(DemandEventCategory::class);
    }

    /**
     * @param  Builder<DemandEvent>  $query
     */
    public function scopeHistorical(Builder $query): void
    {
        $query->where('is_historical', true);
    }

    /**
     * @param  Builder<DemandEvent>  $query
     */
    public function scopePlanned(Builder $query): void
    {
        $query->where('is_historical', false);
    }

    /**
     * @param  Builder<DemandEvent>  $query
     */
    public function scopeOverlapping(Builder $query, string $from, string $to): void
    {
        $query->where('start_date', '<=', $to)
            ->where('end_date', '>=', $from);
    }
}
