<?php

namespace App\Services\CFB\Live;

use App\Models\CFB\Game;

/** Read-only overlay: never replace or recalculate the published pregame forecast. */
class LiveBoardPresentation
{
    public function forGame(Game $game): array
    {
        $empty = [
            'live_predicted_spread' => null,
            'live_predicted_total' => null,
            'live_win_probability' => null,
            'live_seconds_remaining' => null,
            'live_updated_at' => null,
            'live_status' => 'inactive',
            'live_unavailable_reason' => null,
        ];
        if (! in_array($game->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'], true)) {
            return $empty;
        }

        // The canonical query eager-loads one snapshot per game, not its entire history.
        $snapshot = $game->latestLiveSnapshot;
        if (! $snapshot) {
            return [...$empty, 'live_status' => 'updating'];
        }

        $empty['live_updated_at'] = $snapshot->observed_at?->toIso8601String();
        if ($snapshot->status !== 'live' || ! is_array($snapshot->projection)) {
            $reason = match ($snapshot->status) {
                'missing_pregame' => 'missing_baseline',
                'missing_clock', 'missing_score', 'invalid_clock', 'invalid_period' => 'incomplete_game_state',
                'overtime_missing_possession' => 'overtime',
                default => 'unavailable',
            };

            return [...$empty, 'live_status' => 'unavailable', 'live_unavailable_reason' => $reason];
        }

        if (! $snapshot->observed_at || $snapshot->observed_at->isFuture() || $snapshot->observed_at->lt(now()->subMinutes(3))) {
            return [...$empty, 'live_status' => 'stale'];
        }

        $projection = $snapshot->projection;

        return [...$empty,
            // Live spread is the projected home margin, matching the legacy live contract.
            'live_predicted_spread' => $projection['spread'] ?? null,
            'live_predicted_total' => $projection['total'] ?? null,
            'live_win_probability' => $projection['home_win_probability'] ?? null,
            'live_seconds_remaining' => $projection['seconds_remaining'] ?? null,
            'live_status' => 'live',
        ];
    }
}
