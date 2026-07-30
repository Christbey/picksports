<?php

namespace App\Services\MLB;

use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Services\Predictions\PredictionEvaluationRecorder;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use App\Support\MLB\MlbGamePhase;
use App\Support\Odds\MarketSpread;
use Illuminate\Support\Facades\DB;

class TrustedHistoricalPredictionReconstructor
{
    public const MODEL_VERSION = 'historical-core-v1';

    public const BLEND_VERSION = 'baseline-v1';

    public function __construct(
        private readonly TrustedHistoricalFeatureBuilder $featureBuilder,
        private readonly PredictionFeatureSnapshotRecorder $snapshotRecorder,
        private readonly PredictionEvaluationRecorder $evaluationRecorder,
    ) {}

    public function reconstruct(Game $game): ?Prediction
    {
        $payload = $this->featureBuilder->build($game);
        $gameStartAt = MlbGamePhase::scheduledStartAt($game);

        if ($payload === null || $gameStartAt === null) {
            return null;
        }

        $features = $payload['features'];
        $outputs = $this->outputs($features);
        $targetHash = hash('sha256', json_encode([
            'game_id' => (int) $game->id,
            'home_score' => (int) $game->home_score,
            'away_score' => (int) $game->away_score,
        ], JSON_THROW_ON_ERROR));
        $reconstructionMetadata = [
            ...$payload['evidence'],
            'point_in_time_verified' => true,
            'features_available_at' => $payload['features_available_at']->toIso8601String(),
            'game_start_at' => $gameStartAt->toIso8601String(),
            'source_timestamps' => $payload['source_timestamps'],
            'target_hash' => $targetHash,
        ];
        $actualSpread = (float) $game->home_score - (float) $game->away_score;
        $actualTotal = (float) $game->home_score + (float) $game->away_score;
        $winnerCorrect = $actualSpread === 0.0
            ? null
            : (($actualSpread > 0 && $outputs['predicted_spread'] > 0)
                || ($actualSpread < 0 && $outputs['predicted_spread'] < 0));
        $historicalAttributes = [
            'game_id' => (int) $game->id,
            'season' => (int) $game->season,
            'season_type' => (string) $game->season_type,
            'home_team_elo' => $features['home_team_elo'],
            'away_team_elo' => $features['away_team_elo'],
            'home_pitcher_elo' => $features['home_pitcher_elo'],
            'away_pitcher_elo' => $features['away_pitcher_elo'],
            'home_combined_elo' => $outputs['home_combined_elo'],
            'away_combined_elo' => $outputs['away_combined_elo'],
            'predicted_spread' => $outputs['predicted_spread'],
            'predicted_total' => $outputs['predicted_total'],
            'win_probability' => $outputs['win_probability'],
            'confidence_score' => $outputs['confidence_score'],
            'vegas_spread' => $features['market_bookmaker_home_line'],
            'model_version' => self::MODEL_VERSION,
            'feature_version' => TrustedHistoricalFeatureBuilder::FEATURE_VERSION,
            'blend_version' => self::BLEND_VERSION,
            'model_metadata' => [
                'historical_reconstruction' => $reconstructionMetadata,
                'win_probability_calibration' => [
                    'active_source' => 'trusted_historical_core',
                    'baseline_win_probability' => $outputs['win_probability'],
                ],
            ],
            'actual_spread' => round($actualSpread, 1),
            'actual_total' => round($actualTotal, 1),
            'spread_error' => round(abs($actualSpread - $outputs['predicted_spread']), 1),
            'total_error' => round(abs($actualTotal - $outputs['predicted_total']), 1),
            'winner_correct' => $winnerCorrect,
            'graded_at' => now(),
        ];

        return DB::transaction(function () use (
            $game,
            $features,
            $outputs,
            $payload,
            $reconstructionMetadata,
            $targetHash,
            $actualSpread,
            $actualTotal,
            $historicalAttributes,
        ): Prediction {
            $prediction = Prediction::query()
                ->where('game_id', (int) $game->id)
                ->lockForUpdate()
                ->first();

            if ($prediction === null) {
                $prediction = Prediction::query()->create($historicalAttributes);
            }

            $historicalProjection = clone $prediction;
            $historicalProjection->setRelations([]);
            $historicalProjection->forceFill($historicalAttributes);
            $historicalProjection->mergeCasts([
                'predicted_spread' => 'float',
                'predicted_total' => 'float',
                'win_probability' => 'float',
                'confidence_score' => 'float',
                'vegas_spread' => 'float',
                'actual_spread' => 'float',
                'actual_total' => 'float',
                'spread_error' => 'float',
                'total_error' => 'float',
            ]);

            $this->snapshotRecorder->record(
                $prediction,
                $game,
                'mlb',
                $historicalProjection->toArray(),
                [
                    'model_version' => self::MODEL_VERSION,
                    'feature_version' => TrustedHistoricalFeatureBuilder::FEATURE_VERSION,
                    'blend_version' => self::BLEND_VERSION,
                    'features' => $features,
                    'outputs' => $outputs,
                    'market_context' => $payload['market_context'],
                    'model_metadata' => [
                        'historical_reconstruction' => $reconstructionMetadata,
                        'target_hash' => $targetHash,
                    ],
                    'run_type' => 'historical_reconstruction',
                    'run_parameters' => [
                        'historical_profile' => TrustedHistoricalFeatureBuilder::PROFILE,
                        'feature_version' => TrustedHistoricalFeatureBuilder::FEATURE_VERSION,
                    ],
                    'historical_profile' => TrustedHistoricalFeatureBuilder::PROFILE,
                    'point_in_time_verified' => true,
                    'pregame_safe' => true,
                    'availability_status' => 'verified_reconstruction',
                    'features_available_at' => $payload['features_available_at'],
                    'source_timestamps' => $payload['source_timestamps'],
                    'verification_method' => $payload['evidence']['verification_method'],
                ]
            );

            $this->evaluationRecorder->record(
                $historicalProjection,
                $game,
                'mlb',
                $actualSpread,
                $actualTotal,
            );

            return $prediction;
        });
    }

    /**
     * @param  array<string, mixed>  $features
     * @return array<string, float|null>
     */
    private function outputs(array $features): array
    {
        $defaultElo = (float) config('mlb.elo.default_rating', 1500);
        $homePitcherAdjustment = ((float) $features['home_pitcher_elo'] - $defaultElo)
            * (float) $features['home_pitcher_confidence']
            * 0.25;
        $awayPitcherAdjustment = ((float) $features['away_pitcher_elo'] - $defaultElo)
            * (float) $features['away_pitcher_confidence']
            * 0.25;
        $homeCombinedElo = (float) $features['home_team_elo'] + $homePitcherAdjustment;
        $awayCombinedElo = (float) $features['away_team_elo'] + $awayPitcherAdjustment;
        $homeFieldAdvantage = (float) config('mlb.prediction.home_field_advantage', 5);
        $eloDivisor = max(1.0, (float) config('mlb.prediction.elo_diff_to_spread_divisor', 44));
        $rollingRunAdjustment = (
            (float) $features['home_rolling_run_differential_20']
            - (float) $features['away_rolling_run_differential_20']
        ) * 0.35;
        $predictedSpread = round(
            (($homeCombinedElo + $homeFieldAdvantage - $awayCombinedElo) / $eloDivisor)
            + $rollingRunAdjustment,
            4
        );
        $hasRunHistory = (int) $features['home_prior_games'] > 0
            && (int) $features['away_prior_games'] > 0;
        $predictedTotal = $hasRunHistory
            ? (
                (float) $features['home_rolling_runs_scored_20']
                + (float) $features['away_rolling_runs_allowed_20']
                + (float) $features['away_rolling_runs_scored_20']
                + (float) $features['home_rolling_runs_allowed_20']
            ) / 2
            : (float) config('mlb.prediction.total_model.base_runs', 10.6);
        $winProbability = round(1 / (1 + exp(-$predictedSpread / 1.5)), 6);

        return [
            'home_combined_elo' => round($homeCombinedElo, 4),
            'away_combined_elo' => round($awayCombinedElo, 4),
            'predicted_spread' => $predictedSpread,
            'predicted_total' => round($predictedTotal, 4),
            'win_probability' => $winProbability,
            'confidence_score' => round(max($winProbability, 1 - $winProbability) * 100, 4),
            'baseline_predicted_spread' => $predictedSpread,
            'baseline_predicted_total' => round($predictedTotal, 4),
            'bookmaker_home_spread' => $features['market_bookmaker_home_line'],
            'market_spread' => $features['market_bookmaker_home_line'] === null
                ? null
                : MarketSpread::bookmakerHomeLineToHomeMargin((float) $features['market_bookmaker_home_line']),
            'market_total' => $features['market_total'],
        ];
    }
}
