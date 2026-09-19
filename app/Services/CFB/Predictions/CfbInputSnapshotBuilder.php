<?php

namespace App\Services\CFB\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Models\CFB\FpiRating;
use App\Models\CFB\Game;
use App\Models\CFB\GameWeather;
use App\Models\CFB\PlayerInjury;
use App\Models\CFB\TeamMetric;
use App\Models\SportEvent;
use App\Services\CFB\CfbPlayerEvidenceService;
use App\Services\CFB\CfbTeamEvidenceService;
use App\Services\CFB\Signals\CfbFootballSignalEvidence;
use App\Services\Predictions\Football\FootballInputSnapshotBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CfbInputSnapshotBuilder extends FootballInputSnapshotBuilder
{
    public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
    {
        return Cache::lock('cfb-elo-integrity-write', 600)->block(15,
            fn () => $this->buildLocked($event, $release));
    }

    private function buildLocked(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
    {
        $snapshot = parent::build($event, $release);
        if (! data_get($release->configuration, 'inputs.sample_aware_context', false)) {
            return $snapshot;
        }

        $inputs = $snapshot->inputs;
        $inputs['event']['week'] = (int) $event->cfbGame->week;
        $sourceTimestamps = $snapshot->sourceTimestamps;
        $inputs['require_versioned_elo'] = (bool) data_get($release->configuration, 'inputs.point_in_time_elo', false);
        $inputs['require_personnel_evidence'] = (bool) data_get($release->configuration, 'inputs.personnel_evidence', false);
        foreach (['home', 'away'] as $side) {
            $game = $event->cfbGame;
            if ($inputs['require_personnel_evidence']) {
                $inputs[$side]['large_spread_evidence'] = app(CfbLargeSpreadEvidence::class)->forGame(
                    $game, $inputs[$side]['team_id'], $snapshot->capturedAt, $snapshot->cutoffAt);
                $sourceTimestamps[$side.'_large_spread'] = $inputs[$side]['large_spread_evidence']['latest_source_observed_at'] ?? null;
                $inputs[$side]['personnel'] = app(CfbPersonnelEvidence::class)->forTeam(
                    $game, $inputs[$side]['team_id'], $snapshot->capturedAt, $snapshot->cutoffAt);
                foreach ($inputs[$side]['personnel']['components'] as $name => $component) {
                    if ($component['status'] === 'verified') {
                        $sourceTimestamps[$side.'_personnel_'.$name] = $component['observed_at'];
                    }
                }
            }
            if ($inputs['require_versioned_elo']) {
                $elo = app(CfbPointInTimeElo::class)->forTeam($inputs[$side]['team_id'], (int) $event->season,
                    $snapshot->capturedAt, $snapshot->cutoffAt, (float) data_get($release->configuration, 'elo.default', 1500));
                $inputs[$side]['elo'] = $elo['rating'];
                $inputs[$side]['elo_evidence'] = $elo['evidence'];
                $sourceTimestamps[$side.'_elo'] = $elo['evidence']['observed_at'];
            }
            $inputs[$side]['evidence_windows'] = app(CfbTeamEvidenceService::class)
                ->forGame($game, $inputs[$side]['team_id']);
            $inputs[$side]['availability'] = app(CfbPlayerEvidenceService::class)
                ->quarterback($game, $inputs[$side]['team_id']);
            $prior = TeamMetric::where('team_id', $inputs[$side]['team_id'])
                ->where('season', $event->season - 1)
                ->where('updated_at', '<=', $snapshot->capturedAt)
                ->where('updated_at', '<', $snapshot->cutoffAt)
                ->whereDate('calculation_date', '<=', $snapshot->cutoffAt)
                ->orderByDesc('calculation_date')->orderByDesc('id')->first();
            $inputs[$side]['prior_metrics'] = $prior ? $this->nullableMetrics($prior) : null;
            $inputs[$side]['prior_metric_evidence'] = [
                'metric_id' => $prior?->id, 'observed_at' => $prior?->updated_at?->toIso8601String(),
            ];
            $sourceTimestamps[$side.'_prior_metric'] = $prior?->updated_at?->toIso8601String();
            $current = TeamMetric::where('team_id', $inputs[$side]['team_id'])
                ->where('season', data_get($inputs, $side.'.metrics.record_season'))
                ->where('updated_at', '<=', $snapshot->capturedAt)
                ->whereDate('calculation_date', '<=', $snapshot->cutoffAt)
                ->orderByDesc('calculation_date')->orderByDesc('id')->first();
            if ($current && $current->season >= $event->season - 1) {
                $inputs[$side]['metrics'] = $this->nullableMetrics($current);
                $sourceTimestamps[$side.'_metric'] = $current->updated_at->toIso8601String();
            } else {
                $inputs[$side]['metrics'] = null;
            }
            if (data_get($inputs, $side.'.elo_evidence.source_kind') === 'derived_season_initialization') {
                // Stored absolute injury ratings use mutable team Elo, not this newly regressed baseline.
                // Keep direct injury records; suppress only the incompatible absolute-rating delta.
                $inputs[$side]['metrics']['injury_adjusted_team_rating'] = null;
                $inputs[$side]['injury_rating_evidence'] = ['status' => 'metric_baseline_unaligned',
                    'applied' => false, 'direct_injury_records_retained' => true];
            }
            if (data_get($release->configuration, 'spread.rating_baseline') === 'fpi_points') {
                $rating = FpiRating::where('team_id', $inputs[$side]['team_id'])
                    ->where('season', $event->season)->where('week', '<=', $game->week)
                    ->where('updated_at', '<=', $snapshot->capturedAt)
                    ->where('updated_at', '<', $snapshot->cutoffAt)
                    ->orderByDesc('week')->orderByDesc('updated_at')->first();
                $inputs[$side]['metrics']['fpi'] = $rating?->fpi === null ? null : (float) $rating->fpi;
                $inputs[$side]['rating_evidence'] = [
                    'source' => 'cfbd_fpi', 'rating_id' => $rating?->id,
                    'season' => $rating?->season, 'week' => $rating?->week,
                    'observed_at' => $rating?->updated_at?->toIso8601String(),
                    'units' => 'points_above_average',
                ];
                $sourceTimestamps[$side.'_fpi'] = $rating?->updated_at?->toIso8601String();
            }
        }

        if (data_get($release->configuration, 'football_signals.enabled', false)) {
            $inputs['historical_signals'] = app(CfbHistoricalSignalEvidenceBuilder::class)
                ->build($game, $snapshot->capturedAt, $snapshot->cutoffAt);
            $sourceTimestamps['historical_signals'] = $inputs['historical_signals']['latest_source_available_at'];
            $weather = GameWeather::where('game_id', $game->id)
                ->where('updated_at', '<=', $snapshot->capturedAt)->where('updated_at', '<', $snapshot->cutoffAt)
                ->where('observed_at', '<=', $snapshot->capturedAt)->orderByDesc('observed_at')->first();
            // Legacy context rows may contain default zero adjustments without observed weather.
            $inputs['signal_context'] = [];
            $inputs['signal_context']['conference'] = $game->conference_game === null ? null : (bool) $game->conference_game;
            if ($weather) {
                foreach (['temperature_f', 'wind_speed_mph', 'wind_gust_mph', 'precipitation_inches', 'is_indoor'] as $field) {
                    $inputs['signal_context'][$field] = $weather->$field === null ? null : (is_bool($weather->$field) ? $weather->$field : (float) $weather->$field);
                }
            }
            foreach (['home', 'away'] as $side) {
                $inputs['signal_context'][$side.'_rest_days'] = data_get($inputs, 'historical_signals.'.$side.'.rest_days.value');
            }
            foreach (['spreads' => ['home', 'home_spread'], 'totals' => ['over', 'total']] as $market => [$side, $key]) {
                $quote = app(CfbStoredPregameQuote::class)->latest($game->id, $market, $side, $snapshot->cutoffAt, $snapshot->capturedAt);
                $inputs['signal_context'][$key] = $quote?->line === null ? null : (float) $quote->line;
                $inputs['signal_context'][$key.'_quote_id'] = $quote?->id;
                $sourceTimestamps['signal_'.$key] = $quote?->created_at?->toIso8601String();
            }
            $sourceTimestamps['signal_weather'] = $weather?->updated_at?->toIso8601String();
            $evidenceKey = 'cfb:football-signal-evidence:'.hash('sha256', json_encode($release->configuration, JSON_THROW_ON_ERROR)).':'.$snapshot->capturedAt->format('YmdHi');
            $inputs['football_signal_evidence'] = Cache::remember($evidenceKey, 120,
                fn () => app(CfbFootballSignalEvidence::class)->build($snapshot->capturedAt, $release->configuration));
            $sourceTimestamps['football_signal_evidence'] = $inputs['football_signal_evidence']['latest_source_observed_at'];
        }

        return new EventInputSnapshotData(
            schemaVersion: $snapshot->schemaVersion, inputs: $inputs,
            capturedAt: $snapshot->capturedAt, cutoffAt: $snapshot->cutoffAt,
            latestSourceAvailableAt: collect($sourceTimestamps)->filter()->map(fn ($date) => CarbonImmutable::parse($date))->max() ?? $snapshot->latestSourceAvailableAt,
            sourceTimestamps: $sourceTimestamps,
            pregameSafetyStatus: $snapshot->pregameSafetyStatus, metadata: $snapshot->metadata,
        );
    }

    /** @return array<string, mixed> */
    private function nullableMetrics(Model $metric): array
    {
        $inputs = $this->metricInputs($metric);
        foreach (['offensive_rating', 'defensive_rating', 'net_rating', 'points_per_game',
            'points_allowed_per_game', 'turnover_differential', 'recent_form_rating', 'rest_travel_fatigue'] as $field) {
            $inputs[$field] = $this->nullableFloat($metric->getAttribute($field));
        }

        foreach (['yards_per_game', 'yards_allowed_per_game', 'passing_yards_per_game', 'rushing_yards_per_game',
            'strength_of_schedule', 'offensive_success_rate', 'defensive_success_rate', 'net_success_rate',
            'offensive_explosiveness', 'defensive_explosiveness', 'net_explosiveness', 'offensive_havoc_rate',
            'defensive_havoc_rate', 'net_havoc_rate', 'offensive_line_yards', 'offensive_stuff_rate',
            'offensive_sack_rate', 'offensive_line_rating', 'qb_environment_rating', 'defensive_front_rating',
            'cfbd_wepa_offense', 'cfbd_wepa_defense', 'cfbd_wepa_net', 'offensive_true_epa_per_play',
            'defensive_true_epa_per_play', 'net_true_epa_per_play'] as $field) {
            $inputs[$field] = $this->nullableFloat($metric->getAttribute($field));
        }

        return $inputs;
    }

    protected function sport(): string
    {
        return 'cfb';
    }

    protected function inputSchemaVersion(): string
    {
        return CfbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }

    protected function gameRelation(): string
    {
        return 'cfbGame';
    }

    /** @return class-string<Model> */
    protected function gameModel(): string
    {
        return Game::class;
    }

    /** @return class-string<Model> */
    protected function teamMetricModel(): string
    {
        return TeamMetric::class;
    }

    /** @return class-string<Model> */
    protected function playerInjuryModel(): string
    {
        return PlayerInjury::class;
    }

    protected function teamMetricsUseSeasonType(): bool
    {
        return false;
    }

    protected function metricHasFallbackUsableInputs(Model $metric): bool
    {
        return is_numeric($metric->getAttribute('fpi'))
            || is_numeric($metric->getAttribute('power_rating'))
            || is_numeric($metric->getAttribute('predictive_rating'));
    }

    /** @return array<string, mixed> */
    protected function metricInputs(Model $metric): array
    {
        $inputs = parent::metricInputs($metric);
        $sampleGames = (int) $metric->getAttribute('wins') + (int) $metric->getAttribute('losses');

        if ($sampleGames > 0) {
            return $inputs;
        }

        return [
            ...$inputs,
            'offensive_rating' => 0.0,
            'defensive_rating' => 0.0,
            'net_rating' => 0.0,
            'points_per_game' => 0.0,
            'points_allowed_per_game' => 0.0,
            'turnover_differential' => 0.0,
            'recent_form_rating' => 0.0,
            'injury_adjusted_team_rating' => null,
            'injury_total_adjustment' => null,
            'rest_travel_fatigue' => 0.0,
        ];
    }
}
