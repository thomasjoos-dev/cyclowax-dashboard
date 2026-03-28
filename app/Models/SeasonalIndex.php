<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeasonalIndex extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'integer',
            'index_value' => 'decimal:4',
        ];
    }

    /**
     * @param  Builder<SeasonalIndex>  $query
     */
    public function scopeGlobal(Builder $query): void
    {
        $query->whereNull('region');
    }

    /**
     * @param  Builder<SeasonalIndex>  $query
     */
    public function scopeForRegion(Builder $query, string $region): void
    {
        $query->where('region', $region);
    }
}
