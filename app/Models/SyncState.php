<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncState extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'step',
        'last_synced_at',
        'duration_seconds',
        'records_synced',
        'was_full_sync',
        'status',
        'cursor',
        'started_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_synced_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'was_full_sync' => 'boolean',
            'cursor' => 'array',
        ];
    }

    public static function lastSyncedAt(string $step): ?CarbonImmutable
    {
        return static::where('step', $step)
            ->whereNotNull('last_synced_at')
            ->value('last_synced_at');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getCursor(string $step): ?array
    {
        return static::where('step', $step)
            ->where('status', '!=', 'completed')
            ->value('cursor');
    }

    public static function markRunning(string $step): void
    {
        static::updateOrCreate(
            ['step' => $step],
            ['status' => 'running', 'started_at' => now()],
        );
    }

    /**
     * @param  array<string, mixed>  $cursor
     */
    public static function saveCursor(string $step, array $cursor, int $recordsProcessed): void
    {
        static::updateOrCreate(
            ['step' => $step],
            [
                'status' => 'idle',
                'cursor' => $cursor,
                'records_synced' => $recordsProcessed,
            ],
        );
    }

    public static function markCompleted(string $step, float $duration, int $records, bool $wasFull): void
    {
        static::updateOrCreate(
            ['step' => $step],
            [
                'status' => 'completed',
                'last_synced_at' => now(),
                'duration_seconds' => $duration,
                'records_synced' => $records,
                'was_full_sync' => $wasFull,
                'cursor' => null,
                'started_at' => null,
            ],
        );
    }

    public static function isIncomplete(string $step): bool
    {
        $state = static::where('step', $step)->first();

        return $state !== null && $state->cursor !== null && $state->status !== 'completed';
    }

    /**
     * Detect abandoned runs where the process crashed without completing.
     */
    public static function isStale(string $step, int $timeoutSeconds = 360): bool
    {
        $state = static::where('step', $step)
            ->where('status', 'running')
            ->first();

        if (! $state || ! $state->started_at) {
            return false;
        }

        return $state->started_at->diffInSeconds(now()) > $timeoutSeconds;
    }
}
