<?php

namespace App\Actions\WNBA;

use App\Actions\Sports\AbstractPredictionGenerator;
use App\Models\WNBA\EloRating;
use App\Models\WNBA\Prediction;
use App\Models\WNBA\TeamMetric;
use App\Services\WNBA\WnbaPredictionSignalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class GeneratePrediction extends AbstractPredictionGenerator
{
    protected const SPORT_KEY = 'wnba';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;

    /**
     * @var array<string, mixed>
     */
    private array $calibrationMetadata = [];

    private ?Model $currentGame = null;

    private bool $usingHistoricalReconstructionContext = false;

    public function executeHistorical(Model $game, bool $dispatchNarratives = false, array $snapshotOverrides = []): ?Model
    {
        $this->usingHistoricalReconstructionContext = true;

        try {
            return parent::executeHistorical($game, $dispatchNarratives, array_replace([
                'historical_profile' => 'wnba-historical-elo-prior-v1',
                'point_in_time_verified' => true,
                'pregame_safe' => true,
                'verification_method' => 'pregame_elo_history_prior_season_metrics_and_prior_game_signals',
                'features_available_at' => $game->game_date,
            ], $snapshotOverrides));
        } finally {
            $this->usingHistoricalReconstructionContext = false;
        }
    }

    protected function calculatePredictedSpread(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        $this->currentGame = $game;

        // Calculate predicted spread (negative means away team favored)
        $homeCourtAdvantage = config('wnba.elo.home_court_advantage');
        $eloToSpread = config('wnba.prediction.elo_to_spread_divisor');
        $eloDiff = ($homeElo + $homeCourtAdvantage) - $awayElo;

        $eloSpread = $eloDiff / $eloToSpread;
        $metricSpread = $this->metricSpread($homeMetrics, $awayMetrics);
        $sampleGames = $this->metricSampleGames($homeMetrics, $awayMetrics);
        $metricReliability = $this->metricReliability($homeMetrics, $awayMetrics);
        $metricWeight = $metricSpread === null
            ? 0.0
            : (float) config('wnba.prediction.metric_spread_weight', 0.25) * $metricReliability;

        $blendedSpread = $metricSpread === null
            ? $eloSpread
            : ($eloSpread * (1 - $metricWeight)) + ($metricSpread * $metricWeight);

        $regressionWeight = (float) config('wnba.prediction.spread_output_regression_weight', 0.0);
        $predictedSpread = round($blendedSpread * (1 - max(0.0, min(0.75, $regressionWeight))), 1);

        $this->calibrationMetadata['spread_calibration'] = [
            'elo_spread' => round($eloSpread, 3),
            'metric_spread' => $metricSpread !== null ? round($metricSpread, 3) : null,
            'metric_weight' => round($metricWeight, 3),
            'metric_reliability' => round($metricReliability, 3),
            'home_sample_games' => $sampleGames['home'],
            'away_sample_games' => $sampleGames['away'],
            'sample_games' => $sampleGames['min'],
            'output_regression_weight' => round($regressionWeight, 3),
            'calibrated_spread' => $predictedSpread,
        ];

        return $predictedSpread;
    }

    protected function calculatePredictedTotal(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        // Extract efficiency metrics (use league averages if not available)
        $defaultEfficiency = config('wnba.prediction.default_efficiency');
        $homeOffEff = $homeMetrics?->offensive_efficiency ?? $defaultEfficiency;
        $homeDefEff = $homeMetrics?->defensive_efficiency ?? $defaultEfficiency;
        $awayOffEff = $awayMetrics?->offensive_efficiency ?? $defaultEfficiency;
        $awayDefEff = $awayMetrics?->defensive_efficiency ?? $defaultEfficiency;

        // Calculate predicted total using efficiency metrics
        $homePredictedScore = ($homeOffEff + $awayDefEff) / 2;
        $awayPredictedScore = ($awayOffEff + $homeDefEff) / 2;
        $averagePace = (float) config('wnba.prediction.average_pace');
        $paceRaw = (($homeMetrics?->tempo ?? $averagePace) + ($awayMetrics?->tempo ?? $averagePace)) / 2;
        $pace = $this->regressTotalPace(
            $paceRaw,
            $averagePace,
            (float) config('wnba.prediction.total_tempo_regression_weight', 0.0)
        );

        $rawTotal = ($homePredictedScore + $awayPredictedScore) * ($pace / 100);
        $averageTotal = (float) config(
            'wnba.prediction.average_total',
            $defaultEfficiency * 2 * ($averagePace / 100)
        );
        $outputRegressionWeight = (float) config('wnba.prediction.total_output_regression_weight', 0.0);
        $regressedTotal = ($rawTotal * (1 - max(0.0, min(0.75, $outputRegressionWeight))))
            + ($averageTotal * max(0.0, min(0.75, $outputRegressionWeight)));
        $predictedTotal = round($regressedTotal, 1);

        $this->calibrationMetadata['total_calibration'] = [
            'raw_total' => round($rawTotal, 3),
            'average_total' => round($averageTotal, 3),
            'pace_raw' => round($paceRaw, 3),
            'pace_regressed' => round($pace, 3),
            'tempo_regression_weight' => (float) config('wnba.prediction.total_tempo_regression_weight', 0.0),
            'output_regression_weight' => round($outputRegressionWeight, 3),
            'calibrated_total' => $predictedTotal,
        ];

        return $predictedTotal;
    }

    /**
     * @return array{0:float,1:float}
     */
    protected function finalizePredictedOutputs(
        float $predictedSpread,
        float $predictedTotal,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): array {
        $finalSpread = round($predictedSpread, 1);
        $finalTotal = round($predictedTotal, 1);

        $this->calibrationMetadata['final_output_calibration'] = [
            'adjusted_spread' => round($predictedSpread, 3),
            'adjusted_total' => round($predictedTotal, 3),
            'final_spread' => $finalSpread,
            'final_total' => $finalTotal,
            'output_cap_applied' => false,
        ];

        return [$finalSpread, $finalTotal];
    }

    /**
     * @return array{0:int,1:int}
     */
    protected function eloRatingsForGame(Model $game, Model $homeTeam, Model $awayTeam, int $defaultElo): array
    {
        if (! $this->usingHistoricalReconstructionContext) {
            return parent::eloRatingsForGame($game, $homeTeam, $awayTeam, $defaultElo);
        }

        $ratings = EloRating::query()
            ->where('game_id', $game->getKey())
            ->whereIn('team_id', [(int) $homeTeam->getKey(), (int) $awayTeam->getKey()])
            ->get()
            ->keyBy('team_id');

        return [
            $this->preGameElo($ratings->get($homeTeam->getKey()), $homeTeam, $defaultElo),
            $this->preGameElo($ratings->get($awayTeam->getKey()), $awayTeam, $defaultElo),
        ];
    }

    /**
     * @return array{0:?Model,1:?Model}
     */
    protected function teamMetricsForGame(Model $game, int $homeTeamId, int $awayTeamId): array
    {
        if (! $this->usingHistoricalReconstructionContext) {
            return parent::teamMetricsForGame($game, $homeTeamId, $awayTeamId);
        }

        $teamMetricModel = $this->getTeamMetricModel();

        return [
            $this->latestPriorSeasonMetric($teamMetricModel, $homeTeamId, (int) $game->season, $game),
            $this->latestPriorSeasonMetric($teamMetricModel, $awayTeamId, (int) $game->season, $game),
        ];
    }

    protected function buildPredictionData(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        float $predictedSpread,
        float $predictedTotal,
        float $winProbability,
        float $confidenceScore
    ): array {
        $defaultEfficiency = config('wnba.prediction.default_efficiency');
        $averagePace = (float) config('wnba.prediction.average_pace');
        $homeCourtAdvantage = (float) config('wnba.elo.home_court_advantage');
        $sampleGames = $this->metricSampleGames($homeMetrics, $awayMetrics);
        $signalContext = $this->currentGame instanceof Model
            ? app(WnbaPredictionSignalService::class)->forGame($this->currentGame)
            : [];
        $snapshotFeatures = [
            'home_elo' => $homeElo,
            'away_elo' => $awayElo,
            'home_metric_season' => $homeMetrics?->season,
            'away_metric_season' => $awayMetrics?->season,
            'home_off_eff' => (float) ($homeMetrics?->offensive_efficiency ?? $defaultEfficiency),
            'home_def_eff' => (float) ($homeMetrics?->defensive_efficiency ?? $defaultEfficiency),
            'away_off_eff' => (float) ($awayMetrics?->offensive_efficiency ?? $defaultEfficiency),
            'away_def_eff' => (float) ($awayMetrics?->defensive_efficiency ?? $defaultEfficiency),
            'home_tempo' => (float) ($homeMetrics?->tempo ?? $averagePace),
            'away_tempo' => (float) ($awayMetrics?->tempo ?? $averagePace),
            'average_pace' => $averagePace,
            'home_court_advantage' => $homeCourtAdvantage,
            'elo_to_spread_divisor' => (float) config('wnba.prediction.elo_to_spread_divisor'),
            'metric_spread_weight' => (float) config('wnba.prediction.metric_spread_weight', 0.25),
            'metric_spread_min_games' => (int) config('wnba.prediction.metric_spread_min_games', 10),
            'home_sample_games' => $sampleGames['home'],
            'away_sample_games' => $sampleGames['away'],
            'sample_games' => $sampleGames['min'],
            'spread_output_regression_weight' => (float) config('wnba.prediction.spread_output_regression_weight', 0.0),
            'total_tempo_regression_weight' => (float) config('wnba.prediction.total_tempo_regression_weight', 0.0),
            'average_total' => (float) config('wnba.prediction.average_total', $defaultEfficiency * 2 * ($averagePace / 100)),
            'total_output_regression_weight' => (float) config('wnba.prediction.total_output_regression_weight', 0.0),
            'previous_season_metric_fallback_enabled' => (bool) config('wnba.prediction.use_previous_season_metrics_fallback', false),
            'wnba_signals' => $signalContext,
        ];
        $modelMetadata = [
            'model' => 'wnba_elo_efficiency_context',
            'calibration' => $this->calibrationMetadata,
            'signal_context' => $signalContext,
            'season_context' => [
                'sample_games' => $sampleGames['min'],
                'home_sample_games' => $sampleGames['home'],
                'away_sample_games' => $sampleGames['away'],
                'minimum_reliable_games' => max(1, (int) config('wnba.prediction.metric_spread_min_games', 10)),
            ],
            'feature_context' => [
                'uses_elo' => true,
                'uses_efficiency' => true,
                'uses_net_rating_spread_blend' => true,
                'uses_tempo' => true,
                'uses_output_regression' => true,
                'uses_injury_context' => true,
                'uses_rest_recent_context_when_metrics_available' => true,
                'uses_team_ats_context' => true,
                'uses_rolling_four_factors' => true,
                'uses_rest_fatigue_context' => true,
                'uses_previous_season_metric_fallback' => (bool) config('wnba.prediction.use_previous_season_metrics_fallback', false),
            ],
        ];

        $data = array_merge(
            parent::buildPredictionData(
                $homeElo,
                $awayElo,
                $homeMetrics,
                $awayMetrics,
                $predictedSpread,
                $predictedTotal,
                $winProbability,
                $confidenceScore
            ),
            $this->efficiencyPredictionData($homeMetrics, $awayMetrics, $defaultEfficiency),
            [
                '_snapshot' => [
                    'model_version' => $this->modelVersion(),
                    'feature_version' => $this->featureVersion(),
                    'blend_version' => $this->blendVersion(),
                    'features' => $snapshotFeatures,
                    'outputs' => [
                        'predicted_spread' => $predictedSpread,
                        'predicted_total' => $predictedTotal,
                        'win_probability' => $winProbability,
                        'confidence_score' => $confidenceScore,
                    ],
                    'market_context' => [],
                    'model_metadata' => $modelMetadata,
                ],
            ]
        );

        if (Schema::hasColumn((new Prediction)->getTable(), 'model_metadata')) {
            $data['model_metadata'] = $modelMetadata;
        }

        return $data;
    }

    private function metricSpread(?Model $homeMetrics, ?Model $awayMetrics): ?float
    {
        if ($homeMetrics?->net_rating === null || $awayMetrics?->net_rating === null) {
            return null;
        }

        $averagePace = (float) config('wnba.prediction.average_pace', 88.0);
        $pace = (($homeMetrics?->tempo ?? $averagePace) + ($awayMetrics?->tempo ?? $averagePace)) / 2;
        $paceScale = $pace / 100;

        return ((float) $homeMetrics->net_rating - (float) $awayMetrics->net_rating) * $paceScale;
    }

    private function metricReliability(?Model $homeMetrics, ?Model $awayMetrics): float
    {
        $sample = $this->metricSampleGames($homeMetrics, $awayMetrics)['min'];
        $minimum = max(1, (int) config('wnba.prediction.metric_spread_min_games', 10));

        return max(0.0, min(1.0, $sample / $minimum));
    }

    /**
     * @return array{home:int,away:int,min:int}
     */
    private function metricSampleGames(?Model $homeMetrics, ?Model $awayMetrics): array
    {
        $homeGames = (int) ($homeMetrics?->wins ?? 0) + (int) ($homeMetrics?->losses ?? 0);
        $awayGames = (int) ($awayMetrics?->wins ?? 0) + (int) ($awayMetrics?->losses ?? 0);

        return [
            'home' => $homeGames,
            'away' => $awayGames,
            'min' => min($homeGames, $awayGames),
        ];
    }

    private function preGameElo(?EloRating $rating, Model $team, int $defaultElo): int
    {
        if ($rating === null) {
            return (int) round((float) ($team->elo_rating ?? $defaultElo));
        }

        return (int) round((float) $rating->elo_rating - (float) $rating->elo_change);
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
