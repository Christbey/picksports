<?php

namespace App\Services\CFB\Predictions;

use App\Actions\CFB\CalculateElo;
use App\Models\CFB\EloRating;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CfbPointInTimeElo
{
    public function forTeam(int $teamId, int $season, CarbonImmutable $capturedAt, CarbonImmutable $cutoffAt, float $default = 1500): array
    {
        $row = $this->latestObserved($teamId, $season, $capturedAt, $cutoffAt);
        /* Rating and season initialization are read under the builder's shared Elo write lock. */
        $initial = DB::table('cfb_elo_season_initializations')->where('team_id', $teamId)
            ->where('season', $season)->where('active_slot', 1)
            ->where('created_at', '<=', $capturedAt)->where('created_at', '<', $cutoffAt)
            ->orderByDesc('id')->first();
        if ($row === null && $initial === null) {
            $prior = $this->latestObserved($teamId, $season - 1, $capturedAt, $cutoffAt);
            if ($prior && $prior->model_version === CalculateElo::MODEL_VERSION && $prior->season_initialization_id
                && is_string($prior->result_fingerprint)
                && hash_equals($prior->result_fingerprint, app(CalculateElo::class)->fingerprint($prior->game))
                && DB::table('cfb_elo_season_initializations')->where('id', $prior->season_initialization_id)
                    ->where('team_id', $teamId)->where('season', $season - 1)->where('active_slot', 1)
                    ->where('model_version', CalculateElo::MODEL_VERSION)->where('created_at', '<=', $capturedAt)
                    ->where('created_at', '<', $cutoffAt)->exists()) {
                $factor = (float) config('cfb.elo.offseason_regression_factor', .3);
                $default = (float) config('cfb.elo.default_rating', 1500);

                return ['rating' => round((float) $prior->elo_rating * (1 - $factor) + $default * $factor),
                    'evidence' => ['status' => 'versioned_point_in_time', 'qualified' => true,
                        'model_version' => $prior->model_version, 'units' => 'elo_points', 'season' => $season,
                        'history_id' => null, 'season_initialization_id' => null, 'source_history_id' => $prior->id,
                        'source_season' => $season - 1, 'source_season_initialization_id' => $prior->season_initialization_id,
                        'regression_factor' => $factor, 'prior_rating' => (float) $prior->elo_rating,
                        'default_rating' => $default, 'configuration_hash' => hash('sha256', json_encode(config('cfb.elo'), JSON_THROW_ON_ERROR)),
                        'latest_game_id' => $prior->game_id, 'latest_game_started_at' => $prior->game->game_date->toIso8601String(),
                        'observed_at' => $prior->updated_at->toIso8601String(), 'rebuilt_at' => $prior->rebuilt_at?->toIso8601String(),
                        'source_kind' => 'derived_season_initialization']];
            }
        }
        $model = $row?->model_version ?? $initial?->model_version;
        $initialId = $row?->season_initialization_id ?? $initial?->id;
        $qualified = $model === CalculateElo::MODEL_VERSION && $initialId !== null && $initial !== null
            && (int) $initialId === (int) $initial->id && $initial->model_version === $model;

        return ['rating' => (float) ($row?->elo_rating ?? $initial?->initial_rating ?? $default),
            'evidence' => [
                'status' => $qualified ? 'versioned_point_in_time' : 'unverified_legacy_or_default',
                'qualified' => $qualified, 'model_version' => $model, 'units' => 'elo_points',
                'history_id' => $row?->id, 'season_initialization_id' => $initialId,
                'season' => $season, 'latest_game_id' => $row?->game_id,
                'latest_game_started_at' => $row?->game?->game_date?->toIso8601String(),
                'observed_at' => $row?->updated_at?->toIso8601String() ?? $initial?->created_at,
                'rebuilt_at' => $row?->rebuilt_at,
                'source_kind' => $row ? ($row->rebuilt_at ? 'retrospective_rebuild' : 'recorded_result_update') : ($initial ? 'season_initialization' : 'default'),
            ]];
    }

    private function latestObserved(int $teamId, int $season, CarbonImmutable $capturedAt, CarbonImmutable $cutoffAt): ?EloRating
    {
        $asOf = $capturedAt->min($cutoffAt);

        return EloRating::query()->where('team_id', $teamId)->where('season', $season)
            ->where('created_at', '<=', $capturedAt)->where('created_at', '<', $cutoffAt)
            ->where('updated_at', '<=', $capturedAt)->where('updated_at', '<', $cutoffAt)
            ->where(fn ($q) => $q->whereNull('rebuilt_at')->orWhere(fn ($q) => $q->where('rebuilt_at', '<=', $capturedAt)->where('rebuilt_at', '<', $cutoffAt)))
            ->whereHas('game', fn ($q) => $q->where('status', 'STATUS_FINAL')->where('game_date', '<', $asOf))
            ->with('game')->get()->sortByDesc(fn ($rating) => $rating->game->game_date->format('Y-m-d H:i:s').sprintf('%012d', $rating->game_id))->first();
    }
}
