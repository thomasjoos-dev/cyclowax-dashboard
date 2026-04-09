<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SyncState;
use Illuminate\Http\JsonResponse;

class SyncStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $states = SyncState::all()->map(fn (SyncState $state) => [
            'step' => $state->step,
            'status' => $state->status,
            'last_synced_at' => $state->last_synced_at?->toIso8601String(),
            'age' => $state->last_synced_at?->diffForHumans() ?? 'never',
            'duration_seconds' => $state->duration_seconds,
            'records_synced' => $state->records_synced,
            'was_full_sync' => $state->was_full_sync,
        ]);

        return response()->json(['data' => $states]);
    }
}
