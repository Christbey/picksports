<?php

namespace App\Actions\CFB;

use App\Actions\Sports\AbstractAmericanFootballPredictionGenerator;
use App\Models\CFB\EloRating;
use App\Models\CFB\FpiRating;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\CFB\PredictionCalibration;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Services\CFB\CfbGameContextService;
use App\Services\CFB\CfbMarketMovementSignalService;
use App\Services\CFB\PlayerAvailabilityImpactService;
use App\Support\CfbSeasonAffiliationResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GeneratePrediction extends AbstractAmericanFootballPredictionGenerator
{
    protected const SPORT_KEY = 'cfb';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;

    public function __construct(
        private readonly CfbSeasonAffiliationResolver $seasonAffiliationResolver = new CfbSeasonAffiliationResolver,
        private readonly PlayerAvailabilityImpactService $playerAvailabilityImpactService = new PlayerAvailabilityImpactService,
        private readonly CfbGameContextService $gameContextService = new CfbGameContextService,
    ) {}

    /**
     * @var array<string, mixed>
     */
    private array $predictionContext = [];

    /**
     * @var array<string, array<int, string>>
     */
    private array $tableColumns = [];

    /**
     * @var array<int, array<string, mixed>|null>
     */
    private array $adaptiveCalibrationCache = [];

    /**
     * @var array<string, array<string, float|int>|null>
     */
    private array $specialTeamsRatingCache = [];

    protected function calculatePredictedSpread(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        $baseSpread = parent::calculatePredictedSpread($homeElo, $awayElo, $homeMetrics, $awayMetrics, $game);

        $fpiDiff = (float) ($homeMetrics?->fpi ?? 0.0) - (float) ($awayMetrics?->fpi ?? 0.0);
        $wepaNetDiff = (float) ($homeMetrics?->cfbd_wepa_net ?? 0.0) - (float) ($awayMetrics?->cfbd_wepa_net ?? 0.0);
        $efficiencyDiff = (float) ($homeMetrics?->net_rating ?? 0.0) - (float) ($awayMetrics?->net_rating ?? 0.0);
        $powerRatingDiff = (float) ($homeMetrics?->power_rating ?? 0.0) - (float) ($awayMetrics?->power_rating ?? 0.0);
        $ratingConsensusDiff = $this->metricDiff($homeMetrics, $awayMetrics, 'rating_consensus');
        $successRateDiff = $this->metricDiff($homeMetrics, $awayMetrics, 'net_success_rate');
        $explosivenessDiff = $this->metricDiff($homeMetrics, $awayMetrics, 'net_explosiveness');
        $havocDiff = $this->metricDiff($homeMetrics, $awayMetrics, 'net_havoc_rate');
        $olQbEnvironmentDiff = $this->offensiveLineQuarterbackEnvironmentDiff($homeMetrics, $awayMetrics);
        $advancedSpreadSignals = $this->advancedSpreadSignalAdjustments(
            $ratingConsensusDiff,
            $successRateDiff,
            $explosivenessDiff,
            $havocDiff,
            $olQbEnvironmentDiff
        );

        $this->predictionContext = [
            'base_spread' => round($baseSpread, 3),
            'advanced_spread_signals' => $advancedSpreadSignals,
            'preseason_layer' => [
                'enabled' => false,
                'spread_adjustment' => 0.0,
                'confidence_penalty' => 0.0,
                'risk_flags' => [],
            ],
            'quality_signals' => [
                'elo' => round($baseSpread, 3),
                'fpi' => round($fpiDiff, 3),
                'wepa_net' => round($wepaNetDiff, 3),
                'net_rating' => round($efficiencyDiff, 3),
                'power_rating' => round($powerRatingDiff, 3),
                'rating_consensus' => round($ratingConsensusDiff, 3),
                'net_success_rate' => round($successRateDiff, 4),
                'net_explosiveness' => round($explosivenessDiff, 4),
                'net_havoc_rate' => round($havocDiff, 4),
                'ol_qb_environment' => round($olQbEnvironmentDiff, 4),
            ],
            'player_availability' => [
                'enabled' => false,
                'applied' => false,
            ],
            'market_movement' => null,
        ];

        $spread = $baseSpread
            + ($fpiDiff * $this->predictionWeight('fpi_spread_weight', 0.18))
            + ($wepaNetDiff * $this->predictionWeight('wepa_spread_weight', 4.5))
            + ($efficiencyDiff * $this->predictionWeight('efficiency_spread_weight', 0.04))
            + array_sum(array_column($advancedSpreadSignals, 'adjustment'));

        $spread = $this->applyQualityMarginCalibration(
            $spread,
            $baseSpread,
            $fpiDiff,
            $wepaNetDiff,
            $efficiencyDiff
        );

        $maxSpread = (float) config('cfb.predictions.max_spread', 40);
        $minSpread = (float) config('cfb.predictions.min_spread', -40);

        return round(max($minSpread, min($maxSpread, $spread)), 1);
    }

    protected function calculatePredictedTotal(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        $baseTotal = $this->baselineTotal($homeMetrics, $awayMetrics);

        $offenseWepa = (float) ($homeMetrics?->cfbd_wepa_offense ?? 0.0) + (float) ($awayMetrics?->cfbd_wepa_offense ?? 0.0);
        $defenseWepa = (float) ($homeMetrics?->cfbd_wepa_defense ?? 0.0) + (float) ($awayMetrics?->cfbd_wepa_defense ?? 0.0);
        $fpiTotal = (float) ($homeMetrics?->fpi ?? 0.0) + (float) ($awayMetrics?->fpi ?? 0.0);
        $advancedTotalSignals = $this->advancedTotalSignalAdjustments($homeMetrics, $awayMetrics);
        $this->predictionContext['advanced_total_signals'] = $advancedTotalSignals;

        $total = $baseTotal
            + ($offenseWepa * $this->predictionWeight('wepa_total_offense_weight', 2.2))
            - ($defenseWepa * $this->predictionWeight('wepa_total_defense_weight', 1.4))
            + ($fpiTotal * $this->predictionWeight('fpi_total_weight', 0.08))
            + array_sum(array_column($advancedTotalSignals, 'adjustment'));

        $minTotal = (float) config('cfb.predictions.min_total', 28);
        $maxTotal = (float) config('cfb.predictions.max_total', 88);

        return round(max($minTotal, min($maxTotal, $total)), 1);
    }

    protected function finalizePredictedOutputs(
        float $predictedSpread,
        float $predictedTotal,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): array {
        [$predictedSpread, $predictedTotal] = $this->applyPreseasonSignalLayer(
            $predictedSpread,
            $predictedTotal,
            $homeMetrics,
            $awayMetrics,
            $game
        );
        [$predictedSpread, $predictedTotal, $gameContext] = $this->gameContextService->apply(
            $game,
            $predictedSpread,
            $predictedTotal
        );
        $this->predictionContext['game_context'] = $gameContext;
        $this->predictionContext['market_movement'] = $game instanceof Game
            ? app(CfbMarketMovementSignalService::class)->spreadContext($game, $predictedSpread)
            : null;

        $maxSpread = (float) config('cfb.predictions.max_spread', 40);
        $minSpread = (float) config('cfb.predictions.min_spread', -40);
        $minTotal = (float) config('cfb.predictions.min_total', 28);
        $maxTotal = (float) config('cfb.predictions.max_total', 88);

        return [
            round(max($minSpread, min($maxSpread, $predictedSpread)), 1),
            round(max($minTotal, min($maxTotal, $predictedTotal)), 1),
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
        $confidenceScore = $this->applyStoredConfidencePenalty($confidenceScore);
        $confidenceScore = $this->applyMarketMovementConfidenceAdjustment($confidenceScore);
        $preseasonLayer = (array) ($this->predictionContext['preseason_layer'] ?? []);
        $gameContext = (array) ($this->predictionContext['game_context'] ?? []);
        $marketMovement = $this->predictionContext['market_movement'] ?? null;

        return [
            'home_elo' => $homeElo,
            'away_elo' => $awayElo,
            'home_fpi' => $homeMetrics?->fpi,
            'away_fpi' => $awayMetrics?->fpi,
            'predicted_spread' => $predictedSpread,
            'predicted_total' => $predictedTotal,
            'win_probability' => $winProbability,
            'confidence_score' => $confidenceScore,
            'model_version' => $this->modelVersion(),
            'feature_version' => $this->featureVersion(),
            'blend_version' => $this->blendVersion(),
            '_snapshot' => [
                'features' => [
                    'home_elo' => $homeElo,
                    'away_elo' => $awayElo,
                    'home_fpi' => $homeMetrics?->fpi,
                    'away_fpi' => $awayMetrics?->fpi,
                    'quality_signals' => $this->predictionContext['quality_signals'] ?? [],
                    'advanced_spread_signals' => $this->predictionContext['advanced_spread_signals'] ?? [],
                    'advanced_total_signals' => $this->predictionContext['advanced_total_signals'] ?? [],
                    'player_availability' => $this->predictionContext['player_availability'] ?? [],
                    'preseason_layer' => $preseasonLayer,
                    'game_context' => $gameContext,
                    'market_movement' => $marketMovement,
                ],
                'outputs' => [
                    'bookmaker_home_spread' => data_get($marketMovement, 'current_bookmaker_home_line'),
                    'market_spread' => data_get($marketMovement, 'current_home_margin'),
                    'predicted_spread' => $predictedSpread,
                    'predicted_total' => $predictedTotal,
                    'win_probability' => $winProbability,
                    'confidence_score' => $confidenceScore,
                ],
                'market_context' => $marketMovement === null ? null : [
                    'bookmaker_home_spread' => data_get($marketMovement, 'current_bookmaker_home_line'),
                    'market_spread' => data_get($marketMovement, 'current_home_margin'),
                    'cfb_market_movement' => $marketMovement,
                ],
                'model_metadata' => [
                    'cfb_preseason_layer' => $preseasonLayer,
                    'cfb_advanced_metric_layer' => [
                        'spread' => $this->predictionContext['advanced_spread_signals'] ?? [],
                        'total' => $this->predictionContext['advanced_total_signals'] ?? [],
                    ],
                    'cfb_player_availability' => $this->predictionContext['player_availability'] ?? [],
                    'cfb_game_context' => $gameContext,
                    'cfb_market_movement' => $marketMovement,
                ],
            ],
        ];
    }

    /**
     * @return array{0:float,1:float}
     */
    protected function applyInjuryAdjustments(Model $game, float $predictedSpread, float $predictedTotal): array
    {
        if (! (bool) config('cfb.predictions.player_availability.enabled', true)) {
            return parent::applyInjuryAdjustments($game, $predictedSpread, $predictedTotal);
        }

        $asOfDate = isset($game->game_date) ? (string) $game->game_date : null;
        $season = isset($game->season) ? (int) $game->season : null;
        $homeImpact = $this->playerAvailabilityImpactService->impactForTeam((int) ($game->home_team_id ?? 0), $season, $asOfDate);
        $awayImpact = $this->playerAvailabilityImpactService->impactForTeam((int) ($game->away_team_id ?? 0), $season, $asOfDate);

        if (! $homeImpact['available'] && ! $awayImpact['available']) {
            return parent::applyInjuryAdjustments($game, $predictedSpread, $predictedTotal);
        }

        $outSpreadPenalty = (float) config('cfb.predictions.injury_out_spread_penalty', 0.50);
        $questionableSpreadPenalty = (float) config('cfb.predictions.injury_questionable_spread_penalty', 0.20);
        $outTotalPenalty = (float) config('cfb.predictions.injury_out_total_penalty', 0.30);
        $questionableTotalPenalty = (float) config('cfb.predictions.injury_questionable_total_penalty', 0.10);

        $homePenalty = ((float) $homeImpact['out'] * $outSpreadPenalty)
            + ((float) $homeImpact['questionable'] * $questionableSpreadPenalty);
        $awayPenalty = ((float) $awayImpact['out'] * $outSpreadPenalty)
            + ((float) $awayImpact['questionable'] * $questionableSpreadPenalty);

        $maxSpreadAdjustment = (float) config('cfb.predictions.player_availability.max_spread_adjustment', 4.5);
        $maxTotalAdjustment = (float) config('cfb.predictions.player_availability.max_total_adjustment', 6.0);
        $spreadAdjustment = $this->clamp($awayPenalty - $homePenalty, $maxSpreadAdjustment);
        $totalAdjustment = -min(
            $maxTotalAdjustment,
            (((float) $homeImpact['out'] + (float) $awayImpact['out']) * $outTotalPenalty)
                + (((float) $homeImpact['questionable'] + (float) $awayImpact['questionable']) * $questionableTotalPenalty)
        );

        $metadata = [
            'enabled' => true,
            'applied' => abs($spreadAdjustment) > 0.0 || abs($totalAdjustment) > 0.0,
            'source' => 'espn_active_injuries_weighted_by_position_and_recent_cfb_production',
            'home' => $homeImpact,
            'away' => $awayImpact,
            'spread_adjustment' => round($spreadAdjustment, 3),
            'total_adjustment' => round($totalAdjustment, 3),
            'risk_flags' => [],
        ];

        if ($metadata['applied']) {
            $metadata['risk_flags'][] = 'player_availability_impact';
        }

        $this->predictionContext['player_availability'] = $metadata;

        return [
            round($predictedSpread + $spreadAdjustment, 1),
            round($predictedTotal + $totalAdjustment, 1),
        ];
    }

    /**
     * @return array{0:float,1:float}
     */
    protected function applyPreseasonSignalLayer(
        float $predictedSpread,
        float $predictedTotal,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): array {
        $week = (int) ($game->week ?? 0);
        $metadata = [
            'enabled' => false,
            'week' => $week,
            'week_bucket' => $this->weekCalibrationBucket($week),
            'adaptive_calibration' => null,
            'spread_adjustment' => 0.0,
            'total_adjustment' => 0.0,
            'confidence_penalty' => 0.0,
            'aligned_signals' => 0,
            'risk_flags' => [],
            'market' => null,
            'components' => [],
        ];
        $adaptiveCalibration = $this->activeAdaptiveCalibration((int) $game->season);
        $metadata['adaptive_calibration'] = $this->adaptiveCalibrationMetadata($adaptiveCalibration);

        [$predictedSpread, $predictedTotal, $weekCalibration] = $this->applyWeekBucketCalibration(
            $predictedSpread,
            $predictedTotal,
            $metadata['week_bucket']
        );
        $metadata['components']['week_calibration'] = $weekCalibration;
        $metadata['confidence_penalty'] += (float) ($weekCalibration['confidence_penalty'] ?? 0.0);

        [$predictedSpread, $predictedTotal, $adaptiveWeekCalibration] = $this->applyAdaptiveWeekCalibration(
            $predictedSpread,
            $predictedTotal,
            $metadata['week_bucket'],
            $adaptiveCalibration
        );
        $metadata['components']['adaptive_week_calibration'] = $adaptiveWeekCalibration;
        $metadata['spread_adjustment'] += (float) ($adaptiveWeekCalibration['spread_adjustment'] ?? 0.0);
        $metadata['total_adjustment'] += (float) ($adaptiveWeekCalibration['total_adjustment'] ?? 0.0);
        $metadata['confidence_penalty'] += (float) ($adaptiveWeekCalibration['confidence_penalty'] ?? 0.0);

        if (! $this->shouldApplyPreseasonLayer($game)) {
            $this->predictionContext['preseason_layer'] = $metadata;

            return [round($predictedSpread, 1), round($predictedTotal, 1)];
        }

        $metadata['enabled'] = true;
        $homeSignals = $this->preseasonSignalForTeam((int) $game->home_team_id, (int) $game->season);
        $awaySignals = $this->preseasonSignalForTeam((int) $game->away_team_id, (int) $game->season);

        $components = [
            'composite' => $this->preseasonCompositeAdjustment($homeMetrics, $awayMetrics, $homeSignals, $awaySignals),
            'returning_production' => $this->returningProductionAdjustment($homeSignals, $awaySignals),
            'talent_recruiting' => $this->talentRecruitingAdjustment($homeSignals, $awaySignals),
            'qb_continuity' => $this->quarterbackContinuityAdjustment($homeSignals, $awaySignals),
            'transfer_portal' => $this->transferPortalAdjustment($homeSignals, $awaySignals),
            'coaching_continuity' => $this->coachingContinuityAdjustment($homeSignals, $awaySignals),
            'coaching_scheme' => $this->coachingSchemeAdjustment($homeSignals, $awaySignals),
            'special_teams' => $this->specialTeamsAdjustment($game),
            'schedule_spot' => $this->scheduleSpotAdjustment($game),
        ];

        foreach ($components as $name => $component) {
            $component = $this->applyAdaptiveComponentMultiplier(
                $name,
                $component,
                $metadata['week_bucket'],
                $adaptiveCalibration
            );
            $predictedSpread += (float) ($component['spread_adjustment'] ?? 0.0);
            $predictedTotal += (float) ($component['total_adjustment'] ?? 0.0);
            $metadata['spread_adjustment'] += (float) ($component['spread_adjustment'] ?? 0.0);
            $metadata['total_adjustment'] += (float) ($component['total_adjustment'] ?? 0.0);
            $metadata['confidence_penalty'] += (float) ($component['confidence_penalty'] ?? 0.0);
            $metadata['risk_flags'] = array_values(array_unique([
                ...$metadata['risk_flags'],
                ...(array) ($component['risk_flags'] ?? []),
            ]));
            $metadata['components'][$name] = $component;
        }

        $alignedSignals = $this->alignedPreseasonSignalCount(
            $predictedSpread,
            $homeMetrics,
            $awayMetrics,
            $game,
            $homeSignals,
            $awaySignals
        );
        $metadata['aligned_signals'] = $alignedSignals;

        $marketGuardrail = $this->marketAwareEarlySeasonGuardrail($predictedSpread, $game, $alignedSignals);
        $metadata['market'] = $marketGuardrail;
        $metadata['confidence_penalty'] += (float) ($marketGuardrail['confidence_penalty'] ?? 0.0);
        $metadata['risk_flags'] = array_values(array_unique([
            ...$metadata['risk_flags'],
            ...(array) ($marketGuardrail['risk_flags'] ?? []),
        ]));

        $metadata['spread_adjustment'] = round($metadata['spread_adjustment'], 3);
        $metadata['total_adjustment'] = round($metadata['total_adjustment'], 3);
        $metadata['confidence_penalty'] = round($metadata['confidence_penalty'], 2);

        $this->predictionContext['preseason_layer'] = $metadata;

        return [round($predictedSpread, 1), round($predictedTotal, 1)];
    }

    protected function shouldApplyPreseasonLayer(Model $game): bool
    {
        if (! (bool) config('cfb.predictions.preseason.enabled', true)) {
            return false;
        }

        $throughWeek = (int) config('cfb.predictions.preseason.through_week', 4);

        return (int) ($game->week ?? 0) <= $throughWeek;
    }

    /**
     * @return array{0:float,1:float,2:array<string, mixed>}
     */
    protected function applyWeekBucketCalibration(float $spread, float $total, string $bucket): array
    {
        if (! (bool) config('cfb.predictions.week_calibration.enabled', true)) {
            return [$spread, $total, [
                'spread_adjustment' => 0.0,
                'total_adjustment' => 0.0,
                'confidence_penalty' => 0.0,
            ]];
        }

        $spreadMultiplier = (float) config("cfb.predictions.week_calibration.buckets.{$bucket}.spread_multiplier", 1.0);
        $spreadAdjustment = (float) config("cfb.predictions.week_calibration.buckets.{$bucket}.spread_adjustment", 0.0);
        $totalAdjustment = (float) config("cfb.predictions.week_calibration.buckets.{$bucket}.total_adjustment", 0.0);
        $confidencePenalty = (float) config("cfb.predictions.week_calibration.buckets.{$bucket}.confidence_penalty", 0.0);

        $calibratedSpread = ($spread * $spreadMultiplier) + $spreadAdjustment;
        $calibratedTotal = $total + $totalAdjustment;

        return [$calibratedSpread, $calibratedTotal, [
            'bucket' => $bucket,
            'spread_multiplier' => $spreadMultiplier,
            'spread_adjustment' => round($calibratedSpread - $spread, 3),
            'total_adjustment' => round($totalAdjustment, 3),
            'confidence_penalty' => round(max(0.0, $confidencePenalty), 2),
        ]];
    }

    /**
     * @param  array<string, mixed>|null  $adaptiveCalibration
     * @return array{0:float,1:float,2:array<string, mixed>}
     */
    protected function applyAdaptiveWeekCalibration(
        float $spread,
        float $total,
        string $bucket,
        ?array $adaptiveCalibration
    ): array {
        $bucketCalibration = data_get($adaptiveCalibration, "parameters.week_buckets.{$bucket}");

        if (! is_array($bucketCalibration)) {
            return [$spread, $total, [
                'spread_adjustment' => 0.0,
                'total_adjustment' => 0.0,
                'confidence_penalty' => 0.0,
                'source' => 'none',
            ]];
        }

        $spreadAdjustment = $this->clamp(
            (float) ($bucketCalibration['spread_adjustment'] ?? 0.0),
            (float) config('cfb.predictions.adaptive_calibration.max_spread_adjustment', 3.0)
        );
        $totalAdjustment = $this->clamp(
            (float) ($bucketCalibration['total_adjustment'] ?? 0.0),
            (float) config('cfb.predictions.adaptive_calibration.max_total_adjustment', 3.0)
        );
        $confidencePenalty = max(
            0.0,
            min(
                (float) config('cfb.predictions.adaptive_calibration.max_confidence_penalty', 4.0),
                (float) ($bucketCalibration['confidence_penalty'] ?? 0.0)
            )
        );

        return [
            $spread + $spreadAdjustment,
            $total + $totalAdjustment,
            [
                'source' => 'adaptive',
                'spread_adjustment' => round($spreadAdjustment, 3),
                'total_adjustment' => round($totalAdjustment, 3),
                'confidence_penalty' => round($confidencePenalty, 2),
                'sample_size' => (int) ($bucketCalibration['sample_size'] ?? 0),
            ],
        ];
    }

    protected function weekCalibrationBucket(int $week): string
    {
        return match (true) {
            $week <= 1 => 'week_0_1',
            $week <= 4 => 'week_2_4',
            $week <= 8 => 'week_5_8',
            default => 'week_9_plus',
        };
    }

    /**
     * @param  array<string, mixed>|null  $adaptiveCalibration
     * @return array<string, mixed>
     */
    protected function applyAdaptiveComponentMultiplier(
        string $name,
        array $component,
        string $bucket,
        ?array $adaptiveCalibration
    ): array {
        $spreadAdjustment = $component['spread_adjustment'] ?? null;

        if (! is_numeric($spreadAdjustment)) {
            return $component;
        }

        $multiplier = data_get($adaptiveCalibration, "parameters.preseason_component_multipliers.{$bucket}.{$name}");
        if (! is_numeric($multiplier)) {
            return $component;
        }

        $multiplier = max(
            (float) config('cfb.predictions.adaptive_calibration.min_component_multiplier', 0.75),
            min(
                (float) config('cfb.predictions.adaptive_calibration.max_component_multiplier', 1.25),
                (float) $multiplier
            )
        );
        $originalSpreadAdjustment = (float) $spreadAdjustment;
        $component['spread_adjustment'] = round($originalSpreadAdjustment * $multiplier, 3);
        $component['adaptive_multiplier'] = round($multiplier, 3);
        $component['adaptive_delta'] = round($component['spread_adjustment'] - $originalSpreadAdjustment, 3);

        return $component;
    }

    /**
     * @param  array<string, mixed>|null  $homeSignals
     * @param  array<string, mixed>|null  $awaySignals
     * @return array<string, mixed>
     */
    protected function preseasonCompositeAdjustment(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        ?array $homeSignals,
        ?array $awaySignals
    ): array {
        $powerDiff = $this->signalMetricDiff($homeMetrics, $awayMetrics, $homeSignals, $awaySignals, [
            'power_rating',
            'preseason_power_rating',
        ]);
        $fpiDiff = $this->signalMetricDiff($homeMetrics, $awayMetrics, $homeSignals, $awaySignals, [
            'fpi',
            'preseason_fpi',
        ]);
        $netRatingDiff = $this->signalMetricDiff($homeMetrics, $awayMetrics, $homeSignals, $awaySignals, [
            'net_rating',
            'preseason_net_rating',
        ]);

        $adjustment = ($powerDiff * (float) config('cfb.predictions.preseason.composite.power_rating_weight', 0.08))
            + ($fpiDiff * (float) config('cfb.predictions.preseason.composite.fpi_weight', 0.04))
            + ($netRatingDiff * (float) config('cfb.predictions.preseason.composite.net_rating_weight', 0.025));

        $maxAdjustment = (float) config('cfb.predictions.preseason.composite.max_adjustment', 3.0);

        return [
            'spread_adjustment' => round($this->clamp($adjustment, $maxAdjustment), 3),
            'total_adjustment' => 0.0,
            'confidence_penalty' => 0.0,
            'inputs' => [
                'power_rating_diff' => round($powerDiff, 3),
                'fpi_diff' => round($fpiDiff, 3),
                'net_rating_diff' => round($netRatingDiff, 3),
            ],
            'risk_flags' => [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $homeSignals
     * @param  array<string, mixed>|null  $awaySignals
     * @return array<string, mixed>
     */
    protected function returningProductionAdjustment(?array $homeSignals, ?array $awaySignals): array
    {
        $homeReturning = $this->normalizedPercentSignal($homeSignals, [
            'returning_production',
            'returning_production_percent',
            'returning_production_pct',
            'returning_percent_ppa',
            'percent_ppa',
            'percentPPA',
        ]);
        $awayReturning = $this->normalizedPercentSignal($awaySignals, [
            'returning_production',
            'returning_production_percent',
            'returning_production_pct',
            'returning_percent_ppa',
            'percent_ppa',
            'percentPPA',
        ]);

        if ($homeReturning === null || $awayReturning === null) {
            return $this->emptyPreseasonComponent();
        }

        $adjustment = ($homeReturning - $awayReturning)
            * (float) config('cfb.predictions.preseason.returning_production.points_per_full_retention_gap', 8.0);
        $maxAdjustment = (float) config('cfb.predictions.preseason.returning_production.max_adjustment', 2.5);

        return [
            'spread_adjustment' => round($this->clamp($adjustment, $maxAdjustment), 3),
            'total_adjustment' => 0.0,
            'confidence_penalty' => 0.0,
            'inputs' => [
                'home' => round($homeReturning, 3),
                'away' => round($awayReturning, 3),
            ],
            'risk_flags' => [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $homeSignals
     * @param  array<string, mixed>|null  $awaySignals
     * @return array<string, mixed>
     */
    protected function talentRecruitingAdjustment(?array $homeSignals, ?array $awaySignals): array
    {
        $homeScore = $this->talentRecruitingScore($homeSignals);
        $awayScore = $this->talentRecruitingScore($awaySignals);

        if ($homeScore === null || $awayScore === null) {
            return $this->emptyPreseasonComponent();
        }

        $adjustment = ($homeScore - $awayScore)
            * (float) config('cfb.predictions.preseason.talent_recruiting.points_per_score_gap', 4.0);
        $maxAdjustment = (float) config('cfb.predictions.preseason.talent_recruiting.max_adjustment', 1.5);

        return [
            'spread_adjustment' => round($this->clamp($adjustment, $maxAdjustment), 3),
            'total_adjustment' => 0.0,
            'confidence_penalty' => 0.0,
            'inputs' => [
                'home_score' => round($homeScore, 3),
                'away_score' => round($awayScore, 3),
            ],
            'risk_flags' => [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $homeSignals
     * @param  array<string, mixed>|null  $awaySignals
     * @return array<string, mixed>
     */
    protected function quarterbackContinuityAdjustment(?array $homeSignals, ?array $awaySignals): array
    {
        $homeScore = $this->quarterbackContinuityScore($homeSignals);
        $awayScore = $this->quarterbackContinuityScore($awaySignals);

        if ($homeScore === null || $awayScore === null) {
            return $this->emptyPreseasonComponent();
        }

        $adjustment = ($homeScore - $awayScore)
            * (float) config('cfb.predictions.preseason.qb_continuity.points_per_score_gap', 1.5);
        $maxAdjustment = (float) config('cfb.predictions.preseason.qb_continuity.max_adjustment', 2.0);
        $penalty = $this->uncertaintyPenalty($homeScore, $awayScore, 'qb_continuity');

        return [
            'spread_adjustment' => round($this->clamp($adjustment, $maxAdjustment), 3),
            'total_adjustment' => 0.0,
            'confidence_penalty' => $penalty,
            'inputs' => [
                'home_score' => round($homeScore, 3),
                'away_score' => round($awayScore, 3),
            ],
            'risk_flags' => $penalty > 0 ? ['qb_continuity_uncertainty'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $homeSignals
     * @param  array<string, mixed>|null  $awaySignals
     * @return array<string, mixed>
     */
    protected function transferPortalAdjustment(?array $homeSignals, ?array $awaySignals): array
    {
        $homeScore = $this->transferPortalScore($homeSignals);
        $awayScore = $this->transferPortalScore($awaySignals);

        if ($homeScore === null || $awayScore === null) {
            return $this->emptyPreseasonComponent();
        }

        $adjustment = ($homeScore - $awayScore)
            * (float) config('cfb.predictions.preseason.transfer_portal.points_per_score_gap', 2.5);
        $maxAdjustment = (float) config('cfb.predictions.preseason.transfer_portal.max_adjustment', 2.0);
        $penalty = $this->uncertaintyPenalty($homeScore, $awayScore, 'transfer_portal');

        return [
            'spread_adjustment' => round($this->clamp($adjustment, $maxAdjustment), 3),
            'total_adjustment' => 0.0,
            'confidence_penalty' => $penalty,
            'inputs' => [
                'home_score' => round($homeScore, 3),
                'away_score' => round($awayScore, 3),
            ],
            'risk_flags' => $penalty > 0 ? ['transfer_portal_volatility'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $homeSignals
     * @param  array<string, mixed>|null  $awaySignals
     * @return array<string, mixed>
     */
    protected function coachingContinuityAdjustment(?array $homeSignals, ?array $awaySignals): array
    {
        $homeScore = $this->coachingContinuityScore($homeSignals);
        $awayScore = $this->coachingContinuityScore($awaySignals);

        if ($homeScore === null || $awayScore === null) {
            return $this->emptyPreseasonComponent();
        }

        $adjustment = ($homeScore - $awayScore)
            * (float) config('cfb.predictions.preseason.coaching_continuity.points_per_score_gap', 0.8);
        $maxAdjustment = (float) config('cfb.predictions.preseason.coaching_continuity.max_adjustment', 1.0);
        $penalty = $this->uncertaintyPenalty($homeScore, $awayScore, 'coaching_continuity');

        return [
            'spread_adjustment' => round($this->clamp($adjustment, $maxAdjustment), 3),
            'total_adjustment' => 0.0,
            'confidence_penalty' => $penalty,
            'inputs' => [
                'home_score' => round($homeScore, 3),
                'away_score' => round($awayScore, 3),
            ],
            'risk_flags' => $penalty > 0 ? ['coaching_staff_change'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $homeSignals
     * @param  array<string, mixed>|null  $awaySignals
     * @return array<string, mixed>
     */
    protected function coachingSchemeAdjustment(?array $homeSignals, ?array $awaySignals): array
    {
        $homeScore = $this->coachingSchemeScore($homeSignals);
        $awayScore = $this->coachingSchemeScore($awaySignals);

        if ($homeScore === null || $awayScore === null) {
            return $this->emptyPreseasonComponent();
        }

        $spreadAdjustment = ($homeScore - $awayScore)
            * (float) config('cfb.predictions.preseason.coaching_scheme.points_per_score_gap', 0.7);
        $homeTotalSignal = $this->coachingSchemeTotalSignal($homeSignals) ?? 0.0;
        $awayTotalSignal = $this->coachingSchemeTotalSignal($awaySignals) ?? 0.0;
        $totalAdjustment = ($homeTotalSignal + $awayTotalSignal)
            * (float) config('cfb.predictions.preseason.coaching_scheme.total_points_per_score', 0.8);
        $homeVolatility = $this->coachingSchemeVolatility($homeSignals);
        $awayVolatility = $this->coachingSchemeVolatility($awaySignals);
        $volatilityThreshold = (float) config('cfb.predictions.preseason.coaching_scheme.volatility_threshold', 0.55);
        $volatileSides = (int) ($homeVolatility >= $volatilityThreshold) + (int) ($awayVolatility >= $volatilityThreshold);
        $confidencePenalty = round(max(0.0, $volatileSides * (float) config('cfb.predictions.preseason.coaching_scheme.confidence_penalty_per_volatile_side', 1.5)), 2);

        return [
            'spread_adjustment' => round($this->clamp(
                $spreadAdjustment,
                (float) config('cfb.predictions.preseason.coaching_scheme.max_adjustment', 1.0)
            ), 3),
            'total_adjustment' => round($this->clamp(
                $totalAdjustment,
                (float) config('cfb.predictions.preseason.coaching_scheme.max_total_adjustment', 1.25)
            ), 3),
            'confidence_penalty' => $confidencePenalty,
            'inputs' => [
                'home_score' => round($homeScore, 3),
                'away_score' => round($awayScore, 3),
                'home_total_signal' => round($homeTotalSignal, 3),
                'away_total_signal' => round($awayTotalSignal, 3),
                'home_volatility' => round($homeVolatility, 3),
                'away_volatility' => round($awayVolatility, 3),
            ],
            'risk_flags' => $confidencePenalty > 0 ? ['coaching_scheme_volatility'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function specialTeamsAdjustment(Model $game): array
    {
        $homeRating = $this->latestSpecialTeamsRating((int) $game->home_team_id, (int) $game->season, (int) ($game->week ?? 0));
        $awayRating = $this->latestSpecialTeamsRating((int) $game->away_team_id, (int) $game->season, (int) ($game->week ?? 0));

        if ($homeRating === null || $awayRating === null) {
            return $this->emptyPreseasonComponent();
        }

        $homeValue = (float) $homeRating['rating'];
        $awayValue = (float) $awayRating['rating'];
        $ratingDiff = $homeValue - $awayValue;
        $spreadAdjustment = $ratingDiff * (float) config('cfb.predictions.preseason.special_teams.spread_weight', 0.15);
        $totalAdjustment = ($homeValue + $awayValue) * (float) config('cfb.predictions.preseason.special_teams.total_weight', 0.04);
        $mismatchThreshold = (float) config('cfb.predictions.preseason.special_teams.mismatch_threshold', 4.0);

        return [
            'spread_adjustment' => round($this->clamp(
                $spreadAdjustment,
                (float) config('cfb.predictions.preseason.special_teams.max_adjustment', 1.25)
            ), 3),
            'total_adjustment' => round($this->clamp(
                $totalAdjustment,
                (float) config('cfb.predictions.preseason.special_teams.max_total_adjustment', 1.0)
            ), 3),
            'confidence_penalty' => 0.0,
            'inputs' => [
                'home_rating' => round($homeValue, 3),
                'away_rating' => round($awayValue, 3),
                'home_source' => [
                    'season' => (int) $homeRating['season'],
                    'week' => (int) $homeRating['week'],
                ],
                'away_source' => [
                    'season' => (int) $awayRating['season'],
                    'week' => (int) $awayRating['week'],
                ],
            ],
            'risk_flags' => abs($ratingDiff) >= $mismatchThreshold ? ['special_teams_mismatch'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function scheduleSpotAdjustment(Model $game): array
    {
        $adjustment = 0.0;
        $inputs = [];

        $homeRest = $this->numericGameAttribute($game, ['home_rest_days', 'home_days_rest', 'rest_days_home']);
        $awayRest = $this->numericGameAttribute($game, ['away_rest_days', 'away_days_rest', 'rest_days_away']);
        if ($homeRest !== null && $awayRest !== null) {
            $restDiff = max(-3.0, min(3.0, $homeRest - $awayRest));
            $adjustment += $restDiff * (float) config('cfb.predictions.preseason.schedule_spot.rest_day_weight', 0.25);
            $inputs['rest_day_diff'] = round($restDiff, 3);
        }

        $homeTravel = $this->numericGameAttribute($game, ['home_travel_miles', 'home_distance_traveled', 'home_travel_distance']);
        $awayTravel = $this->numericGameAttribute($game, ['away_travel_miles', 'away_distance_traveled', 'away_travel_distance']);
        if ($homeTravel !== null && $awayTravel !== null) {
            $travelDiffThousands = max(-3.0, min(3.0, ($awayTravel - $homeTravel) / 1000));
            $adjustment += $travelDiffThousands * (float) config('cfb.predictions.preseason.schedule_spot.travel_1000_miles_weight', 0.35);
            $inputs['away_minus_home_travel_1000_miles'] = round($travelDiffThousands, 3);
        }

        return [
            'spread_adjustment' => round($this->clamp(
                $adjustment,
                (float) config('cfb.predictions.preseason.schedule_spot.max_adjustment', 1.25)
            ), 3),
            'total_adjustment' => 0.0,
            'confidence_penalty' => 0.0,
            'inputs' => $inputs,
            'risk_flags' => [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $homeSignals
     * @param  array<string, mixed>|null  $awaySignals
     */
    protected function alignedPreseasonSignalCount(
        float $spread,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game,
        ?array $homeSignals,
        ?array $awaySignals
    ): int {
        $direction = $this->signalDirection($spread, 0.5);
        if ($direction === 0) {
            return 0;
        }

        $signals = [
            'elo' => (float) ($this->predictionContext['base_spread'] ?? 0.0),
            'fpi' => $this->signalMetricDiff($homeMetrics, $awayMetrics, $homeSignals, $awaySignals, ['fpi', 'preseason_fpi']),
            'net_rating' => $this->signalMetricDiff($homeMetrics, $awayMetrics, $homeSignals, $awaySignals, ['net_rating', 'preseason_net_rating']),
            'power_rating' => $this->signalMetricDiff($homeMetrics, $awayMetrics, $homeSignals, $awaySignals, ['power_rating', 'preseason_power_rating']),
            'rating_consensus' => $this->metricDiff($homeMetrics, $awayMetrics, 'rating_consensus'),
            'net_success_rate' => $this->metricDiff($homeMetrics, $awayMetrics, 'net_success_rate'),
            'net_explosiveness' => $this->metricDiff($homeMetrics, $awayMetrics, 'net_explosiveness'),
            'net_havoc_rate' => $this->metricDiff($homeMetrics, $awayMetrics, 'net_havoc_rate'),
            'ol_qb_environment' => $this->offensiveLineQuarterbackEnvironmentDiff($homeMetrics, $awayMetrics),
            'returning_production' => $this->nullableDiff(
                $this->normalizedPercentSignal($homeSignals, ['returning_production', 'returning_production_percent', 'returning_production_pct', 'returning_percent_ppa', 'percent_ppa', 'percentPPA']),
                $this->normalizedPercentSignal($awaySignals, ['returning_production', 'returning_production_percent', 'returning_production_pct', 'returning_percent_ppa', 'percent_ppa', 'percentPPA'])
            ),
            'talent_recruiting' => $this->nullableDiff($this->talentRecruitingScore($homeSignals), $this->talentRecruitingScore($awaySignals)),
            'qb_continuity' => $this->nullableDiff($this->quarterbackContinuityScore($homeSignals), $this->quarterbackContinuityScore($awaySignals)),
            'transfer_portal' => $this->nullableDiff($this->transferPortalScore($homeSignals), $this->transferPortalScore($awaySignals)),
            'coaching_scheme' => $this->nullableDiff($this->coachingSchemeScore($homeSignals), $this->coachingSchemeScore($awaySignals)),
            'special_teams' => $this->nullableDiff(
                data_get($this->latestSpecialTeamsRating((int) $game->home_team_id, (int) $game->season, (int) ($game->week ?? 0)), 'rating'),
                data_get($this->latestSpecialTeamsRating((int) $game->away_team_id, (int) $game->season, (int) ($game->week ?? 0)), 'rating')
            ),
        ];

        return collect($signals)
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->filter(function (float|int $value) use ($direction): bool {
                return $this->signalDirection((float) $value, 0.05) === $direction;
            })
            ->count();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function marketAwareEarlySeasonGuardrail(float $spread, Model $game, int $alignedSignals): ?array
    {
        if (! (bool) config('cfb.predictions.preseason.market_guardrail.enabled', true)) {
            return null;
        }

        if ((int) ($game->week ?? 0) > (int) config('cfb.predictions.preseason.market_guardrail.through_week', 2)) {
            return null;
        }

        $marketHomeLine = $this->extractMarketHomeSpread($game);
        if ($marketHomeLine === null) {
            return null;
        }

        $marketHomeMargin = -$marketHomeLine;
        $disagreement = abs($spread - $marketHomeMargin);
        $threshold = (float) config('cfb.predictions.preseason.market_guardrail.large_disagreement_threshold', 10.0);

        if ($disagreement < $threshold) {
            return [
                'bookmaker_home_line' => round($marketHomeLine, 1),
                'market_home_margin' => round($marketHomeMargin, 1),
                'disagreement' => round($disagreement, 1),
                'confidence_penalty' => 0.0,
                'risk_flags' => [],
            ];
        }

        $requiredSignals = (int) config('cfb.predictions.preseason.market_guardrail.required_aligned_signals', 3);
        $confidencePenalty = $alignedSignals >= $requiredSignals
            ? (float) config('cfb.predictions.preseason.market_guardrail.confirmed_disagreement_penalty', 3.0)
            : (float) config('cfb.predictions.preseason.market_guardrail.unconfirmed_disagreement_penalty', 12.0);

        return [
            'bookmaker_home_line' => round($marketHomeLine, 1),
            'market_home_margin' => round($marketHomeMargin, 1),
            'disagreement' => round($disagreement, 1),
            'required_aligned_signals' => $requiredSignals,
            'aligned_signals' => $alignedSignals,
            'confidence_penalty' => round($confidencePenalty, 2),
            'risk_flags' => $alignedSignals >= $requiredSignals
                ? ['market_disagreement_confirmed']
                : ['market_disagreement_watchlist'],
        ];
    }

    protected function applyStoredConfidencePenalty(float $confidenceScore): float
    {
        $penalty = (float) data_get($this->predictionContext, 'preseason_layer.confidence_penalty', 0.0)
            + (float) data_get($this->predictionContext, 'game_context.confidence_penalty', 0.0);
        $minimum = (float) config('cfb.predictions.preseason.min_confidence_after_penalties', 50.0);

        return round(max($minimum, $confidenceScore - max(0.0, $penalty)), 2);
    }

    protected function applyMarketMovementConfidenceAdjustment(float $confidenceScore): float
    {
        $adjustment = (float) data_get($this->predictionContext, 'market_movement.confidence_adjustment', 0.0);
        $maxAdjustment = (float) config('cfb.predictions.market_movement.max_confidence_adjustment', 3.0);
        $minimum = (float) config('cfb.predictions.preseason.min_confidence_after_penalties', 50.0);

        return round(max($minimum, min(100.0, $confidenceScore + $this->clamp($adjustment, $maxAdjustment))), 2);
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyPreseasonComponent(): array
    {
        return [
            'spread_adjustment' => 0.0,
            'total_adjustment' => 0.0,
            'confidence_penalty' => 0.0,
            'inputs' => [],
            'risk_flags' => [],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $homeSignals
     * @param  array<string, mixed>|null  $awaySignals
     * @param  array<int, string>  $keys
     */
    protected function signalMetricDiff(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        ?array $homeSignals,
        ?array $awaySignals,
        array $keys
    ): float {
        $homeValue = $this->numericSignalOrMetric($homeSignals, $homeMetrics, $keys);
        $awayValue = $this->numericSignalOrMetric($awaySignals, $awayMetrics, $keys);

        if ($homeValue === null || $awayValue === null) {
            return 0.0;
        }

        return $homeValue - $awayValue;
    }

    /**
     * @param  array<string, mixed>|null  $signals
     * @param  array<int, string>  $keys
     */
    protected function numericSignalOrMetric(?array $signals, ?Model $metrics, array $keys): ?float
    {
        $signalValue = $this->numericSignal($signals, $keys);
        if ($signalValue !== null) {
            return $signalValue;
        }

        foreach ($keys as $key) {
            $metricKey = str_starts_with($key, 'preseason_') ? substr($key, 10) : $key;
            $value = $metrics?->{$metricKey};

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $signals
     * @param  array<int, string>  $keys
     */
    protected function normalizedPercentSignal(?array $signals, array $keys): ?float
    {
        $value = $this->numericSignal($signals, $keys);

        if ($value === null) {
            return null;
        }

        if ($value > 1.5) {
            $value /= 100;
        }

        return max(0.0, min(1.0, $value));
    }

    /**
     * @param  array<string, mixed>|null  $signals
     * @param  array<int, string>  $keys
     */
    protected function normalizedSignedSignal(?array $signals, array $keys): ?float
    {
        $value = $this->numericSignal($signals, $keys);

        if ($value === null) {
            return null;
        }

        if (abs($value) > 1.5) {
            $value /= 100;
        }

        return max(-1.0, min(1.0, $value));
    }

    /**
     * @param  array<string, mixed>|null  $signals
     * @param  array<int, string>  $keys
     */
    protected function numericSignal(?array $signals, array $keys): ?float
    {
        if ($signals === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = data_get($signals, $key);

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $signals
     */
    protected function quarterbackContinuityScore(?array $signals): ?float
    {
        $score = $this->normalizedSignedSignal($signals, [
            'qb_continuity_score',
            'quarterback_continuity_score',
            'qb_score',
        ]);

        if ($score !== null) {
            return $score;
        }

        $returningStarter = $this->booleanSignal($signals, ['returning_starting_qb', 'qb_returning_starter']);
        if ($returningStarter !== null) {
            return $returningStarter ? 1.0 : -0.6;
        }

        return $this->statusScore($signals, [
            'qb_continuity_classification',
            'qb_continuity',
            'qb_status',
            'starting_qb_status',
        ], (array) config('cfb.predictions.preseason.qb_continuity.status_scores', []));
    }

    /**
     * @param  array<string, mixed>|null  $signals
     */
    protected function coachingContinuityScore(?array $signals): ?float
    {
        $score = $this->normalizedSignedSignal($signals, [
            'coaching_continuity_score',
            'coordinator_continuity_score',
            'staff_continuity_score',
            'coach_score',
        ]);

        if ($score !== null) {
            return $score;
        }

        $statusScore = $this->statusScore($signals, [
            'coaching_continuity',
            'staff_status',
            'coach_status',
        ], (array) config('cfb.predictions.preseason.coaching_continuity.status_scores', []));

        if ($statusScore !== null) {
            return $statusScore;
        }

        $newHeadCoach = $this->booleanSignal($signals, ['new_head_coach', 'new_hc', 'head_coach_change']);
        $newOc = $this->booleanSignal($signals, ['new_offensive_coordinator', 'new_oc', 'offensive_coordinator_change']);
        $newDc = $this->booleanSignal($signals, ['new_defensive_coordinator', 'new_dc', 'defensive_coordinator_change']);
        $newStaff = $this->booleanSignal($signals, ['new_staff', 'staff_change']);

        if ($newHeadCoach === null && $newOc === null && $newDc === null && $newStaff === null) {
            return null;
        }

        if ($newHeadCoach === true || $newStaff === true) {
            return -1.0;
        }

        $score = 1.0;
        $score -= $newOc === true ? 0.35 : 0.0;
        $score -= $newDc === true ? 0.35 : 0.0;

        return max(-1.0, min(1.0, $score));
    }

    /**
     * @param  array<string, mixed>|null  $signals
     */
    protected function coachingSchemeScore(?array $signals): ?float
    {
        $explicitScore = $this->normalizedSignedSignal($signals, [
            'scheme_continuity_score',
            'scheme_fit_score',
            'scheme_stability_score',
            'coaching_scheme_score',
            'coaching_continuity_payload.scheme_continuity_score',
            'coaching_continuity_payload.scheme_fit_score',
            'coaching_continuity_payload.scheme_stability_score',
            'coaching_continuity_payload.coaching_scheme_score',
        ]);

        if ($explicitScore !== null) {
            return $explicitScore;
        }

        $schemeSeverity = $this->coachingSchemeVolatility($signals);
        $newOffensiveScheme = $this->booleanSignal($signals, [
            'new_offensive_scheme',
            'offensive_scheme_change',
            'coaching_continuity_payload.new_offensive_scheme',
            'coaching_continuity_payload.offensive_scheme_change',
        ]);
        $newDefensiveScheme = $this->booleanSignal($signals, [
            'new_defensive_scheme',
            'defensive_scheme_change',
            'coaching_continuity_payload.new_defensive_scheme',
            'coaching_continuity_payload.defensive_scheme_change',
        ]);
        $newPlayCaller = $this->booleanSignal($signals, [
            'new_play_caller',
            'new_offensive_play_caller',
            'coaching_continuity_payload.new_play_caller',
            'coaching_continuity_payload.new_offensive_play_caller',
        ]);

        if ($schemeSeverity <= 0.0 && $newOffensiveScheme === null && $newDefensiveScheme === null && $newPlayCaller === null) {
            return null;
        }

        $score = 1.0 - ($schemeSeverity * 1.2);
        $score -= $newOffensiveScheme === true ? 0.35 : 0.0;
        $score -= $newDefensiveScheme === true ? 0.25 : 0.0;
        $score -= $newPlayCaller === true ? 0.20 : 0.0;

        return max(-1.0, min(1.0, $score));
    }

    /**
     * @param  array<string, mixed>|null  $signals
     */
    protected function coachingSchemeTotalSignal(?array $signals): ?float
    {
        return $this->normalizedSignedSignal($signals, [
            'scheme_total_signal',
            'tempo_change_score',
            'pace_change_score',
            'pace_delta_score',
            'expected_pace_change',
            'coaching_continuity_payload.scheme_total_signal',
            'coaching_continuity_payload.tempo_change_score',
            'coaching_continuity_payload.pace_change_score',
            'coaching_continuity_payload.pace_delta_score',
            'coaching_continuity_payload.expected_pace_change',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $signals
     */
    protected function coachingSchemeVolatility(?array $signals): float
    {
        $severity = $this->normalizedPercentSignal($signals, [
            'scheme_change_severity',
            'scheme_change_score',
            'coaching_scheme_volatility',
            'coaching_continuity_payload.scheme_change_severity',
            'coaching_continuity_payload.scheme_change_score',
            'coaching_continuity_payload.coaching_scheme_volatility',
        ]);

        if ($severity !== null) {
            return $severity;
        }

        $volatileSignals = [
            $this->booleanSignal($signals, ['new_offensive_scheme', 'offensive_scheme_change', 'coaching_continuity_payload.new_offensive_scheme', 'coaching_continuity_payload.offensive_scheme_change']),
            $this->booleanSignal($signals, ['new_defensive_scheme', 'defensive_scheme_change', 'coaching_continuity_payload.new_defensive_scheme', 'coaching_continuity_payload.defensive_scheme_change']),
            $this->booleanSignal($signals, ['new_play_caller', 'new_offensive_play_caller', 'coaching_continuity_payload.new_play_caller', 'coaching_continuity_payload.new_offensive_play_caller']),
        ];

        return min(1.0, collect($volatileSignals)->filter(fn (?bool $value): bool => $value === true)->count() * 0.25);
    }

    /**
     * @param  array<string, mixed>|null  $signals
     */
    protected function talentRecruitingScore(?array $signals): ?float
    {
        $explicitScore = $this->normalizedSignedSignal($signals, [
            'talent_recruiting_score',
            'talent_score',
            'recruiting_talent_score',
        ]);

        if ($explicitScore !== null) {
            return $explicitScore;
        }

        $scoreParts = [];

        $talentComposite = $this->numericSignal($signals, ['talent_composite', 'talent_rating', 'talent']);
        if ($talentComposite !== null) {
            $scale = max(1.0, (float) config('cfb.predictions.preseason.talent_recruiting.talent_composite_scale', 1000.0));
            $scoreParts[] = max(0.0, min(1.0, $talentComposite / $scale));
        }

        $recruitingPoints = $this->numericSignal($signals, ['recruiting_points', 'recruiting_talent', 'recruiting_score']);
        if ($recruitingPoints !== null) {
            $scale = max(1.0, (float) config('cfb.predictions.preseason.talent_recruiting.recruiting_points_scale', 350.0));
            $scoreParts[] = max(0.0, min(1.0, $recruitingPoints / $scale));
        }

        $recruitingRank = $this->numericSignal($signals, ['recruiting_rank', 'talent_rank']);
        if ($recruitingRank !== null && $recruitingRank > 0) {
            $teamCount = max(1.0, (float) config('cfb.predictions.preseason.talent_recruiting.recruiting_rank_team_count', 134.0));
            $scoreParts[] = max(0.0, min(1.0, ($teamCount - $recruitingRank + 1.0) / $teamCount));
        }

        if ($scoreParts === []) {
            return null;
        }

        return array_sum($scoreParts) / count($scoreParts);
    }

    /**
     * @param  array<string, mixed>|null  $signals
     */
    protected function transferPortalScore(?array $signals): ?float
    {
        $explicitScore = $this->normalizedSignedSignal($signals, [
            'transfer_portal_net_score',
            'portal_net_score',
            'transfer_net_score',
        ]);

        if ($explicitScore !== null) {
            return $explicitScore;
        }

        $netValue = $this->numericSignal($signals, [
            'transfer_net_value',
            'portal_net_rating',
            'transfer_portal_net',
        ]);

        if ($netValue === null) {
            return null;
        }

        $weightedValue = $netValue
            + (($this->numericSignal($signals, ['transfer_qb_net_value']) ?? 0.0) * 0.75)
            + (($this->numericSignal($signals, ['transfer_ol_net_value']) ?? 0.0) * 0.20)
            + (($this->numericSignal($signals, ['transfer_dl_net_value']) ?? 0.0) * 0.20);
        $normalizer = max(0.1, (float) config('cfb.predictions.preseason.transfer_portal.value_normalizer', 4.0));

        return $this->clamp($weightedValue / $normalizer, 1.0);
    }

    /**
     * @param  array<string, mixed>|null  $signals
     * @param  array<int, string>  $keys
     * @param  array<string, mixed>  $map
     */
    protected function statusScore(?array $signals, array $keys, array $map): ?float
    {
        if ($signals === null) {
            return null;
        }

        foreach ($keys as $key) {
            $value = data_get($signals, $key);

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $normalized = strtolower(str_replace([' ', '-'], '_', trim($value)));

            if (is_numeric($map[$normalized] ?? null)) {
                return max(-1.0, min(1.0, (float) $map[$normalized]));
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $signals
     * @param  array<int, string>  $keys
     */
    protected function booleanSignal(?array $signals, array $keys): ?bool
    {
        if ($signals === null) {
            return null;
        }

        foreach ($keys as $key) {
            if (str_contains($key, '.')) {
                $value = data_get($signals, $key);
            } elseif (array_key_exists($key, $signals)) {
                $value = $signals[$key];
            } else {
                continue;
            }

            if (is_bool($value)) {
                return $value;
            }

            if (is_numeric($value)) {
                return (int) $value === 1;
            }

            if (is_string($value)) {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
        }

        return null;
    }

    protected function uncertaintyPenalty(float $homeScore, float $awayScore, string $key): float
    {
        $threshold = (float) config("cfb.predictions.preseason.{$key}.uncertainty_score_threshold", 0.0);
        $penaltyPerSide = (float) config("cfb.predictions.preseason.{$key}.confidence_penalty_per_uncertain_side", 2.0);

        $uncertainSides = (int) ($homeScore <= $threshold) + (int) ($awayScore <= $threshold);

        return round(max(0.0, $uncertainSides * $penaltyPerSide), 2);
    }

    protected function nullableDiff(?float $homeValue, ?float $awayValue): ?float
    {
        if ($homeValue === null || $awayValue === null) {
            return null;
        }

        return $homeValue - $awayValue;
    }

    /**
     * @param  array<int, string>  $keys
     */
    protected function numericGameAttribute(Model $game, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $game->getAttribute($key);

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function preseasonSignalForTeam(int $teamId, int $season): ?array
    {
        $table = (string) config('cfb.predictions.preseason.signal_table', 'cfb_preseason_team_signals');

        if ($table === '' || ! Schema::hasTable($table) || ! $this->tableHasColumn($table, 'team_id')) {
            return null;
        }

        $query = DB::table($table)->where('team_id', $teamId);

        if ($this->tableHasColumn($table, 'season')) {
            $query->where('season', '<=', $season)->orderByDesc('season');
        }

        foreach (['as_of_date', 'snapshot_date', 'updated_at', 'created_at'] as $dateColumn) {
            if ($this->tableHasColumn($table, $dateColumn)) {
                $query->orderByDesc($dateColumn);
            }
        }

        $row = $query->first();

        if (! $row) {
            return null;
        }

        $signals = (array) $row;

        foreach ($signals as $column => $value) {
            if (! is_string($column) || ! str_ends_with($column, '_payload')) {
                continue;
            }

            $decoded = $this->decodeSignalPayload($value);

            if ($decoded !== null) {
                $signals[$column] = $decoded;
            }
        }

        foreach (['payload', 'signals', 'metadata'] as $jsonColumn) {
            $decoded = $this->decodeSignalPayload($signals[$jsonColumn] ?? null);

            if ($decoded !== null) {
                $signals = array_replace_recursive($signals, $decoded);
            }
        }

        return $signals;
    }

    /**
     * @return array{rating: float, season: int, week: int}|null
     */
    protected function latestSpecialTeamsRating(int $teamId, int $season, int $week): ?array
    {
        $cacheKey = "{$teamId}:{$season}:{$week}";

        if (array_key_exists($cacheKey, $this->specialTeamsRatingCache)) {
            return $this->specialTeamsRatingCache[$cacheKey];
        }

        if (! Schema::hasTable((new FpiRating)->getTable())) {
            return $this->specialTeamsRatingCache[$cacheKey] = null;
        }

        $rating = FpiRating::query()
            ->where('team_id', $teamId)
            ->where('season', '<=', $season)
            ->where(function ($query) use ($season, $week): void {
                $query->where('season', '<', $season)
                    ->orWhere('week', '<=', max(1, $week));
            })
            ->orderByDesc('season')
            ->orderByDesc('week')
            ->first();

        $value = data_get($rating, 'special_teams');

        if ($value === null) {
            $value = data_get($rating, 'special_teams_fpi');
        }

        if (! is_numeric($value)) {
            return $this->specialTeamsRatingCache[$cacheKey] = null;
        }

        return $this->specialTeamsRatingCache[$cacheKey] = [
            'rating' => (float) $value,
            'season' => (int) $rating->season,
            'week' => (int) $rating->week,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeSignalPayload(mixed $payload): ?array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || trim($payload) === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function tableHasColumn(string $table, string $column): bool
    {
        if (! isset($this->tableColumns[$table])) {
            $this->tableColumns[$table] = Schema::getColumnListing($table);
        }

        return in_array($column, $this->tableColumns[$table], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function activeAdaptiveCalibration(int $season): ?array
    {
        if (! (bool) config('cfb.predictions.adaptive_calibration.enabled', true)) {
            return null;
        }

        if (array_key_exists($season, $this->adaptiveCalibrationCache)) {
            return $this->adaptiveCalibrationCache[$season];
        }

        if (! Schema::hasTable('cfb_prediction_calibrations')) {
            return $this->adaptiveCalibrationCache[$season] = null;
        }

        $calibration = PredictionCalibration::query()
            ->active()
            ->where('season', $season)
            ->latest('generated_at')
            ->latest('id')
            ->first();

        if (! $calibration) {
            return $this->adaptiveCalibrationCache[$season] = null;
        }

        return $this->adaptiveCalibrationCache[$season] = [
            'id' => (int) $calibration->id,
            'generated_at' => $calibration->generated_at?->toIso8601String(),
            'training_from_week' => $calibration->training_from_week,
            'training_through_week' => $calibration->training_through_week,
            'games_count' => $calibration->games_count,
            'parameters' => (array) $calibration->parameters,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $adaptiveCalibration
     * @return array<string, mixed>|null
     */
    protected function adaptiveCalibrationMetadata(?array $adaptiveCalibration): ?array
    {
        if ($adaptiveCalibration === null) {
            return null;
        }

        return [
            'id' => $adaptiveCalibration['id'] ?? null,
            'generated_at' => $adaptiveCalibration['generated_at'] ?? null,
            'training_from_week' => $adaptiveCalibration['training_from_week'] ?? null,
            'training_through_week' => $adaptiveCalibration['training_through_week'] ?? null,
            'games_count' => $adaptiveCalibration['games_count'] ?? null,
        ];
    }

    protected function extractMarketHomeSpread(Model $game): ?float
    {
        if ($game instanceof Game) {
            $quoteHomeLine = app(CfbMarketMovementSignalService::class)->currentBookmakerHomeLine($game);
            if ($quoteHomeLine !== null) {
                return $quoteHomeLine;
            }
        }

        $oddsData = $game->odds_data;

        if (! is_array($oddsData) || ! isset($oddsData['bookmakers'])) {
            return null;
        }

        $homeNames = $this->teamNames($game->homeTeam);

        foreach ($oddsData['bookmakers'] as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'spreads') {
                    continue;
                }

                foreach (($market['outcomes'] ?? []) as $outcome) {
                    if (! is_numeric($outcome['point'] ?? null)) {
                        continue;
                    }

                    if ($this->outcomeMatchesTeam((string) ($outcome['name'] ?? ''), $homeNames)) {
                        return (float) $outcome['point'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function teamNames(?Model $team): array
    {
        if (! $team) {
            return [];
        }

        return collect([
            $team->name,
            $team->display_name,
            $team->short_display_name,
            $team->school,
            $team->abbreviation,
            $team->location,
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => strtolower(trim($value)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $teamNames
     */
    protected function outcomeMatchesTeam(string $outcomeName, array $teamNames): bool
    {
        $normalizedOutcome = strtolower(trim($outcomeName));

        if ($normalizedOutcome === '') {
            return false;
        }

        foreach ($teamNames as $teamName) {
            if ($normalizedOutcome === $teamName
                || str_contains($normalizedOutcome, $teamName)
                || str_contains($teamName, $normalizedOutcome)
            ) {
                return true;
            }
        }

        return false;
    }

    protected function clamp(float $value, float $maxAbsoluteValue): float
    {
        $maxAbsoluteValue = abs($maxAbsoluteValue);

        return max(-$maxAbsoluteValue, min($maxAbsoluteValue, $value));
    }

    protected function metricDiff(?Model $homeMetrics, ?Model $awayMetrics, string $metric): float
    {
        $homeValue = $this->numericMetric($homeMetrics, $metric);
        $awayValue = $this->numericMetric($awayMetrics, $metric);

        if ($homeValue === null || $awayValue === null) {
            return 0.0;
        }

        return $homeValue - $awayValue;
    }

    protected function numericMetric(?Model $metrics, string $metric): ?float
    {
        $value = $metrics?->{$metric};

        return is_numeric($value) ? (float) $value : null;
    }

    protected function offensiveLineQuarterbackEnvironmentDiff(?Model $homeMetrics, ?Model $awayMetrics): float
    {
        $homeRating = $this->averageNumericMetrics($homeMetrics, [
            'offensive_line_rating',
            'qb_environment_rating',
        ]);
        $awayRating = $this->averageNumericMetrics($awayMetrics, [
            'offensive_line_rating',
            'qb_environment_rating',
        ]);

        if ($homeRating === null || $awayRating === null) {
            return 0.0;
        }

        return $homeRating - $awayRating;
    }

    /**
     * @param  array<int, string>  $metrics
     */
    protected function averageNumericMetrics(?Model $model, array $metrics): ?float
    {
        $values = [];

        foreach ($metrics as $metric) {
            $value = $this->numericMetric($model, $metric);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values === [] ? null : array_sum($values) / count($values);
    }

    /**
     * @return array<string, array{input:float,weight:float,adjustment:float,max_adjustment:float}>
     */
    protected function advancedSpreadSignalAdjustments(
        float $ratingConsensusDiff,
        float $successRateDiff,
        float $explosivenessDiff,
        float $havocDiff,
        float $olQbEnvironmentDiff
    ): array {
        return [
            'rating_consensus' => $this->boundedSignalAdjustment(
                $ratingConsensusDiff,
                'rating_consensus_spread_weight',
                0.10,
                'rating_consensus_max_adjustment',
                2.5
            ),
            'success_rate' => $this->boundedSignalAdjustment(
                $successRateDiff,
                'success_rate_spread_weight',
                14.0,
                'success_rate_max_adjustment',
                2.0
            ),
            'explosiveness' => $this->boundedSignalAdjustment(
                $explosivenessDiff,
                'explosiveness_spread_weight',
                3.0,
                'explosiveness_max_adjustment',
                1.5
            ),
            'havoc' => $this->boundedSignalAdjustment(
                $havocDiff,
                'havoc_spread_weight',
                10.0,
                'havoc_max_adjustment',
                1.25
            ),
            'ol_qb_environment' => $this->boundedSignalAdjustment(
                $olQbEnvironmentDiff,
                'ol_qb_environment_spread_weight',
                1.25,
                'ol_qb_environment_max_adjustment',
                1.5
            ),
        ];
    }

    /**
     * @return array<string, array{input:float,weight:float,adjustment:float,max_adjustment:float}>
     */
    protected function advancedTotalSignalAdjustments(?Model $homeMetrics, ?Model $awayMetrics): array
    {
        $successInput = $this->centeredMetricSum($homeMetrics, $awayMetrics, 'offensive_success_rate', 0.42);
        $explosivenessInput = $this->centeredMetricSum($homeMetrics, $awayMetrics, 'offensive_explosiveness', 1.30);
        $havocInput = $this->centeredMetricSum($homeMetrics, $awayMetrics, 'defensive_havoc_rate', 0.17);

        $signals = [
            'offensive_success_rate' => $this->boundedSignalAdjustment(
                $successInput,
                'advanced_total_success_weight',
                18.0,
                'advanced_total_max_adjustment',
                3.0
            ),
            'offensive_explosiveness' => $this->boundedSignalAdjustment(
                $explosivenessInput,
                'advanced_total_explosiveness_weight',
                2.0,
                'advanced_total_max_adjustment',
                3.0
            ),
            'defensive_havoc_rate' => $this->boundedSignalAdjustment(
                -$havocInput,
                'advanced_total_havoc_weight',
                4.0,
                'advanced_total_max_adjustment',
                3.0
            ),
        ];

        return array_filter($signals, fn (array $signal): bool => $signal['input'] !== 0.0);
    }

    protected function centeredMetricSum(?Model $homeMetrics, ?Model $awayMetrics, string $metric, float $center): float
    {
        $homeValue = $this->numericMetric($homeMetrics, $metric);
        $awayValue = $this->numericMetric($awayMetrics, $metric);

        if ($homeValue === null || $awayValue === null) {
            return 0.0;
        }

        return ($homeValue - $center) + ($awayValue - $center);
    }

    /**
     * @return array{input:float,weight:float,adjustment:float,max_adjustment:float}
     */
    protected function boundedSignalAdjustment(
        float $input,
        string $weightKey,
        float $defaultWeight,
        string $maxKey,
        float $defaultMax
    ): array {
        $weight = $this->predictionWeight($weightKey, $defaultWeight);
        $maxAdjustment = (float) config("cfb.predictions.{$maxKey}", $defaultMax);
        $adjustment = $this->clamp($input * $weight, $maxAdjustment);

        return [
            'input' => round($input, 4),
            'weight' => round($weight, 4),
            'adjustment' => round($adjustment, 3),
            'max_adjustment' => round(abs($maxAdjustment), 3),
        ];
    }

    protected function baselineTotal(?Model $homeMetrics, ?Model $awayMetrics): float
    {
        $averageTotal = (float) config('cfb.predictions.average_total', 52);

        $homeScoring = (float) ($homeMetrics?->points_per_game ?? 0.0);
        $awayScoring = (float) ($awayMetrics?->points_per_game ?? 0.0);
        $homeAllowed = (float) ($homeMetrics?->points_allowed_per_game ?? 0.0);
        $awayAllowed = (float) ($awayMetrics?->points_allowed_per_game ?? 0.0);

        if ($homeScoring <= 0 || $awayScoring <= 0 || $homeAllowed <= 0 || $awayAllowed <= 0) {
            return $averageTotal;
        }

        $derivedTotal = (($homeScoring + $awayAllowed) / 2) + (($awayScoring + $homeAllowed) / 2);

        return ($averageTotal * 0.35) + ($derivedTotal * 0.65);
    }

    protected function predictionWeight(string $key, float $default): float
    {
        $value = config("cfb.predictions.{$key}");

        return is_numeric($value) ? (float) $value : $default;
    }

    protected function applyQualityMarginCalibration(
        float $spread,
        float $eloSpread,
        float $fpiDiff,
        float $wepaNetDiff,
        float $efficiencyDiff
    ): float {
        if (! (bool) config('cfb.predictions.margin_calibration.enabled', true)) {
            return $spread;
        }

        $absoluteSpread = abs($spread);
        $minSpread = (float) config('cfb.predictions.margin_calibration.min_abs_spread', 3.0);
        $maxSpread = (float) config('cfb.predictions.margin_calibration.max_abs_spread', 21.0);

        if ($absoluteSpread < $minSpread || $absoluteSpread >= $maxSpread) {
            return $spread;
        }

        $direction = $this->signalDirection($spread, 0.1);

        if ($direction === 0 || $this->signalDirection($eloSpread, $minSpread) !== $direction) {
            return $spread;
        }

        $agreement = $this->qualitySignalAgreement($direction, $fpiDiff, $wepaNetDiff, $efficiencyDiff);

        if ($agreement['opposing'] > 0) {
            return $spread;
        }

        $minSignals = (int) config('cfb.predictions.margin_calibration.min_non_elo_signals', 2);
        if ($agreement['aligned'] < $minSignals) {
            return $spread;
        }

        $factor = $this->marginCalibrationFactor($absoluteSpread);
        $targetAbsoluteSpread = $absoluteSpread * $factor;
        $maxBonus = (float) config('cfb.predictions.margin_calibration.max_bonus_points', 6.0);
        $bonus = min($maxBonus, max(0.0, $targetAbsoluteSpread - $absoluteSpread));

        return $spread + ($direction * $bonus);
    }

    /**
     * @return array{aligned:int,opposing:int}
     */
    protected function qualitySignalAgreement(
        int $direction,
        float $fpiDiff,
        float $wepaNetDiff,
        float $efficiencyDiff
    ): array {
        $signals = [
            $this->signalDirection(
                $fpiDiff,
                (float) config('cfb.predictions.margin_calibration.fpi_threshold', 2.0)
            ),
            $this->signalDirection(
                $wepaNetDiff,
                (float) config('cfb.predictions.margin_calibration.wepa_net_threshold', 0.35)
            ),
            $this->signalDirection(
                $efficiencyDiff,
                (float) config('cfb.predictions.margin_calibration.net_rating_threshold', 3.0)
            ),
        ];

        return [
            'aligned' => collect($signals)->filter(fn (int $signal): bool => $signal === $direction)->count(),
            'opposing' => collect($signals)->filter(fn (int $signal): bool => $signal === -$direction)->count(),
        ];
    }

    protected function marginCalibrationFactor(float $absoluteSpread): float
    {
        $lowMax = (float) config('cfb.predictions.margin_calibration.low_band_max', 7.0);
        $midMax = (float) config('cfb.predictions.margin_calibration.mid_band_max', 14.0);

        if ($absoluteSpread < $lowMax) {
            return (float) config('cfb.predictions.margin_calibration.low_band_factor', 1.80);
        }

        if ($absoluteSpread < $midMax) {
            return (float) config('cfb.predictions.margin_calibration.mid_band_factor', 1.45);
        }

        return (float) config('cfb.predictions.margin_calibration.upper_band_factor', 1.20);
    }

    protected function signalDirection(float $value, float $threshold): int
    {
        if ($value >= $threshold) {
            return 1;
        }

        if ($value <= -$threshold) {
            return -1;
        }

        return 0;
    }

    /**
     * @return array{0:int,1:int}
     */
    protected function eloRatingsForGame(Model $game, Model $homeTeam, Model $awayTeam, int $defaultElo): array
    {
        if (! (bool) config('cfb.predictions.use_previous_season_elo_fallback', true)) {
            return parent::eloRatingsForGame($game, $homeTeam, $awayTeam, $defaultElo);
        }

        $homeElo = $this->pointInTimeEloForGame((int) $homeTeam->id, $game, $defaultElo)
            ?? (int) round((float) ($homeTeam->elo_rating ?? $defaultElo));
        $awayElo = $this->pointInTimeEloForGame((int) $awayTeam->id, $game, $defaultElo)
            ?? (int) round((float) ($awayTeam->elo_rating ?? $defaultElo));

        return [$homeElo, $awayElo];
    }

    protected function pointInTimeEloForGame(int $teamId, Model $game, int $defaultElo): ?int
    {
        $sameSeasonElo = $this->latestSameSeasonEloBeforeGame($teamId, $game);

        if ($sameSeasonElo !== null) {
            return (int) round($sameSeasonElo);
        }

        if (! $this->shouldUsePriorSeasonEloFallback($game)) {
            return null;
        }

        $priorSeasonElo = $this->latestPriorSeasonElo($teamId, (int) $game->season);

        if ($priorSeasonElo === null) {
            return null;
        }

        $regressionFactor = (float) config(
            'cfb.predictions.previous_season_elo_regression_factor',
            config('cfb.elo.offseason_regression_factor', 0.30)
        );
        $regressionFactor = max(0.0, min(1.0, $regressionFactor));

        return (int) round($priorSeasonElo + (($defaultElo - $priorSeasonElo) * $regressionFactor));
    }

    protected function latestSameSeasonEloBeforeGame(int $teamId, Model $game): ?float
    {
        $query = EloRating::query()
            ->where('team_id', $teamId)
            ->where('season', (int) $game->season);

        if (Schema::hasColumn((new EloRating)->getTable(), 'date') && $game->game_date) {
            $gameDate = Carbon::parse($game->game_date)->toDateString();

            return $query
                ->whereDate('date', '<', $gameDate)
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->value('elo_rating');
        }

        return $query
            ->where('week', '<', (int) ($game->week ?? 0))
            ->orderByDesc('week')
            ->orderByDesc('id')
            ->value('elo_rating');
    }

    protected function latestPriorSeasonElo(int $teamId, int $season): ?float
    {
        $query = EloRating::query()
            ->where('team_id', $teamId)
            ->where('season', '<', $season)
            ->orderByDesc('season');

        if (Schema::hasColumn((new EloRating)->getTable(), 'date')) {
            $query->orderByDesc('date');
        }

        return $query
            ->orderByDesc('week')
            ->orderByDesc('id')
            ->value('elo_rating');
    }

    protected function shouldUsePriorSeasonEloFallback(Model $game): bool
    {
        $throughWeek = (int) config('cfb.predictions.previous_season_elo_fallback_through_week', 4);

        if ($throughWeek < 0) {
            return true;
        }

        return (int) ($game->week ?? 0) <= $throughWeek;
    }

    protected function latestPriorSeasonMetric(string $teamMetricModel, int $teamId, int $season, ?Model $game = null): ?Model
    {
        $team = Team::query()->find($teamId);
        if (! $team || ! $this->seasonAffiliationResolver->isFbs($team, $season)) {
            return null;
        }

        return $teamMetricModel::query()
            ->where('team_id', $teamId)
            ->where('season', '<', $season)
            ->orderByDesc('season')
            ->get()
            ->first(function (Model $metric) use ($team) {
                return $this->seasonAffiliationResolver->isFbs($team, (int) $metric->season);
            });
    }
}
