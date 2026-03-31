<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scenario extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ScenarioAssumption, $this>
     */
    public function assumptions(): HasMany
    {
        return $this->hasMany(ScenarioAssumption::class);
    }

    /**
     * @return HasMany<ScenarioProductMix, $this>
     */
    public function productMixes(): HasMany
    {
        return $this->hasMany(ScenarioProductMix::class);
    }

    /**
     * @param  Builder<Scenario>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<Scenario>  $query
     */
    public function scopeForYear(Builder $query, int $year): void
    {
        $query->where('year', $year);
    }
}
