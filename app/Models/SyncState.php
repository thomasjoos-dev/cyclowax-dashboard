<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class SyncState extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_synced_at' => 'immutable_datetime',
            'was_full_sync' => 'boolean',
        ];
    }

    public static function lastSyncedAt(string $step): ?CarbonImmutable
    {
        return static::where('step', $step)
            ->whereNotNull('last_synced_at')
            ->value('last_synced_at');
    }
}
