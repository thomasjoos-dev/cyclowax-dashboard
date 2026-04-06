<?php

namespace App\Models;

use App\Enums\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseCalendarRun extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'warehouse' => Warehouse::class,
            'generated_at' => 'datetime',
            'summary' => 'array',
            'netting_summary' => 'array',
            'sku_mix_summary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Scenario, $this>
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    /**
     * @return HasMany<PurchaseCalendarEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(PurchaseCalendarEvent::class, 'run_id');
    }

    /**
     * @param  Builder<PurchaseCalendarRun>  $query
     */
    public function scopeForScenario(Builder $query, Scenario $scenario): void
    {
        $query->where('scenario_id', $scenario->id);
    }

    /**
     * @param  Builder<PurchaseCalendarRun>  $query
     */
    public function scopeForYear(Builder $query, int $year): void
    {
        $query->where('year', $year);
    }

    /**
     * @param  Builder<PurchaseCalendarRun>  $query
     */
    public function scopeForWarehouse(Builder $query, ?Warehouse $warehouse): void
    {
        if ($warehouse === null) {
            $query->whereNull('warehouse');
        } else {
            $query->where('warehouse', $warehouse->value);
        }
    }
}
