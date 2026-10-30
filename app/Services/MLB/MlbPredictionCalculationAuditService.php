<?php

namespace App\Services\MLB;

use App\Models\CanonicalPrediction;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Predictions\CanonicalShadowInputs;
use App\Services\Predictions\PredictionDeliverySource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class MlbPredictionCalculationAuditService
{
    /**
     * @return array<string, mixed>
     */
    public function explain(Prediction|CanonicalPrediction $prediction, ?CarbonInterface $asOf = null): array
    {
        if ($prediction instanceof CanonicalPrediction) {
            return $this->canonicalExplanation($prediction, $asOf);
        }
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
        return $this->invariantFailures($prediction->getAttributes());
    }

    private function invariantFailures(array $values, bool $requireConfidence = true): array
    {
        $failures = [];
        $homeProbability = $this->nullableFloat($values['win_probability'] ?? null);
        $spread = $this->nullableFloat($values['predicted_spread'] ?? null);
        $total = $this->nullableFloat($values['predicted_total'] ?? null);
        $confidence = $this->nullableFloat($values['confidence_score'] ?? null);

        foreach ([
            'win_probability' => $homeProbability,
            'predicted_spread' => $spread,
            'predicted_total' => $total,
            ...($requireConfidence || $confidence !== null ? ['confidence_score' => $confidence] : []),
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
            if (! is_string(($values[$field] ?? null)) || trim(($values[$field] ?? null)) === '') {
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

    /** @param array<string, mixed> $filters */
    public function canonicalQuery(array $filters = []): Builder
    {
        return app(CanonicalSportPredictionQuery::class)->queryForSport('mlb', $filters)->withOnly([
            'markets', 'calculationRun:id,event_input_snapshot_id,calculation_release_id,status,diagnostics',
            'calculationRun.release:id,status,semantic_version',
            'calculationRun.inputSnapshot:id,sport,sport_event_id,phase,pregame_safety_status,captured_at,cutoff_at,latest_source_available_at,content_hash,schema_version,inputs',
            'sportEvent:id,starts_at',
            'sportEvent.mlbGame:id,sport_event_id,season,game_date,game_time,status,home_team_id,away_team_id,home_score,away_score,short_name,name',
            'sportEvent.mlbGame.homeTeam', 'sportEvent.mlbGame.awayTeam',
        ]);
    }

    public function forecastSource(?string $requested = null): string
    {
        $source = $requested ?? ((config('prediction_lifecycle.canonical_pipeline.mlb', false)
            || config('prediction_lifecycle.canonical_reads.mlb', false)) ? 'canonical' : 'legacy');
        if (! in_array($source, ['canonical', 'legacy'], true)) {
            throw new \InvalidArgumentException('Forecast source must be canonical or legacy.');
        }

        return $source;
    }

    private function canonicalExplanation(CanonicalPrediction $prediction, ?CarbonInterface $asOf): array
    {
        if ($prediction->sport !== 'mlb') {
            throw new \InvalidArgumentException('MLB diagnostics require an MLB forecast.');
        }
        $context = app(PredictionDeliverySource::class)->context($prediction, 'mlb');
        $snapshot = app(CanonicalShadowInputs::class)->fromPrediction($prediction);
        $input = $prediction->calculationRun?->inputSnapshot;
        $inputs = (array) $input?->inputs;
        $game = $context->game;
        $start = $prediction->sportEvent?->starts_at;
        try {
            $frozenStart = data_get($inputs, 'event.starts_at');
            $kickoffMatches = is_string($frozenStart) && Carbon::parse($frozenStart)->eq($start);
        } catch (\Throwable) {
            $kickoffMatches = false;
        }
        $safe = $snapshot !== null && $kickoffMatches && $prediction->generated_at->lte($asOf ?? now())
            && $prediction->published_at->lt($start) && $prediction->published_at->lte($asOf ?? now())
            && ($input->latest_source_available_at === null || $input->latest_source_available_at->lte($prediction->generated_at));
        $versions = ['model_version' => $prediction->model_version ?? $prediction->calculationRun?->release?->semantic_version, 'feature_version' => $input?->schema_version,
            'blend_version' => 'canonical-release-'.$prediction->calculationRun?->calculation_release_id];
        $values = [...$versions, 'win_probability' => $context->homeWinProbability,
            'predicted_spread' => $context->predictedSpread, 'predicted_total' => $context->predictedTotal,
            'confidence_score' => $context->confidenceScore];
        $failures = $this->invariantFailures($values, false);
        $away = $this->nullableFloat($prediction->markets->first(fn ($market) => $market->market_type === 'moneyline' && $market->selection === 'away')?->probability);
        if ($away === null || ! is_finite($away) || $away < 0 || $away > 1) {
            $failures[] = 'away_win_probability_is_missing_or_invalid';
        } elseif ($context->homeWinProbability !== null && abs($context->homeWinProbability + $away - 1) > 0.001) {
            $failures[] = 'probabilities_do_not_sum_to_one';
        }
        $scores = $this->derivedScores($context->predictedSpread, $context->predictedTotal);
        $warnings = $safe ? [] : ['missing_canonical_input_evidence'];
        $market = (array) data_get($inputs, 'pregame_market', []);
        if (($market['available'] ?? false) !== true) {
            $warnings[] = 'market_context_not_proven_pregame_safe';
        }

        return [
            'game_id' => $game->id, 'prediction_id' => $prediction->id, 'forecast_source' => 'canonical',
            'prediction_public_id' => $prediction->public_id,
            'canonical_lineage' => $snapshot?->lineage ?? ['canonical_prediction_id' => $prediction->id,
                'calculation_run_id' => $prediction->calculation_run_id, 'event_input_snapshot_id' => $input?->id],
            'phase' => $this->phase($game), 'as_of' => ($asOf ?? now())->toIso8601String(),
            'teams' => ['home' => $this->teamLabel($game->homeTeam), 'away' => $this->teamLabel($game->awayTeam)],
            'game' => ['date' => $game->game_date?->toDateString(), 'time' => $game->game_time,
                'status' => $game->status, 'start_at' => $start?->toIso8601String()],
            'versions' => $versions,
            'input_timestamps' => ['input_captured_at' => $input?->captured_at?->toIso8601String(),
                'features_available_at' => $input?->latest_source_available_at?->toIso8601String(),
                'weather_observed_at' => data_get($inputs, 'weather.observed_at'),
                'market_snapshot_captured_at' => data_get($market, 'consensus.spread.captured_at')],
            'inputs' => ['team_elo' => ['home' => data_get($inputs, 'home.elo'), 'away' => data_get($inputs, 'away.elo'),
                'home_combined' => null, 'away_combined' => null],
                'pitcher_elo' => ['home' => data_get($inputs, 'pitching.home.elo'), 'away' => data_get($inputs, 'pitching.away.elo'),
                    'source' => data_get($inputs, 'pitching', [])],
                'team_metrics' => ['home' => data_get($inputs, 'home.metrics', []), 'away' => data_get($inputs, 'away.metrics', [])],
                'weather' => data_get($inputs, 'weather'), 'venue' => data_get($inputs, 'venue'), 'market' => $market],
            'adjustments' => (array) $prediction->calculationRun?->diagnostics,
            'outputs' => ['home_win_probability' => $context->homeWinProbability, 'away_win_probability' => $away,
                'predicted_winner' => $context->homeWinProbability === null ? null : $this->teamLabel($context->homeWinProbability >= 0.5 ? $game->homeTeam : $game->awayTeam),
                'predicted_spread' => $context->predictedSpread, 'predicted_total' => $context->predictedTotal,
                'derived_home_score' => $scores['home'], 'derived_away_score' => $scores['away'],
                'confidence' => $this->nullableFloat($context->confidenceScore), 'confidence_label' => $this->confidenceLabel($this->nullableFloat($context->confidenceScore))],
            'safety' => ['point_in_time_safe' => $safe, 'market_context_safe' => $safe && ($market['available'] ?? false) === true,
                'warnings' => $warnings, 'hard_failures' => array_values(array_unique($failures)),
                'calibrated' => false, 'bet_eligible' => false],
        ];
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
