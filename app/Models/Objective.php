<?php

namespace App\Models;

use App\Enums\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Objective extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'parent_key_result_id',
        'team',
        'title',
        'year',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'team' => Team::class,
            'year' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<KeyResult, $this>
     */
    public function keyResults(): HasMany
    {
        return $this->hasMany(KeyResult::class)->orderBy('sort_order');
    }

    /**
     * The company key result that this team objective cascades from.
     *
     * @return BelongsTo<KeyResult, $this>
     */
    public function parentKeyResult(): BelongsTo
    {
        return $this->belongsTo(KeyResult::class, 'parent_key_result_id');
    }

    /**
     * @param  Builder<Objective>  $query
     */
    public function scopeCompany(Builder $query): void
    {
        $query->whereNull('team');
    }

    /**
     * @param  Builder<Objective>  $query
     */
    public function scopeForTeam(Builder $query, Team $team): void
    {
        $query->where('team', $team);
    }

    /**
     * @param  Builder<Objective>  $query
     */
    public function scopeForYear(Builder $query, int $year): void
    {
        $query->where('year', $year);
    }

    public function isCompany(): bool
    {
        return $this->team === null;
    }
}
