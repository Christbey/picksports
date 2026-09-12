<?php

namespace App\Services\CFB\Live;

use App\Models\CFB\Game;
use App\Models\CFB\LivePredictionSnapshot;

class LiveSnapshotRecorder
{
    public function baseline(Game $game): ?array
    {
        $existing = LivePredictionSnapshot::where('game_id', $game->id)->oldest('id')->first();
        if ($existing) {
            return $existing->pregame;
        }
        $prediction = $game->prediction;
        if (! $prediction) {
            return null;
        }

        return ['prediction_id' => $prediction->id, 'spread' => $prediction->predicted_spread !== null ? (float) $prediction->predicted_spread : null,
            'total' => $prediction->predicted_total !== null ? (float) $prediction->predicted_total : null,
            'home_win_probability' => $prediction->win_probability !== null ? (float) $prediction->win_probability : null,
            'model_version' => $prediction->model_version, 'captured_at' => now()->toIso8601String()];
    }

    public function record(Game $game, array $baseline, ?array $projection, string $status, string $source = 'scoreboard', array $markets = [], array $props = []): LivePredictionSnapshot
    {
        $state = ['status' => $game->status, 'period' => $game->period, 'clock' => $game->game_clock,
            'home_score' => $game->home_score, 'away_score' => $game->away_score];
        $hash = hash('sha256', json_encode([$game->id, now()->format('Y-m-d H:i'), $state, $baseline, $projection, $status, $source, $markets, $props]));

        return LivePredictionSnapshot::firstOrCreate(['state_hash' => $hash], [
            'game_id' => $game->id, 'prediction_id' => $baseline['prediction_id'], 'source' => $source,
            'pregame' => $baseline, 'state' => $state, 'projection' => $projection, 'markets' => $markets, 'props' => $props,
            'status' => $status, 'observed_at' => now(),
        ]);
    }
}
