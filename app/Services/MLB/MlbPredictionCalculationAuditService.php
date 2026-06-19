<?php

namespace App\Services\MLB;

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\PredictionFeatureSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class MlbPredictionCalculationAuditService
{
    /**
     * @return array<string, mixed>
     */
    public function explain(Prediction $prediction, ?CarbonInterface $asOf = null): array
    {
        $prediction->loadMissing(['game.homeTeam', 'game.awayTeam', 'game.weather']);
        $game = $prediction->game;
        $metadata = is_array($prediction->model_metadata) ? $prediction->model_metadata : [];
        $snapshot = $this->latestSnapshot($prediction);
        $homeWinProbability = $this->nullableFloat($prediction->win_probability);
        $awayWinProbability = $homeWinProbability !== null ? round(1.0 - $homeWinProbability, 3) : null;
        $spread = $this->nullableFloat($prediction->predicted_spread);
        $total = $this->nullableFloat($prediction->predicted_total);
        $derivedScores = $this->derivedScores($spread, $total);
        $warnings = $this->warnings($prediction, $snapshot);

        return [
            'game_id' => (int) $prediction->game_id,
            'prediction_id' => (int) $prediction->id,
            'phase' => $this->phase($game),
            'as_of' => ($asOf ?? now())->toIso8601String(),
            'teams' => [
                'home' => $this->teamLabel($game?->homeTeam),
                'away' => $this->teamLabel($game?->awayTeam),
            ],
            'game' => [
                'date' => $game?->game_date?->toDateString(),
                'time' => $game?->game_time,
                'status' => $game?->status,
                'start_at' => $this->scheduledStartAt($game)?->toIso8601String(),
            ],
            'versions' => [
                'model_version' => $prediction->model_version,
                'feature_version' => $prediction->feature_version,
                'blend_version' => $prediction->blend_version,
            ],
            'input_timestamps' => [
                'odds_updated_at' => $game?->odds_updated_at?->toIso8601String(),
                'weather_observed_at' => $game?->weather?->observed_at?->toIso8601String(),
                'feature_snapshot_generated_at' => $snapshot?->generated_at?->toIso8601String(),
                'market_snapshot_captured_at' => data_get($metadata, 'market_context.safety.odds_captured_at'),
            ],
            'inputs' => [
                'team_elo' => [
                    'home' => $this->nullableFloat($prediction->home_team_elo),
                    'away' => $this->nullableFloat($prediction->away_team_elo),
                    'home_combined' => $this->nullableFloat($prediction->home_combined_elo),
                    'away_combined' => $this->nullableFloat($prediction->away_combined_elo),
                ],
                'pitcher_elo' => [
                    'home' => $this->nullableFloat($prediction->home_pitcher_elo),
                    'away' => $this->nullableFloat($prediction->away_pitcher_elo),
                    'source' => data_get($metadata, 'pitcher_inputs'),
                ],
                'team_metrics' => [
                    'season_context' => data_get($metadata, 'season_context', []),
                    'point_in_time_safety' => data_get($metadata, 'point_in_time_safety.team_metrics', []),
                ],
                'bullpen' => data_get($metadata, 'situational_context.bullpen', []),
                'injuries' => [
                    'source' => data_get($metadata, 'injury_model_source'),
                    'depth_chart_injuries' => data_get($metadata, 'depth_chart_injuries', []),
                    'probable_pitcher' => data_get($metadata, 'pitcher_inputs', []),
                ],
                'park_factor' => data_get($metadata, 'park_context', []),
                'weather' => data_get($metadata, 'actual_weather', []),
                'market' => data_get($metadata, 'market_context', []),
            ],
            'adjustments' => [
                'home_field_elo_points' => (float) (config('mlb.prediction.home_field_advantage') ?? config('mlb.elo.home_field_advantage', 0)),
                'team_strength' => $this->teamStrengthAdjustment($prediction),
                'pitcher' => $this->pitcherAdjustment($prediction),
                'context_spread' => $this->nullableFloat(data_get($metadata, 'season_context.context_spread_adjustment')),
                'historical_spread' => $this->nullableFloat(data_get($metadata, 'historical_context.spread_adjustment')),
                'situational_spread' => $this->nullableFloat(data_get($metadata, 'situational_context.spread_adjustment')),
                'injury_total' => $this->nullableFloat(data_get($metadata, 'depth_chart_injuries.total_adjustment')),
                'probable_pitcher_spread' => $this->nullableFloat(data_get($metadata, 'pitcher_inputs.probable_pitcher_spread_adjustment')),
                'probable_pitcher_total' => $this->nullableFloat(data_get($metadata, 'pitcher_inputs.probable_pitcher_total_adjustment')),
                'park_total' => $this->nullableFloat(data_get($metadata, 'park_context.total_adjustment')),
                'weather_total' => $this->nullableFloat(data_get($metadata, 'actual_weather.total_adjustment')),
                'market' => 0.0,
            ],
            'outputs' => [
                'home_win_probability' => $homeWinProbability,
                'away_win_probability' => $awayWinProbability,
                'predicted_winner' => $this->predictedWinner($prediction),
                'predicted_spread' => $spread,
                'predicted_total' => $total,
                'derived_home_score' => $derivedScores['home'],
                'derived_away_score' => $derivedScores['away'],
                'confidence' => $this->nullableFloat($prediction->confidence_score),
                'confidence_label' => $this->confidenceLabel($this->nullableFloat($prediction->confidence_score)),
            ],
            'safety' => [
                'point_in_time_safe' => $this->pointInTimeSafe($prediction),
                'market_context_safe' => (bool) data_get($metadata, 'market_context.safety.pregame_safe', false),
                'warnings' => $warnings,
                'hard_failures' => $this->hardInvariantFailures($prediction),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function hardInvariantFailures(Prediction $prediction): array
    {
        $failures = [];
        $homeProbability = $this->nullableFloat($prediction->win_probability);
        $spread = $this->nullableFloat($prediction->predicted_spread);
        $total = $this->nullableFloat($prediction->predicted_total);
        $confidence = $this->nullableFloat($prediction->confidence_score);

        foreach ([
            'win_probability' => $homeProbability,
            'predicted_spread' => $spread,
            'predicted_total' => $total,
            'confidence_score' => $confidence,
        ] as $field => $value) {
            if ($value === null || ! is_finite($value)) {
                $failures[] = "{$field}_is_missing_or_not_finite";
            }
        }

        if ($homeProbability !== null && ($homeProbability < 0.0 || $homeProbability > 1.0)) {
            $failures[] = 'home_win_probability_out_of_range';
        }

        if ($homeProbability !== null && abs(($homeProbability + (1.0 - $homeProbability)) - 1.0) > 0.001) {
            $failures[] = 'probabilities_do_not_sum_to_one';
        }

        if ($spread !== null && $homeProbability !== null) {
            if ($spread > 0 && $homeProbability < 0.5) {
                $failures[] = 'home_favored_spread_disagrees_with_probability';
            }

            if ($spread < 0 && $homeProbability > 0.5) {
                $failures[] = 'away_favored_spread_disagrees_with_probability';
            }
        }

        if ($spread !== null && $total !== null && $total < abs($spread)) {
            $failures[] = 'derived_team_score_would_be_negative';
        }

        if ($confidence !== null && ($confidence < 0.0 || $confidence > 100.0)) {
            $failures[] = 'confidence_out_of_range';
        }

        foreach (['model_version', 'feature_version', 'blend_version'] as $field) {
            if (! is_string($prediction->{$field}) || trim($prediction->{$field}) === '') {
                $failures[] = "{$field}_missing";
            }
        }

        return array_values(array_unique($failures));
    }

    /**
     * @return list<string>
     */
    public function warnings(Prediction $prediction, ?PredictionFeatureSnapshot $snapshot = null): array
    {
        $metadata = is_array($prediction->model_metadata) ? $prediction->model_metadata : [];
        $warnings = [];

        if ($snapshot === null) {
            $warnings[] = 'missing_feature_snapshot';
        }

        if (data_get($metadata, 'market_context.safety.pregame_safe') !== true) {
            $warnings[] = 'market_context_not_proven_pregame_safe';
        }

        if (data_get($metadata, 'actual_weather.applied') && ! data_get($prediction->game?->weather, 'observed_at')) {
            $warnings[] = 'weather_applied_without_observed_at_timestamp';
        }

        if (data_get($metadata, 'point_in_time_safety.team_metrics.home.pregame_safe') === false
            || data_get($metadata, 'point_in_time_safety.team_metrics.away.pregame_safe') === false) {
            $warnings[] = 'team_metric_point_in_time_limitation';
        }

        if ($prediction->live_win_probability !== null
            || $prediction->live_predicted_spread !== null
            || $prediction->live_predicted_total !== null) {
            $warnings[] = 'live_fields_present_but_not_core_pregame_inputs';
        }

        return array_values(array_unique($warnings));
    }

    public function confidenceLabel(?float $confidence): string
    {
        if ($confidence === null) {
            return 'unknown';
        }

        return match (true) {
            $confidence >= 75.0 => 'high',
            $confidence >= 60.0 => 'medium',
            default => 'low',
        };
    }

    /**
     * @return array{home:?float, away:?float}
     */
    public function derivedScores(?float $spread, ?float $total): array
    {
        if ($spread === null || $total === null) {
            return ['home' => null, 'away' => null];
        }

        return [
            'home' => round(($total + $spread) / 2, 2),
            'away' => round(($total - $spread) / 2, 2),
        ];
    }

    public function predictedWinner(Prediction $prediction): ?string
    {
        $game = $prediction->game;
        $probability = $this->nullableFloat($prediction->win_probability);

        if ($probability === null || $game === null) {
            return null;
        }

        return $probability >= 0.5
            ? $this->teamLabel($game->homeTeam)
            : $this->teamLabel($game->awayTeam);
    }

    public function latestSnapshot(Prediction $prediction): ?PredictionFeatureSnapshot
    {
        return PredictionFeatureSnapshot::query()
            ->where('prediction_table', $prediction->getTable())
            ->where('prediction_id', (int) $prediction->id)
            ->latest('generated_at')
            ->latest('id')
            ->first();
    }

    private function pointInTimeSafe(Prediction $prediction): bool
    {
        $metadata = is_array($prediction->model_metadata) ? $prediction->model_metadata : [];

        if (data_get($metadata, 'market_context.safety.pregame_safe') === false) {
            return false;
        }

        if (data_get($metadata, 'point_in_time_safety.team_metrics.home.pregame_safe') === false
            || data_get($metadata, 'point_in_time_safety.team_metrics.away.pregame_safe') === false) {
            return false;
        }

        return true;
    }

    private function phase(?Game $game): string
    {
        return match ($game?->status) {
            config('mlb.statuses.in_progress') => 'live',
            config('mlb.statuses.final') => 'historical',
            default => 'pregame',
        };
    }

    private function scheduledStartAt(?Game $game): ?Carbon
    {
        if ($game === null || $game->game_date === null) {
            return null;
        }

        $date = $game->game_date->toDateString();
        $time = $game->game_time ?: '00:00:00';

        return Carbon::parse("{$date} {$time}", config('app.timezone'))->utc();
    }

    private function teamLabel(mixed $team): ?string
    {
        if (! $team) {
            return null;
        }

        return trim((string) ($team->abbreviation ?: $team->name ?: $team->location)) ?: null;
    }

    private function teamStrengthAdjustment(Prediction $prediction): ?float
    {
        $home = $this->nullableFloat($prediction->home_team_elo);
        $away = $this->nullableFloat($prediction->away_team_elo);

        return $home !== null && $away !== null ? round($home - $away, 2) : null;
    }

    private function pitcherAdjustment(Prediction $prediction): ?float
    {
        $home = $this->nullableFloat($prediction->home_pitcher_elo);
        $away = $this->nullableFloat($prediction->away_pitcher_elo);

        return $home !== null && $away !== null ? round($home - $away, 2) : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
