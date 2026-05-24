<?php

namespace App\Actions\WNBA;

use App\Actions\Sports\AbstractPredictionGenerator;
use App\Models\WNBA\Prediction;
use App\Models\WNBA\TeamMetric;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class GeneratePrediction extends AbstractPredictionGenerator
{
    protected const SPORT_KEY = 'wnba';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;

    protected function calculatePredictedSpread(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        // Calculate predicted spread (negative means away team favored)
        $homeCourtAdvantage = config('wnba.elo.home_court_advantage');
        $eloToSpread = config('wnba.prediction.elo_to_spread_divisor');
        $eloDiff = ($homeElo + $homeCourtAdvantage) - $awayElo;

        return round($eloDiff / $eloToSpread, 1);
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

        return round(($homePredictedScore + $awayPredictedScore) * ($pace / 100), 1);
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
            'total_tempo_regression_weight' => (float) config('wnba.prediction.total_tempo_regression_weight', 0.0),
            'previous_season_metric_fallback_enabled' => (bool) config('wnba.prediction.use_previous_season_metrics_fallback', false),
        ];
        $modelMetadata = [
            'model' => 'wnba_elo_efficiency_context',
            'feature_context' => [
                'uses_elo' => true,
                'uses_efficiency' => true,
                'uses_tempo' => true,
                'uses_injury_context' => true,
                'uses_rest_recent_context_when_metrics_available' => true,
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
}
