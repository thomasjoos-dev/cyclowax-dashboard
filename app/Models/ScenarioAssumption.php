<?php

namespace App\Models;

use App\Enums\ForecastRegion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScenarioAssumption extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'scenario_id',
        'quarter',
        'acq_rate',
        'repeat_rate',
        'repeat_aov',
        'region',
        'retention_index',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'region' => ForecastRegion::class,
            'acq_rate' => 'decimal:4',
            'repeat_rate' => 'decimal:4',
            'repeat_aov' => 'decimal:2',
            'retention_index' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Scenario, $this>
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }
}
