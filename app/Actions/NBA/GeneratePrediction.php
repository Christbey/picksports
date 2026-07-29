<?php

namespace App\Actions\NBA;

use App\Actions\Sports\AbstractPredictionGenerator;
use App\Actions\Sports\Concerns\AppliesTrueEpaPredictionBlend;
use App\Jobs\NBA\GeneratePredictionNarrative as GeneratePredictionNarrativeJob;
use App\Models\NBA\Game;
use App\Models\NBA\PlayerInjury;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;
use App\Models\NBA\TeamStat;
use App\Services\NBA\WinProbabilityCalibrationInferenceService;
use App\Support\Odds\MarketSpread;
use Illuminate\Database\Eloquent\Model;

class GeneratePrediction extends AbstractPredictionGenerator
{
    use AppliesTrueEpaPredictionBlend;

    /** @var array<string, mixed> Cached metadata for the current prediction */
    private array $metadata = [];

    /** @var array<string, mixed> True EPA rollout metadata */
    private array $trueEpaMetadata = [];

    /** @var array<string, mixed> Total model rollout metadata */
    private array $totalMetadata = [];

    /** @var array<string, mixed> Win probability calibration metadata */
    private array $winProbabilityCalibrationMetadata = [];

    protected const SPORT_KEY = 'nba';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;

    /**
     * @return array<string, mixed>|null
     */
    public function preview(Game $game): ?array
    {
        return $this->makePredictionData($game);
    }

    public function execute(Model $game, bool $dispatchNarratives = true): ?Model
    {
        $prediction = parent::execute($game, $dispatchNarratives);

        if ($prediction instanceof Prediction && $dispatchNarratives) {
            GeneratePredictionNarrativeJob::dispatch($prediction->id);
        }

        return $prediction;
    }

    protected function calculatePredictedSpread(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        $this->metadata = [];
        $this->trueEpaMetadata = [];
        $this->totalMetadata = [];
        $this->winProbabilityCalibrationMetadata = [];

        $config = config('nba.prediction');
        $oddsMarketAvailability = $this->oddsMarketAvailability($game);
        $homeCourtAdvantage = config('nba.elo.home_court_advantage');

        // 1. ELO spread component
        $eloSpread = ($homeElo + $homeCourtAdvantage - $awayElo) / $config['elo_to_spread_divisor'];

        // 2. Efficiency spread component
        $homeNetRating = $homeMetrics?->net_rating ?? 0;
        $awayNetRating = $awayMetrics?->net_rating ?? 0;
        $efficiencySpread = ($homeNetRating - $awayNetRating) / 2 + $config['home_court_points'];

        // Apply home/away split adjustment
        $homeAwaySplitAdj = $this->calculateHomeAwaySplitAdjustment($game, $homeMetrics, $awayMetrics);
        $efficiencySpread += $homeAwaySplitAdj * $config['home_away_split_weight'];

        // 3. Form spread component
        $homeForm = $this->getRecentForm($game->homeTeam, $game->season);
        $awayForm = $this->getRecentForm($game->awayTeam, $game->season);
        $homeFormNet = $homeForm['net_rating'];
        $awayFormNet = $awayForm['net_rating'];
        $formSpread = ($homeFormNet - $awayFormNet) / 2 + $config['home_court_points'];

        // 4. Situational adjustments
        $restHome = $this->getRestDays($game->homeTeam, $game);
        $restAway = $this->getRestDays($game->awayTeam, $game);
        $restAdj = $this->calculateRestAdjustment($restHome, $restAway, $config);

        $turnoverAdj = $this->calculateTurnoverAdjustment($game, $config);
        $reboundAdj = $this->calculateReboundAdjustment($game, $config);

        $situationalAdj = $restAdj + $turnoverAdj + $reboundAdj;
        $injuryContext = $this->buildInjuryContext($game);
        $usePersistedSpreadInjuryContext = $this->hasPersistedInjuryAdjustedRating($homeMetrics, $awayMetrics);
        $usePersistedTotalInjuryContext = $this->hasPersistedInjuryAdjustedTotal($homeMetrics, $awayMetrics);
        $injurySpreadAdj = $usePersistedSpreadInjuryContext ? 0.0 : $injuryContext['spread_adj'];
        $injuryTotalAdj = $usePersistedTotalInjuryContext
            ? $this->persistedInjuryTotalAdjustment($homeMetrics, $awayMetrics)
            : $injuryContext['total_adj'];

        // 5. Ensemble blend
        $modelSpread = ($config['elo_weight'] * $eloSpread)
            + ($config['efficiency_weight'] * $efficiencySpread)
            + ($config['form_weight'] * $formSpread)
            + $situationalAdj
            + $injurySpreadAdj;

        // 6. Vegas blend (if available)
        $vegasSpread = $this->getVegasSpread($game);

        if ($vegasSpread !== null) {
            $finalSpread = ($config['model_weight_with_vegas'] * $modelSpread)
                + ($config['vegas_weight'] * $vegasSpread);
        } else {
            $finalSpread = $modelSpread;
        }
        [$finalSpread, $trueEpaSpreadMeta] = $this->applyTrueEpaSpreadBlend(
            (float) $finalSpread,
            $homeMetrics,
            $awayMetrics
        );

        // Cache metadata for buildPredictionData
        $this->metadata = [
            'home_recent_form' => $homeFormNet,
            'away_recent_form' => $awayFormNet,
            'rest_days_home' => $restHome,
            'rest_days_away' => $restAway,
            'home_away_split_adj' => round($homeAwaySplitAdj, 2),
            'turnover_diff_adj' => round($turnoverAdj, 2),
            'rebound_margin_adj' => round($reboundAdj, 2),
            'vegas_spread' => $vegasSpread !== null ? round($vegasSpread, 2) : null,
            'elo_spread_component' => round($eloSpread, 2),
            'efficiency_spread_component' => round($efficiencySpread, 2),
            'form_spread_component' => round($formSpread, 2),
            'home_injuries_out' => $injuryContext['home_out'],
            'away_injuries_out' => $injuryContext['away_out'],
            'home_injuries_questionable' => $injuryContext['home_questionable'],
            'away_injuries_questionable' => $injuryContext['away_questionable'],
            'home_injuries_out_weighted' => $injuryContext['home_out_weighted'],
            'away_injuries_out_weighted' => $injuryContext['away_out_weighted'],
            'home_injuries_questionable_weighted' => $injuryContext['home_questionable_weighted'],
            'away_injuries_questionable_weighted' => $injuryContext['away_questionable_weighted'],
            'injury_spread_adj' => round($injurySpreadAdj, 2),
            'injury_total_adj' => round($injuryTotalAdj, 2),
            'injury_model_source' => $usePersistedSpreadInjuryContext === $usePersistedTotalInjuryContext
                ? ($usePersistedSpreadInjuryContext ? 'persisted_team_rating' : 'raw_player_status')
                : 'mixed',
            'injury_spread_model_source' => $usePersistedSpreadInjuryContext ? 'persisted_team_rating' : 'raw_player_status',
            'injury_total_model_source' => $usePersistedTotalInjuryContext ? 'persisted_team_rating' : 'raw_player_status',
            'baseline_model_spread' => round($modelSpread, 2),
            'odds_market_availability' => $oddsMarketAvailability,
        ];
        $this->trueEpaMetadata = [...$this->trueEpaMetadata, ...$trueEpaSpreadMeta];

        return round($finalSpread, 1);
    }

    protected function calculatePredictedTotal(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        $config = config('nba.prediction');
        $defaultEfficiency = $config['default_efficiency'];
        $recentWeight = (float) ($config['total_recent_efficiency_weight'] ?? 0.35);
        $venueWeight = (float) ($config['total_venue_efficiency_weight'] ?? 0.15);
        $averagePace = (float) ($config['average_pace'] ?? 100.0);
        $seasonTempoRegressionWeight = (float) ($config['total_season_tempo_regression_weight'] ?? 0.0);
        $recentTempoRegressionWeight = (float) ($config['total_recent_tempo_regression_weight'] ?? 0.0);

        $homeOffEff = $homeMetrics?->offensive_efficiency ?? $defaultEfficiency;
        $homeDefEff = $homeMetrics?->defensive_efficiency ?? $defaultEfficiency;
        $awayOffEff = $awayMetrics?->offensive_efficiency ?? $defaultEfficiency;
        $awayDefEff = $awayMetrics?->defensive_efficiency ?? $defaultEfficiency;

        $homeForm = $this->getRecentForm($game->homeTeam, $game->season);
        $awayForm = $this->getRecentForm($game->awayTeam, $game->season);

        $homeSeasonScore = ($homeOffEff + $awayDefEff) / 2;
        $awaySeasonScore = ($awayOffEff + $homeDefEff) / 2;
        $rawHomeRecentScore = ($homeForm['off_eff'] + $awayForm['def_eff']) / 2;
        $rawAwayRecentScore = ($awayForm['off_eff'] + $homeForm['def_eff']) / 2;
        $homeRecentScore = $this->sanitizeRecentScoreComponent($rawHomeRecentScore, $homeSeasonScore, $defaultEfficiency);
        $awayRecentScore = $this->sanitizeRecentScoreComponent($rawAwayRecentScore, $awaySeasonScore, $defaultEfficiency);
        $homeVenueScore = $this->pairedVenueScore(
            $this->getVenueEfficiency($game->homeTeam, (int) $game->season, 'home'),
            $this->getVenueEfficiency($game->awayTeam, (int) $game->season, 'away')
        );
        $awayVenueScore = $this->pairedVenueScore(
            $this->getVenueEfficiency($game->awayTeam, (int) $game->season, 'away'),
            $this->getVenueEfficiency($game->homeTeam, (int) $game->season, 'home')
        );

        $homePredictedScore = $this->blendWeightedValues([
            ['value' => $homeSeasonScore, 'weight' => 1.0],
            ['value' => $homeRecentScore, 'weight' => $recentWeight],
            ['value' => $homeVenueScore, 'weight' => $homeVenueScore !== null ? $venueWeight : 0.0],
        ]);
        $awayPredictedScore = $this->blendWeightedValues([
            ['value' => $awaySeasonScore, 'weight' => 1.0],
            ['value' => $awayRecentScore, 'weight' => $recentWeight],
            ['value' => $awayVenueScore, 'weight' => $awayVenueScore !== null ? $venueWeight : 0.0],
        ]);

        // Blend season tempo with recent form tempo
        $seasonPaceRaw = (($homeMetrics?->tempo ?? $averagePace)
            + ($awayMetrics?->tempo ?? $averagePace)) / 2;
        $seasonPace = $this->regressTotalPace($seasonPaceRaw, $averagePace, $seasonTempoRegressionWeight);

        $formPaceRaw = ($homeForm['tempo'] + $awayForm['tempo']) / 2;
        $formPace = $this->regressTotalPace($formPaceRaw, $averagePace, $recentTempoRegressionWeight);
        $calibration = (array) ($config['total_calibration'] ?? []);
        $maxRecentPaceDrop = (float) ($calibration['max_recent_pace_drop'] ?? 7.0);
        $recentPaceFloor = max(0.0, $seasonPace - $maxRecentPaceDrop);
        $formPace = max($formPace, $recentPaceFloor);
        $pace = ($seasonPace + $formPace) / 2;
        $paceFloor = (float) ($calibration['pace_floor'] ?? 95.0);
        $paceFloorBlend = (float) ($calibration['pace_floor_blend'] ?? 0.55);
        if ($pace < $paceFloor) {
            $pace += ($paceFloor - $pace) * $paceFloorBlend;
        }

        // B2B teams tend to play slower
        $restHome = $this->metadata['rest_days_home'] ?? null;
        $restAway = $this->metadata['rest_days_away'] ?? null;
        $paceAdj = 0.0;
        if ($restHome !== null && $restHome <= 1) {
            $paceAdj -= 1.0;
        }
        if ($restAway !== null && $restAway <= 1) {
            $paceAdj -= 1.0;
        }
        $pace += $paceAdj;
        $injuryTotalAdj = (float) ($this->metadata['injury_total_adj'] ?? 0.0);
        $legacyTotal = (($homePredictedScore + $awayPredictedScore) * ($pace / 100)) + $injuryTotalAdj;
        [$blendedTotal, $trueEpaTotalMeta] = $this->applyTrueEpaTotalBlend(
            (float) $legacyTotal,
            $homeMetrics,
            $awayMetrics
        );
        $rangeAnchor = (float) ($calibration['range_anchor'] ?? 228.0);
        $rangeScale = (float) ($calibration['range_scale'] ?? 1.18);
        $baseAdjustment = (float) ($calibration['base_adjustment'] ?? 3.0);
        $highTotalThreshold = (float) ($calibration['high_total_threshold'] ?? 229.0);
        $highTotalSlope = (float) ($calibration['high_total_slope'] ?? 0.35);
        $highTotalBoost = max(0.0, $blendedTotal - $highTotalThreshold) * $highTotalSlope;
        $calibratedTotal = $rangeAnchor + (($blendedTotal - $rangeAnchor) * $rangeScale) + $baseAdjustment + $highTotalBoost;
        $vegasTotal = $this->extractMarketTotal($game);
        $this->metadata['market_total'] = $vegasTotal !== null ? round($vegasTotal, 2) : null;
        $highMarketTotalThreshold = (float) ($calibration['high_market_total_threshold'] ?? 235.0);
        $highMarketTotalBlendWeight = (float) ($calibration['high_market_total_blend_weight'] ?? 0.55);
        $marketTotalBlendApplied = false;
        if ($vegasTotal !== null && $vegasTotal >= $highMarketTotalThreshold) {
            $calibratedTotal = ($calibratedTotal * (1 - $highMarketTotalBlendWeight)) + ($vegasTotal * $highMarketTotalBlendWeight);
            $marketTotalBlendApplied = true;
        }

        $this->trueEpaMetadata = [...$this->trueEpaMetadata, ...$trueEpaTotalMeta];
        $this->totalMetadata = [
            'season_home_score_component' => round($homeSeasonScore, 3),
            'season_away_score_component' => round($awaySeasonScore, 3),
            'recent_home_score_component_raw' => round($rawHomeRecentScore, 3),
            'recent_away_score_component_raw' => round($rawAwayRecentScore, 3),
            'recent_home_score_component' => round($homeRecentScore, 3),
            'recent_away_score_component' => round($awayRecentScore, 3),
            'recent_home_score_fallback_applied' => $homeRecentScore !== $rawHomeRecentScore,
            'recent_away_score_fallback_applied' => $awayRecentScore !== $rawAwayRecentScore,
            'venue_home_score_component' => $homeVenueScore !== null ? round($homeVenueScore, 3) : null,
            'venue_away_score_component' => $awayVenueScore !== null ? round($awayVenueScore, 3) : null,
            'season_pace_raw' => round($seasonPaceRaw, 3),
            'season_pace' => round($seasonPace, 3),
            'recent_pace_raw' => round($formPaceRaw, 3),
            'recent_pace' => round($formPace, 3),
            'season_tempo_regression_weight' => round($seasonTempoRegressionWeight, 3),
            'recent_tempo_regression_weight' => round($recentTempoRegressionWeight, 3),
            'recent_pace_floor' => round($recentPaceFloor, 3),
            'max_recent_pace_drop' => round($maxRecentPaceDrop, 3),
            'pace_adjustment' => round($paceAdj, 3),
            'pace_floor' => round($paceFloor, 3),
            'pace_floor_blend' => round($paceFloorBlend, 3),
            'blended_pace' => round($pace, 3),
            'injury_total_adjustment' => round($injuryTotalAdj, 3),
            'legacy_total' => round($legacyTotal, 3),
            'post_epa_total' => round($blendedTotal, 3),
            'range_anchor' => round($rangeAnchor, 3),
            'range_scale' => round($rangeScale, 3),
            'total_base_adjustment' => round($baseAdjustment, 3),
            'high_total_boost' => round($highTotalBoost, 3),
            'vegas_total' => $vegasTotal !== null ? round($vegasTotal, 3) : null,
            'high_market_total_threshold' => round($highMarketTotalThreshold, 3),
            'high_market_total_blend_weight' => round($highMarketTotalBlendWeight, 3),
            'market_total_blend_applied' => $marketTotalBlendApplied,
            'calibrated_total' => round($calibratedTotal, 3),
        ];

        return round($calibratedTotal, 1);
    }

    private function sanitizeRecentScoreComponent(float $recentScore, float $seasonScore, float $defaultEfficiency): float
    {
        $minReasonableScore = max(80.0, $defaultEfficiency * 0.75);
        $maxReasonableScore = min(145.0, $defaultEfficiency * 1.35);

        if ($recentScore < $minReasonableScore || $recentScore > $maxReasonableScore) {
            return $seasonScore;
        }

        return $recentScore;
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
        $defaultEfficiency = config('nba.prediction.default_efficiency');
        $calibration = app(WinProbabilityCalibrationInferenceService::class)
            ->calibrate($this->getSport(), $winProbability);
        $this->winProbabilityCalibrationMetadata = $calibration;

        $activeWinProbability = round((float) ($calibration['active_win_probability'] ?? $winProbability), 3);
        $baselineWinProbability = round((float) ($calibration['baseline_win_probability'] ?? $winProbability), 3);
        $calibratedWinProbability = round((float) ($calibration['calibrated_win_probability'] ?? $winProbability), 3);
        $activeConfidenceScore = $this->calculateConfidence($activeWinProbability);

        return array_merge(
            parent::buildPredictionData(
                $homeElo,
                $awayElo,
                $homeMetrics,
                $awayMetrics,
                $predictedSpread,
                $predictedTotal,
                $activeWinProbability,
                $activeConfidenceScore
            ),
            $this->efficiencyPredictionData($homeMetrics, $awayMetrics, $defaultEfficiency),
            $this->metadata,
            [
                'model_version' => $this->modelVersion(),
                'feature_version' => $this->featureVersion(),
                'blend_version' => $this->blendVersion(),
                'model_metadata' => [
                    'model' => 'nba_ensemble',
                    'true_epa' => $this->trueEpaMetadata,
                    'total_model' => $this->totalMetadata,
                    'injury_model_source' => $this->metadata['injury_model_source'] ?? null,
                    'injury_spread_model_source' => $this->metadata['injury_spread_model_source'] ?? null,
                    'injury_total_model_source' => $this->metadata['injury_total_model_source'] ?? null,
                    'depth_chart_injuries' => [
                        'applied' => ((float) ($this->metadata['injury_spread_adj'] ?? 0.0)) !== 0.0
                            || ((float) ($this->metadata['injury_total_adj'] ?? 0.0)) !== 0.0,
                        'home_out_weighted' => $this->metadata['home_injuries_out_weighted'] ?? 0.0,
                        'away_out_weighted' => $this->metadata['away_injuries_out_weighted'] ?? 0.0,
                        'home_questionable_weighted' => $this->metadata['home_injuries_questionable_weighted'] ?? 0.0,
                        'away_questionable_weighted' => $this->metadata['away_injuries_questionable_weighted'] ?? 0.0,
                        'spread_adjustment' => $this->metadata['injury_spread_adj'] ?? 0.0,
                        'total_adjustment' => $this->metadata['injury_total_adj'] ?? 0.0,
                    ],
                    'win_probability_calibration' => $calibration,
                    'market_context' => [
                        'vegas_spread' => $this->metadata['vegas_spread'] ?? null,
                        'market_total' => $this->metadata['market_total'] ?? null,
                        ...($this->metadata['odds_market_availability'] ?? []),
                    ],
                ],
                '_snapshot' => [
                    'model_version' => $this->modelVersion(),
                    'feature_version' => $this->featureVersion(),
                    'blend_version' => $this->blendVersion(),
                    'run_type' => 'pregame_prediction',
                    'features' => [
                        'home_elo' => $homeElo,
                        'away_elo' => $awayElo,
                        'home_off_eff' => $homeMetrics?->offensive_efficiency,
                        'home_def_eff' => $homeMetrics?->defensive_efficiency,
                        'away_off_eff' => $awayMetrics?->offensive_efficiency,
                        'away_def_eff' => $awayMetrics?->defensive_efficiency,
                        ...$this->metadata,
                    ],
                    'outputs' => [
                        'baseline_predicted_spread' => round($this->metadata['baseline_model_spread'] ?? $predictedSpread, 3),
                        'baseline_predicted_total' => round($this->totalMetadata['legacy_total'] ?? $predictedTotal, 3),
                        'bookmaker_home_spread' => $this->metadata['vegas_spread'] ?? null,
                        'market_spread' => is_numeric($this->metadata['vegas_spread'] ?? null)
                            ? MarketSpread::bookmakerHomeLineToHomeMargin((float) $this->metadata['vegas_spread'])
                            : null,
                        'market_total' => $this->metadata['market_total'] ?? null,
                        'blended_predicted_spread' => $predictedSpread,
                        'blended_predicted_total' => $predictedTotal,
                        'predicted_spread' => $predictedSpread,
                        'predicted_total' => $predictedTotal,
                        'baseline_win_probability' => $baselineWinProbability,
                        'calibrated_win_probability' => $calibratedWinProbability,
                        'win_probability' => $activeWinProbability,
                        'confidence_score' => $activeConfidenceScore,
                        'active_win_probability_source' => $calibration['active_source'] ?? 'baseline',
                    ],
                    'market_context' => [
                        'bookmaker_home_line' => $this->metadata['vegas_spread'] ?? null,
                        'market_home_margin' => is_numeric($this->metadata['vegas_spread'] ?? null)
                            ? MarketSpread::bookmakerHomeLineToHomeMargin((float) $this->metadata['vegas_spread'])
                            : null,
                        'bookmaker_spread_convention' => MarketSpread::BOOKMAKER_HOME_LINE_CONVENTION,
                        'spread_convention' => MarketSpread::HOME_MARGIN_CONVENTION,
                        'market_total' => $this->metadata['market_total'] ?? null,
                        ...($this->metadata['odds_market_availability'] ?? []),
                    ],
                    'source_timestamps' => array_filter([
                        'home_metrics_calculated_at' => $homeMetrics?->calculation_date?->toDateString(),
                        'away_metrics_calculated_at' => $awayMetrics?->calculation_date?->toDateString(),
                    ]),
                    'model_metadata' => [
                        'model' => 'nba_ensemble',
                        'true_epa' => $this->trueEpaMetadata,
                        'total_model' => $this->totalMetadata,
                        'injury_model_source' => $this->metadata['injury_model_source'] ?? null,
                        'injury_spread_model_source' => $this->metadata['injury_spread_model_source'] ?? null,
                        'injury_total_model_source' => $this->metadata['injury_total_model_source'] ?? null,
                        'depth_chart_injuries' => [
                            'applied' => ((float) ($this->metadata['injury_spread_adj'] ?? 0.0)) !== 0.0
                                || ((float) ($this->metadata['injury_total_adj'] ?? 0.0)) !== 0.0,
                            'home_out_weighted' => $this->metadata['home_injuries_out_weighted'] ?? 0.0,
                            'away_out_weighted' => $this->metadata['away_injuries_out_weighted'] ?? 0.0,
                            'home_questionable_weighted' => $this->metadata['home_injuries_questionable_weighted'] ?? 0.0,
                            'away_questionable_weighted' => $this->metadata['away_injuries_questionable_weighted'] ?? 0.0,
                            'spread_adjustment' => $this->metadata['injury_spread_adj'] ?? 0.0,
                            'total_adjustment' => $this->metadata['injury_total_adj'] ?? 0.0,
                        ],
                        'win_probability_calibration' => $calibration,
                        'market_context' => [
                            'vegas_spread' => $this->metadata['vegas_spread'] ?? null,
                            'market_total' => $this->metadata['market_total'] ?? null,
                            ...($this->metadata['odds_market_availability'] ?? []),
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * @param  array{off: float, def: float, net: float}  $offenseVenue
     * @param  array{off: float, def: float, net: float}  $defenseVenue
     */
    private function pairedVenueScore(array $offenseVenue, array $defenseVenue): ?float
    {
        return ((float) ($offenseVenue['off'] ?? 0.0) + (float) ($defenseVenue['def'] ?? 0.0)) / 2;
    }

    /**
     * @param  array<int, array{value: float|null, weight: float}>  $components
     */
    private function blendWeightedValues(array $components): float
    {
        $weightedTotal = 0.0;
        $totalWeight = 0.0;

        foreach ($components as $component) {
            $value = $component['value'];
            $weight = (float) ($component['weight'] ?? 0.0);

            if ($value === null || $weight <= 0) {
                continue;
            }

            $weightedTotal += (float) $value * $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0) {
            return 0.0;
        }

        return $weightedTotal / $totalWeight;
    }

    /**
     * Calculate recent form as weighted efficiency over last N games.
     *
     * @return array{off_eff: float, def_eff: float, net_rating: float, tempo: float}
     */
    private function getRecentForm(Team $team, int $season): array
    {
        $config = config('nba.prediction');
        $numGames = $config['recent_form_games'];
        $decay = $config['recency_decay'];
        $defaultEff = $config['default_efficiency'];
        $defaultPace = $config['average_pace'];

        $recentGames = Game::query()
            ->whereIn('status', ['STATUS_FINAL', 'STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->where('season', $season)
            ->where(function ($q) use ($team) {
                $q->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->orderByDesc('game_date')
            ->limit($numGames)
            ->pluck('id')
            ->toArray();

        if (empty($recentGames)) {
            return $this->defaultRecentForm($defaultEff, $defaultPace);
        }

        $stats = TeamStat::query()
            ->where('team_id', $team->id)
            ->whereIn('game_id', $recentGames)
            ->join('nba_games', 'nba_team_stats.game_id', '=', 'nba_games.id')
            ->orderByDesc('nba_games.game_date')
            ->select('nba_team_stats.*')
            ->get();

        if ($stats->isEmpty()) {
            return $this->defaultRecentForm($defaultEff, $defaultPace);
        }

        $totalWeight = 0;
        $weightedOffEff = 0;
        $weightedDefEff = 0;
        $weightedTempo = 0;

        foreach ($stats as $index => $stat) {
            $weight = pow($decay, $index);
            $possessions = $stat->possessions > 0 ? $stat->possessions : $defaultPace;
            $offEff = ($stat->points / $possessions) * 100;

            // Get opponent stats for defensive efficiency
            $opponentStat = TeamStat::query()
                ->where('game_id', $stat->game_id)
                ->where('team_id', '!=', $team->id)
                ->first();

            $defEff = $opponentStat
                ? ($opponentStat->points / ($opponentStat->possessions > 0 ? $opponentStat->possessions : $defaultPace)) * 100
                : $defaultEff;

            $weightedOffEff += $offEff * $weight;
            $weightedDefEff += $defEff * $weight;
            $weightedTempo += $possessions * $weight;
            $totalWeight += $weight;
        }

        $offEff = $weightedOffEff / $totalWeight;
        $defEff = $weightedDefEff / $totalWeight;

        return [
            'off_eff' => round($offEff, 1),
            'def_eff' => round($defEff, 1),
            'net_rating' => round($offEff - $defEff, 3),
            'tempo' => round($weightedTempo / $totalWeight, 1),
        ];
    }

    /**
     * Get rest days since team's last completed game before this game.
     */
    private function getRestDays(Team $team, Model $game): ?int
    {
        $lastGame = Game::query()
            ->whereIn('status', ['STATUS_FINAL', 'STATUS_SCHEDULED', 'STATUS_DELAYED'])
            ->where(function ($q) use ($team) {
                $q->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->where('game_date', '<', $game->game_date)
            ->orderByDesc('game_date')
            ->first();

        if (! $lastGame) {
            return null;
        }

        return (int) $lastGame->game_date->diffInDays($game->game_date);
    }

    /**
     * Calculate home/away split efficiency adjustment.
     */
    private function calculateHomeAwaySplitAdjustment(Model $game, ?Model $homeMetrics, ?Model $awayMetrics): float
    {
        $homeStats = $this->getVenueEfficiency($game->homeTeam, $game->season, 'home');
        $awayStats = $this->getVenueEfficiency($game->awayTeam, $game->season, 'away');

        $homeSeasonNet = $homeMetrics?->net_rating ?? 0;
        $awaySeasonNet = $awayMetrics?->net_rating ?? 0;

        // How much better/worse each team performs at their respective venue vs season avg
        $homeVenueAdj = ($homeStats['net'] ?? 0) - $homeSeasonNet;
        $awayVenueAdj = ($awayStats['net'] ?? 0) - $awaySeasonNet;

        return $homeVenueAdj - $awayVenueAdj;
    }

    /**
     * Get offensive/defensive efficiency for home-only or away-only games.
     *
     * @return array{off: float, def: float, net: float}
     */
    private function getVenueEfficiency(Team $team, int $season, string $venue): array
    {
        $defaultEff = config('nba.prediction.default_efficiency');
        $games = $this->completedSeasonGameIdsForTeam($team, $season, $venue);

        if (empty($games)) {
            return ['off' => $defaultEff, 'def' => $defaultEff, 'net' => 0];
        }

        $stats = TeamStat::query()
            ->where('team_id', $team->id)
            ->whereIn('game_id', $games)
            ->get();

        if ($stats->isEmpty()) {
            return ['off' => $defaultEff, 'def' => $defaultEff, 'net' => 0];
        }

        $totalPoints = $stats->sum('points');
        $totalPoss = $stats->sum('possessions');

        $offEff = $totalPoss > 0 ? ($totalPoints / $totalPoss) * 100 : $defaultEff;

        // Opponent stats for defensive efficiency
        $opponentPoints = TeamStat::query()
            ->where('team_id', '!=', $team->id)
            ->whereIn('game_id', $games)
            ->sum('points');
        $opponentPoss = TeamStat::query()
            ->where('team_id', '!=', $team->id)
            ->whereIn('game_id', $games)
            ->sum('possessions');

        $defEff = $opponentPoss > 0 ? ($opponentPoints / $opponentPoss) * 100 : $defaultEff;

        return [
            'off' => round($offEff, 1),
            'def' => round($defEff, 1),
            'net' => round($offEff - $defEff, 1),
        ];
    }

    /**
     * Calculate rest day adjustment for the spread.
     */
    private function calculateRestAdjustment(?int $restHome, ?int $restAway, array $config): float
    {
        $adj = 0;

        // Back-to-back penalty
        if ($restHome !== null && $restHome <= 1) {
            $adj += $config['back_to_back_penalty'];
        }
        if ($restAway !== null && $restAway <= 1) {
            $adj -= $config['back_to_back_penalty'];
        }

        // Rest day advantage (capped at ±3 days difference)
        if ($restHome !== null && $restAway !== null) {
            $restDiff = min(3, max(-3, $restHome - $restAway));
            $adj += $restDiff * $config['rest_day_adjustment'];
        }

        return $adj;
    }

    /**
     * Calculate turnover differential adjustment.
     */
    private function calculateTurnoverAdjustment(Model $game, array $config): float
    {
        $homeDiff = $this->getTurnoverDifferential($game->homeTeam, $game->season);
        $awayDiff = $this->getTurnoverDifferential($game->awayTeam, $game->season);

        return ($homeDiff - $awayDiff) * $config['turnover_diff_weight'];
    }

    /**
     * Get turnover differential: forced - committed (positive = good).
     */
    private function getTurnoverDifferential(Team $team, int $season): float
    {
        $games = $this->completedSeasonGameIdsForTeam($team, $season);

        if (empty($games)) {
            return 0;
        }

        $avgCommitted = TeamStat::query()
            ->where('team_id', $team->id)
            ->whereIn('game_id', $games)
            ->avg('turnovers') ?? 0;

        $avgForced = TeamStat::query()
            ->where('team_id', '!=', $team->id)
            ->whereIn('game_id', $games)
            ->avg('turnovers') ?? 0;

        return round($avgForced - $avgCommitted, 2);
    }

    /**
     * Calculate rebound margin adjustment.
     */
    private function calculateReboundAdjustment(Model $game, array $config): float
    {
        $homeMargin = $this->getReboundMargin($game->homeTeam, $game->season);
        $awayMargin = $this->getReboundMargin($game->awayTeam, $game->season);

        return ($homeMargin - $awayMargin) * $config['rebound_margin_weight'];
    }

    /**
     * Get average rebound margin (team rebounds - opponent rebounds).
     */
    private function getReboundMargin(Team $team, int $season): float
    {
        $games = $this->completedSeasonGameIdsForTeam($team, $season);

        if (empty($games)) {
            return 0;
        }

        $avgTeamRebounds = TeamStat::query()
            ->where('team_id', $team->id)
            ->whereIn('game_id', $games)
            ->avg('rebounds') ?? 0;

        $avgOpponentRebounds = TeamStat::query()
            ->where('team_id', '!=', $team->id)
            ->whereIn('game_id', $games)
            ->avg('rebounds') ?? 0;

        return round($avgTeamRebounds - $avgOpponentRebounds, 2);
    }

    /**
     * Extract spread from odds_data JSON if available.
     */
    private function getVegasSpread(Model $game): ?float
    {
        $oddsData = $game->odds_data;

        if (empty($oddsData) || ! isset($oddsData['bookmakers'])) {
            return null;
        }

        foreach ($oddsData['bookmakers'] as $bookmaker) {
            if (! isset($bookmaker['markets'])) {
                continue;
            }

            foreach ($bookmaker['markets'] as $market) {
                // Look for spreads market first
                if ($market['key'] === 'spreads') {
                    foreach ($market['outcomes'] as $outcome) {
                        if ($this->isHomeTeamOutcome($outcome['name'], $game)) {
                            return (float) $outcome['point'];
                        }
                    }
                }

            }
        }

        return null;
    }

    /**
     * Extract total from odds_data JSON if available.
     */
    /**
     * Check if an outcome name matches the home team.
     */
    private function isHomeTeamOutcome(string $outcomeName, Model $game): bool
    {
        $homeTeam = $game->homeTeam;
        $name = strtolower($outcomeName);
        $teamName = strtolower(trim($homeTeam->location.' '.$homeTeam->name));
        $mascot = strtolower($homeTeam->name ?? '');

        return str_contains($name, strtolower($homeTeam->location ?? ''))
            || str_contains($name, $mascot)
            || $name === $teamName;
    }

    /**
     * @return array{off_eff: float, def_eff: float, net_rating: float, tempo: float}
     */
    private function defaultRecentForm(float $defaultEfficiency, float $defaultPace): array
    {
        return [
            'off_eff' => $defaultEfficiency,
            'def_eff' => $defaultEfficiency,
            'net_rating' => 0.0,
            'tempo' => $defaultPace,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function completedSeasonGameIdsForTeam(Team $team, int $season, ?string $venue = null): array
    {
        $query = Game::query()
            ->where('status', 'STATUS_FINAL')
            ->where('season', $season);

        if ($venue === 'home') {
            $query->where('home_team_id', $team->id);
        } elseif ($venue === 'away') {
            $query->where('away_team_id', $team->id);
        } else {
            $query->where(function ($q) use ($team) {
                $q->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            });
        }

        return $query->pluck('id')->toArray();
    }

    /**
     * @return array{
     *   home_out:int,
     *   away_out:int,
     *   home_questionable:int,
     *   away_questionable:int,
     *   spread_adj:float,
     *   total_adj:float
     * }
     */
    private function buildInjuryContext(Model $game): array
    {
        $config = config('nba.prediction');
        $homeRaw = $this->nbaRawInjuryCountsForTeam((int) $game->home_team_id);
        $awayRaw = $this->nbaRawInjuryCountsForTeam((int) $game->away_team_id);
        $season = isset($game->season) ? (int) $game->season : null;
        $homeWeighted = $this->nbaWeightedInjuryCountsForTeam((int) $game->home_team_id, $season);
        $awayWeighted = $this->nbaWeightedInjuryCountsForTeam((int) $game->away_team_id, $season);

        $outSpreadPenalty = (float) ($config['injury_out_spread_penalty'] ?? 0.75);
        $questionableSpreadPenalty = (float) ($config['injury_questionable_spread_penalty'] ?? 0.30);
        $outTotalPenalty = (float) ($config['injury_out_total_penalty'] ?? 0.40);
        $questionableTotalPenalty = (float) ($config['injury_questionable_total_penalty'] ?? 0.15);

        $homePenalty = ($homeWeighted['out'] * $outSpreadPenalty) + ($homeWeighted['questionable'] * $questionableSpreadPenalty);
        $awayPenalty = ($awayWeighted['out'] * $outSpreadPenalty) + ($awayWeighted['questionable'] * $questionableSpreadPenalty);

        // Positive favors home, negative favors away.
        $spreadAdj = $awayPenalty - $homePenalty;
        $totalAdj = -(
            (($homeWeighted['out'] + $awayWeighted['out']) * $outTotalPenalty)
            + (($homeWeighted['questionable'] + $awayWeighted['questionable']) * $questionableTotalPenalty)
        );

        return [
            'home_out' => $homeRaw['out'],
            'away_out' => $awayRaw['out'],
            'home_questionable' => $homeRaw['questionable'],
            'away_questionable' => $awayRaw['questionable'],
            'home_out_weighted' => round($homeWeighted['out'], 2),
            'away_out_weighted' => round($awayWeighted['out'], 2),
            'home_questionable_weighted' => round($homeWeighted['questionable'], 2),
            'away_questionable_weighted' => round($awayWeighted['questionable'], 2),
            'spread_adj' => round($spreadAdj, 2),
            'total_adj' => round($totalAdj, 2),
        ];
    }

    protected function hasPersistedInjuryAdjustedTotal(?Model $homeMetrics, ?Model $awayMetrics): bool
    {
        return $homeMetrics?->injury_total_adjustment !== null
            || $awayMetrics?->injury_total_adjustment !== null;
    }

    protected function persistedInjuryTotalAdjustment(?Model $homeMetrics, ?Model $awayMetrics): float
    {
        return round(
            (float) ($homeMetrics?->injury_total_adjustment ?? 0.0)
            + (float) ($awayMetrics?->injury_total_adjustment ?? 0.0),
            2
        );
    }

    /**
     * @return array{out:float, questionable:float}
     */
    private function nbaWeightedInjuryCountsForTeam(int $teamId, ?int $season = null): array
    {
        $counts = ['out' => 0, 'questionable' => 0];
        if ($teamId <= 0) {
            return $counts;
        }

        $injuries = PlayerInjury::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->get(['player_id', 'status']);

        foreach ($injuries as $injury) {
            $bucket = $this->injuryStatusBucket((string) ($injury->status ?? ''));
            if ($bucket !== null) {
                $impact = $this->injuryImpactMultiplier('nba', (int) ($injury->player_id ?? 0));
                $depthChart = $this->depthChartInjuryMultiplier('nba', $teamId, (int) ($injury->player_id ?? 0), $season);

                $counts[$bucket] += $impact * $depthChart;
            }
        }

        $counts['out'] = round((float) $counts['out'], 2);
        $counts['questionable'] = round((float) $counts['questionable'], 2);

        return $counts;
    }

    /**
     * @return array{out:int, questionable:int}
     */
    private function nbaRawInjuryCountsForTeam(int $teamId): array
    {
        $counts = ['out' => 0, 'questionable' => 0];
        if ($teamId <= 0) {
            return $counts;
        }

        $statuses = PlayerInjury::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->pluck('status');

        foreach ($statuses as $status) {
            $bucket = $this->injuryStatusBucket((string) $status);
            if ($bucket !== null) {
                $counts[$bucket]++;
            }
        }

        return $counts;
    }

    /**
     * @return array{0:float,1:array<string,mixed>}
     */
    private function applyTrueEpaSpreadBlend(float $legacySpread, ?Model $homeMetrics, ?Model $awayMetrics): array
    {
        return $this->applyTrueEpaSpreadBlendForSport(
            'nba',
            $legacySpread,
            $homeMetrics,
            $awayMetrics,
            20.0
        );
    }

    /**
     * @return array{0:float,1:array<string,mixed>}
     */
    private function applyTrueEpaTotalBlend(float $legacyTotal, ?Model $homeMetrics, ?Model $awayMetrics): array
    {
        return $this->applyTrueEpaTotalBlendForSport(
            'nba',
            $legacyTotal,
            $homeMetrics,
            $awayMetrics,
            35.0,
            180.0,
            270.0
        );
    }
}
