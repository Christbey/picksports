<?php

namespace App\Actions\NFL;

use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Prediction;
use App\Models\NFL\TeamMetric;
use App\Services\Sports\DepthChartImpactService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class GeneratePredictionFromHistoricalElo
{
    /**
     * @var array<string,mixed>
     */
    protected array $lastModelMetadata = [];

    public function __construct(protected ?DepthChartImpactService $depthChartImpactService = null)
    {
        $this->depthChartImpactService ??= app(DepthChartImpactService::class);
    }

    public function execute(Game $game): string
    {
        if (! $game->homeTeam || ! $game->awayTeam) {
            return 'skipped';
        }

        $homeElo = $this->getEloAtDate($game->home_team_id, $game->game_date);
        $awayElo = $this->getEloAtDate($game->away_team_id, $game->game_date);

        $homeFieldAdvantage = config('nfl.elo.home_field_advantage');
        $adjustedHomeElo = $game->neutral_site ? $homeElo : $homeElo + $homeFieldAdvantage;

        $legacyWinProbability = $this->calculateWinProbability($adjustedHomeElo, $awayElo);

        $eloDiff = $adjustedHomeElo - $awayElo;
        $pointsPerElo = config('nfl.predictions.points_per_elo');
        $legacySpread = $eloDiff * $pointsPerElo;
        $minSpread = config('nfl.predictions.min_spread');
        $maxSpread = config('nfl.predictions.max_spread');
        $legacySpread = max($minSpread, min($maxSpread, $legacySpread));

        $averageTotal = config('nfl.predictions.average_total');
        $defaultElo = config('nfl.elo.default_rating');
        $combinedEloBonus = (($homeElo + $awayElo) - (2 * $defaultElo)) / 100;
        $legacyTotal = $averageTotal + $combinedEloBonus;

        [$predictedSpread, $winProbability, $predictedTotal] = $this->applyTrueEpaBlend(
            game: $game,
            legacySpread: $legacySpread,
            legacyWinProbability: $legacyWinProbability,
            legacyTotal: $legacyTotal
        );
        [$predictedSpread, $winProbability] = $this->applyPreseasonSignalBlend(
            $game,
            $predictedSpread,
            $winProbability
        );
        [$predictedSpread, $winProbability, $predictedTotal] = $this->applyRollingEfficiencyBlend(
            $game,
            $predictedSpread,
            $winProbability,
            $predictedTotal
        );
        [$predictedSpread, $winProbability] = $this->applyQbFormBlend(
            $game,
            $predictedSpread,
            $winProbability
        );
        [$predictedSpread, $winProbability, $predictedTotal] = $this->applyLineMatchupBlend(
            $game,
            $predictedSpread,
            $winProbability,
            $predictedTotal
        );
        [$predictedSpread, $winProbability, $predictedTotal] = $this->applyContextualFactorsBlend(
            $game,
            $predictedSpread,
            $winProbability,
            $predictedTotal
        );
        [$predictedSpread, $winProbability, $predictedTotal] = $this->applyDepthChartInjuryAdjustments(
            $game,
            $predictedSpread,
            $winProbability,
            $predictedTotal
        );
        [$predictedSpread, $winProbability, $predictedTotal] = $this->applyMarketBlend(
            $game,
            $predictedSpread,
            $winProbability,
            $predictedTotal
        );
        $winProbability = $this->applyAdaptiveWinProbabilityCalibration($game, $winProbability);
        $confidenceScore = max($winProbability, 1 - $winProbability) * 100;
        $this->applyAnalysisLayer($game, $predictedSpread, $predictedTotal, $winProbability);

        $existing = Prediction::query()->where('game_id', $game->id)->first();

        Prediction::updateOrCreate(
            ['game_id' => $game->id],
            [
                'home_elo' => round($homeElo, 1),
                'away_elo' => round($awayElo, 1),
                'predicted_spread' => round($predictedSpread, 1),
                'predicted_total' => round($predictedTotal, 1),
                'win_probability' => round($winProbability, 3),
                'confidence_score' => round($confidenceScore, 2),
                'model_metadata' => $this->lastModelMetadata,
            ]
        );

        return $existing ? 'updated' : 'created';
    }

    /**
     * @return array{0:float,1:float,2:float}
     */
    protected function applyTrueEpaBlend(
        Game $game,
        float $legacySpread,
        float $legacyWinProbability,
        float $legacyTotal
    ): array {
        if (! config('nfl.predictions.true_epa.enabled', false)) {
            $this->lastModelMetadata = [
                'model' => 'nfl_historical_elo',
                'true_epa' => [
                    'enabled' => false,
                    'applied' => false,
                    'reason' => 'feature_disabled',
                ],
                'legacy' => [
                    'spread' => round($legacySpread, 4),
                    'win_probability' => round($legacyWinProbability, 6),
                    'total' => round($legacyTotal, 4),
                ],
                'blended' => [
                    'spread' => round($legacySpread, 4),
                    'win_probability' => round($legacyWinProbability, 6),
                    'total' => round($legacyTotal, 4),
                ],
            ];

            return [$legacySpread, $legacyWinProbability, $legacyTotal];
        }

        $regularSeasonType = (string) config('nfl.season.types.regular', 2);
        $homeMetric = TeamMetric::query()
            ->where('team_id', $game->home_team_id)
            ->where('season', $game->season)
            ->where('season_type', $regularSeasonType)
            ->first();
        $awayMetric = TeamMetric::query()
            ->where('team_id', $game->away_team_id)
            ->where('season', $game->season)
            ->where('season_type', $regularSeasonType)
            ->first();

        if (! $homeMetric || ! $awayMetric) {
            $this->lastModelMetadata = [
                'model' => 'nfl_historical_elo',
                'true_epa' => [
                    'enabled' => true,
                    'applied' => false,
                    'reason' => 'missing_team_metrics',
                ],
                'legacy' => [
                    'spread' => round($legacySpread, 4),
                    'win_probability' => round($legacyWinProbability, 6),
                    'total' => round($legacyTotal, 4),
                ],
                'blended' => [
                    'spread' => round($legacySpread, 4),
                    'win_probability' => round($legacyWinProbability, 6),
                    'total' => round($legacyTotal, 4),
                ],
            ];

            return [$legacySpread, $legacyWinProbability, $legacyTotal];
        }

        $homeNetEpa = $homeMetric->net_true_epa_per_play;
        $awayNetEpa = $awayMetric->net_true_epa_per_play;
        if ($homeNetEpa === null || $awayNetEpa === null) {
            $this->lastModelMetadata = [
                'model' => 'nfl_historical_elo',
                'true_epa' => [
                    'enabled' => true,
                    'applied' => false,
                    'reason' => 'missing_net_true_epa',
                ],
                'legacy' => [
                    'spread' => round($legacySpread, 4),
                    'win_probability' => round($legacyWinProbability, 6),
                    'total' => round($legacyTotal, 4),
                ],
                'blended' => [
                    'spread' => round($legacySpread, 4),
                    'win_probability' => round($legacyWinProbability, 6),
                    'total' => round($legacyTotal, 4),
                ],
            ];

            return [$legacySpread, $legacyWinProbability, $legacyTotal];
        }

        $weight = $this->clamp((float) config('nfl.predictions.true_epa.blend_weight', 0.35), 0.0, 1.0);
        $epaDiff = (float) $homeNetEpa - (float) $awayNetEpa;

        $epaSpread = $epaDiff * (float) config('nfl.predictions.true_epa.spread_points_per_epa', 14.0);
        $blendedSpread = $this->blend($legacySpread, $epaSpread, $weight);
        $blendedSpread = $this->clamp(
            $blendedSpread,
            (float) config('nfl.predictions.min_spread'),
            (float) config('nfl.predictions.max_spread')
        );

        $maxAdjust = (float) config('nfl.predictions.true_epa.win_prob_max_adjustment', 0.12);
        $sensitivity = (float) config('nfl.predictions.true_epa.win_prob_sensitivity', 8.0);
        $epaWinAdjustment = tanh($epaDiff * $sensitivity) * $maxAdjust;
        $epaWinProbability = $this->clamp($legacyWinProbability + $epaWinAdjustment, 0.01, 0.99);
        $blendedWinProbability = $this->blend($legacyWinProbability, $epaWinProbability, $weight);
        $blendedWinProbability = $this->clamp($blendedWinProbability, 0.01, 0.99);

        $homeOff = $homeMetric->offensive_true_epa_per_play;
        $homeDef = $homeMetric->defensive_true_epa_per_play;
        $awayOff = $awayMetric->offensive_true_epa_per_play;
        $awayDef = $awayMetric->defensive_true_epa_per_play;
        if ($homeOff === null || $homeDef === null || $awayOff === null || $awayDef === null) {
            $this->lastModelMetadata = [
                'model' => 'nfl_historical_elo',
                'true_epa' => [
                    'enabled' => true,
                    'applied' => true,
                    'weight' => round($weight, 4),
                    'reason' => 'missing_off_def_epa_for_total',
                    'epa_diff' => round($epaDiff, 6),
                ],
                'legacy' => [
                    'spread' => round($legacySpread, 4),
                    'win_probability' => round($legacyWinProbability, 6),
                    'total' => round($legacyTotal, 4),
                ],
                'blended' => [
                    'spread' => round($blendedSpread, 4),
                    'win_probability' => round($blendedWinProbability, 6),
                    'total' => round($legacyTotal, 4),
                ],
            ];

            return [$blendedSpread, $blendedWinProbability, $legacyTotal];
        }

        $totalScale = (float) config('nfl.predictions.true_epa.total_points_per_epa_component', 20.0);
        $homeExpectedDelta = ((float) $homeOff - (float) $awayDef) * $totalScale;
        $awayExpectedDelta = ((float) $awayOff - (float) $homeDef) * $totalScale;
        $epaTotal = $legacyTotal + $homeExpectedDelta + $awayExpectedDelta;
        $blendedTotal = $this->blend($legacyTotal, $epaTotal, $weight);
        $blendedTotal = $this->clamp(
            $blendedTotal,
            (float) config('nfl.predictions.true_epa.min_predicted_total', 28.0),
            (float) config('nfl.predictions.true_epa.max_predicted_total', 66.0)
        );

        $this->lastModelMetadata = [
            'model' => 'nfl_historical_elo',
            'true_epa' => [
                'enabled' => true,
                'applied' => true,
                'weight' => round($weight, 4),
                'epa_diff' => round($epaDiff, 6),
            ],
            'legacy' => [
                'spread' => round($legacySpread, 4),
                'win_probability' => round($legacyWinProbability, 6),
                'total' => round($legacyTotal, 4),
            ],
            'epa_component' => [
                'spread' => round($epaSpread, 4),
                'win_probability' => round($epaWinProbability, 6),
                'total' => round($epaTotal, 4),
            ],
            'blended' => [
                'spread' => round($blendedSpread, 4),
                'win_probability' => round($blendedWinProbability, 6),
                'total' => round($blendedTotal, 4),
            ],
        ];

        return [$blendedSpread, $blendedWinProbability, $blendedTotal];
    }

    /**
     * Use the game's eventual primary passer only as a starter identity label,
     * then score that QB from games before kickoff. This lets historical
     * backtests evaluate QB quality without leaking same-game production.
     *
     * @return array{0:float,1:float}
     */
    protected function applyQbFormBlend(Game $game, float $predictedSpread, float $winProbability): array
    {
        $this->lastModelMetadata['qb_form'] = [
            'enabled' => (bool) config('nfl.predictions.qb_form.enabled', false),
            'applied' => false,
        ];

        if (! config('nfl.predictions.qb_form.enabled', false)) {
            $this->lastModelMetadata['qb_form']['reason'] = 'feature_disabled';

            return [$predictedSpread, $winProbability];
        }

        $home = $this->qbContextForGame($game, (int) $game->home_team_id);
        $away = $this->qbContextForGame($game, (int) $game->away_team_id);

        if (($home['qb_id'] ?? null) === null || ($away['qb_id'] ?? null) === null) {
            $this->lastModelMetadata['qb_form']['reason'] = 'missing_game_qb_identity';
            $this->lastModelMetadata['qb_form']['home'] = $home;
            $this->lastModelMetadata['qb_form']['away'] = $away;

            return [$predictedSpread, $winProbability];
        }

        $minPriorAttempts = (int) config('nfl.predictions.qb_form.min_prior_attempts', 30);
        if (($home['prior_attempts'] ?? 0) < $minPriorAttempts || ($away['prior_attempts'] ?? 0) < $minPriorAttempts) {
            $this->lastModelMetadata['qb_form']['reason'] = 'insufficient_prior_attempts';
            $this->lastModelMetadata['qb_form']['home'] = $home;
            $this->lastModelMetadata['qb_form']['away'] = $away;
            $this->lastModelMetadata['qb_form']['min_prior_attempts'] = $minPriorAttempts;

            return [$predictedSpread, $winProbability];
        }

        $maxSignalSpread = (float) config('nfl.predictions.qb_form.max_signal_spread', 6.0);
        $rawSignalSpread = (float) $home['score'] - (float) $away['score'];
        $experienceWeight = (float) config('nfl.predictions.qb_form.experience_weight', 0.35);
        $experienceSignal = ($this->qbExperienceScore((string) ($home['experience_bucket'] ?? 'unknown'))
            - $this->qbExperienceScore((string) ($away['experience_bucket'] ?? 'unknown'))) * $experienceWeight;
        $qbSignalSpread = $this->clamp($rawSignalSpread + $experienceSignal, -$maxSignalSpread, $maxSignalSpread);
        $baseWeight = $this->clamp((float) config('nfl.predictions.qb_form.blend_weight', 0.22), 0.0, 1.0);
        $sampleWeight = $this->qbFormSampleWeight($home, $away);
        $earlySeasonMultiplier = $this->qbFormEarlySeasonMultiplier($game);
        $effectiveWeight = $this->clamp($baseWeight * $sampleWeight * $earlySeasonMultiplier, 0.0, 1.0);

        $blendedSpread = $this->blend($predictedSpread, $predictedSpread + $qbSignalSpread, $effectiveWeight);
        $blendedSpread = $this->clamp(
            $blendedSpread,
            (float) config('nfl.predictions.min_spread'),
            (float) config('nfl.predictions.max_spread')
        );

        $spreadCoefficient = (float) config('nfl.predictions.spread_to_probability_coefficient', 7.0);
        $blendedWinProbability = $this->clamp(1 / (1 + exp(-$blendedSpread / $spreadCoefficient)), 0.01, 0.99);

        $this->lastModelMetadata['qb_form'] = [
            'enabled' => true,
            'applied' => true,
            'base_weight' => round($baseWeight, 4),
            'sample_weight' => round($sampleWeight, 4),
            'early_season_multiplier' => round($earlySeasonMultiplier, 4),
            'effective_weight' => round($effectiveWeight, 4),
            'home' => $home,
            'away' => $away,
            'raw_signal_spread' => round($rawSignalSpread, 3),
            'experience_signal_spread' => round($experienceSignal, 3),
            'signal_spread' => round($qbSignalSpread, 3),
            'blended_spread' => round($blendedSpread, 3),
        ];

        return [$blendedSpread, $blendedWinProbability];
    }

    /**
     * Blend the running spread with a predictive_rating-derived spread when in-season
     * EPA hasn't kicked in yet. Pulls predictive_rating from the seeded preseason
     * team metrics, which already includes the offseason adjustment (QB/skill
     * continuity, injuries) computed by HistoricalTeamMetricCalculator.
     *
     * @return array{0:float,1:float}
     */
    protected function applyPreseasonSignalBlend(
        Game $game,
        float $predictedSpread,
        float $winProbability
    ): array {
        $this->lastModelMetadata['preseason_signal'] = [
            'enabled' => (bool) config('nfl.predictions.preseason_signal.enabled', true),
            'applied' => false,
        ];

        if (! config('nfl.predictions.preseason_signal.enabled', true)) {
            $this->lastModelMetadata['preseason_signal']['reason'] = 'feature_disabled';

            return [$predictedSpread, $winProbability];
        }

        if (($this->lastModelMetadata['true_epa']['applied'] ?? false) === true) {
            $this->lastModelMetadata['preseason_signal']['reason'] = 'true_epa_already_applied';

            return [$predictedSpread, $winProbability];
        }

        $regularSeasonType = (string) config('nfl.season.types.regular', 2);
        $homeMetric = TeamMetric::query()
            ->where('team_id', $game->home_team_id)
            ->where('season', $game->season)
            ->where('season_type', $regularSeasonType)
            ->first();
        $awayMetric = TeamMetric::query()
            ->where('team_id', $game->away_team_id)
            ->where('season', $game->season)
            ->where('season_type', $regularSeasonType)
            ->first();

        if (! $homeMetric || ! $awayMetric
            || $homeMetric->predictive_rating === null
            || $awayMetric->predictive_rating === null
        ) {
            $this->lastModelMetadata['preseason_signal']['reason'] = 'missing_predictive_rating';

            return [$predictedSpread, $winProbability];
        }

        $homeFieldAdvantage = $game->neutral_site
            ? 0
            : (float) config('nfl.elo.home_field_advantage') * (float) config('nfl.predictions.points_per_elo');
        $predictiveDiff = (float) $homeMetric->predictive_rating - (float) $awayMetric->predictive_rating;
        $signalSpread = $predictiveDiff + $homeFieldAdvantage;

        $weight = $this->clamp(
            (float) config('nfl.predictions.preseason_signal.blend_weight', 0.25),
            0.0,
            1.0
        );
        $blendedSpread = $this->blend($predictedSpread, $signalSpread, $weight);
        $blendedSpread = $this->clamp(
            $blendedSpread,
            (float) config('nfl.predictions.min_spread'),
            (float) config('nfl.predictions.max_spread')
        );

        $spreadCoefficient = (float) config('nfl.predictions.spread_to_probability_coefficient', 7.0);
        $blendedWinProbability = $this->clamp(1 / (1 + exp(-$blendedSpread / $spreadCoefficient)), 0.01, 0.99);

        $this->lastModelMetadata['preseason_signal'] = [
            'enabled' => true,
            'applied' => true,
            'weight' => round($weight, 4),
            'predictive_diff' => round($predictiveDiff, 4),
            'signal_spread' => round($signalSpread, 4),
            'blended_spread' => round($blendedSpread, 4),
        ];

        return [$blendedSpread, $blendedWinProbability];
    }

    /**
     * Derive an OL-vs-DL matchup from prior team stats only. We do not have
     * premium pressure charting here, so the first version uses sack rate and
     * rushing efficiency as auditable trench proxies.
     *
     * @return array{0:float,1:float,2:float}
     */
    protected function applyLineMatchupBlend(
        Game $game,
        float $predictedSpread,
        float $winProbability,
        float $predictedTotal
    ): array {
        $this->lastModelMetadata['line_matchup'] = [
            'enabled' => (bool) config('nfl.predictions.line_matchup.enabled', false),
            'applied' => false,
        ];

        if (! config('nfl.predictions.line_matchup.enabled', false)) {
            $this->lastModelMetadata['line_matchup']['reason'] = 'feature_disabled';

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $home = $this->lineProfile($game, (int) $game->home_team_id);
        $away = $this->lineProfile($game, (int) $game->away_team_id);
        $minGames = (int) config('nfl.predictions.line_matchup.min_games', 2);

        if (($home['games'] ?? 0) < $minGames || ($away['games'] ?? 0) < $minGames) {
            $this->lastModelMetadata['line_matchup']['reason'] = 'insufficient_prior_games';
            $this->lastModelMetadata['line_matchup']['home_games'] = (int) ($home['games'] ?? 0);
            $this->lastModelMetadata['line_matchup']['away_games'] = (int) ($away['games'] ?? 0);

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $runWeight = (float) config('nfl.predictions.line_matchup.run_edge_weight', 1.35);
        $pressureWeight = (float) config('nfl.predictions.line_matchup.pressure_edge_weight', 34.0);
        $maxSignalSpread = (float) config('nfl.predictions.line_matchup.max_signal_spread', 4.0);

        $homeRunEdge = (float) $home['off_rush_yards_per_attempt'] - (float) $away['def_rush_yards_allowed_per_attempt'];
        $awayRunEdge = (float) $away['off_rush_yards_per_attempt'] - (float) $home['def_rush_yards_allowed_per_attempt'];
        $homePressureEdge = (float) $away['def_sack_rate'] + (float) $home['off_sack_allowed_rate'];
        $awayPressureEdge = (float) $home['def_sack_rate'] + (float) $away['off_sack_allowed_rate'];

        $homeMatchupScore = ($homeRunEdge * $runWeight) - ($homePressureEdge * $pressureWeight);
        $awayMatchupScore = ($awayRunEdge * $runWeight) - ($awayPressureEdge * $pressureWeight);
        $signalSpread = $this->clamp($homeMatchupScore - $awayMatchupScore, -$maxSignalSpread, $maxSignalSpread);

        $weight = $this->clamp((float) config('nfl.predictions.line_matchup.blend_weight', 0.18), 0.0, 1.0);
        $blendedSpread = $this->blend($predictedSpread, $predictedSpread + $signalSpread, $weight);
        $blendedSpread = $this->clamp(
            $blendedSpread,
            (float) config('nfl.predictions.min_spread'),
            (float) config('nfl.predictions.max_spread')
        );

        $totalRunWeight = (float) config('nfl.predictions.line_matchup.total_run_edge_weight', 0.8);
        $totalPressureWeight = (float) config('nfl.predictions.line_matchup.total_pressure_edge_weight', 14.0);
        $maxTotalAdjustment = (float) config('nfl.predictions.line_matchup.max_total_adjustment', 3.0);
        $totalSignal = $this->clamp(
            (($homeRunEdge + $awayRunEdge) * $totalRunWeight)
                - (max(0.0, $homePressureEdge + $awayPressureEdge) * $totalPressureWeight),
            -$maxTotalAdjustment,
            $maxTotalAdjustment
        );
        $blendedTotal = $this->clamp(
            $predictedTotal + ($totalSignal * min($weight, 0.25)),
            (float) config('nfl.predictions.true_epa.min_predicted_total', 28.0),
            (float) config('nfl.predictions.true_epa.max_predicted_total', 66.0)
        );

        $spreadCoefficient = (float) config('nfl.predictions.spread_to_probability_coefficient', 7.0);
        $blendedWinProbability = $this->clamp(1 / (1 + exp(-$blendedSpread / $spreadCoefficient)), 0.01, 0.99);

        $this->lastModelMetadata['line_matchup'] = [
            'enabled' => true,
            'applied' => true,
            'weight' => round($weight, 4),
            'home' => $home,
            'away' => $away,
            'home_run_edge' => round($homeRunEdge, 3),
            'away_run_edge' => round($awayRunEdge, 3),
            'home_pressure_edge' => round($homePressureEdge, 4),
            'away_pressure_edge' => round($awayPressureEdge, 4),
            'home_matchup_score' => round($homeMatchupScore, 3),
            'away_matchup_score' => round($awayMatchupScore, 3),
            'signal_spread' => round($signalSpread, 3),
            'total_signal' => round($totalSignal, 3),
            'blended_spread' => round($blendedSpread, 3),
            'blended_total' => round($blendedTotal, 3),
        ];

        return [$blendedSpread, $blendedWinProbability, $blendedTotal];
    }

    /**
     * Add small, auditable situational features that should inform the
     * calculated prediction without overpowering core team/QB efficiency.
     *
     * @return array{0:float,1:float,2:float}
     */
    protected function applyContextualFactorsBlend(
        Game $game,
        float $predictedSpread,
        float $winProbability,
        float $predictedTotal
    ): array {
        $this->lastModelMetadata['contextual_factors'] = [
            'enabled' => (bool) config('nfl.predictions.contextual_factors.enabled', false),
            'applied' => false,
        ];

        if (! config('nfl.predictions.contextual_factors.enabled', false)) {
            $this->lastModelMetadata['contextual_factors']['reason'] = 'feature_disabled';

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $homeAway = $this->homeAwayStrengthContext($game);
        $division = $this->divisionRivalryContext($game);
        $weather = $this->weatherTotalContext($game);
        $schedule = $this->scheduleSpotContext($game);
        $coaching = $this->coachingContext($game);

        $spreadAdjustment = $homeAway['spread_adjustment']
            + $division['spread_adjustment']
            + $schedule['spread_adjustment']
            + $coaching['spread_adjustment'];
        $totalAdjustment = $division['total_adjustment']
            + $weather['total_adjustment']
            + $schedule['total_adjustment'];

        $maxSpreadAdjustment = (float) config('nfl.predictions.contextual_factors.max_spread_adjustment', 4.0);
        $maxTotalAdjustment = (float) config('nfl.predictions.contextual_factors.max_total_adjustment', 5.0);
        $spreadAdjustment = $this->clamp($spreadAdjustment, -$maxSpreadAdjustment, $maxSpreadAdjustment);
        $totalAdjustment = $this->clamp($totalAdjustment, -$maxTotalAdjustment, $maxTotalAdjustment);

        $adjustedSpread = $this->clamp(
            $predictedSpread + $spreadAdjustment,
            (float) config('nfl.predictions.min_spread'),
            (float) config('nfl.predictions.max_spread')
        );
        $adjustedTotal = $this->clamp(
            $predictedTotal + $totalAdjustment,
            (float) config('nfl.predictions.true_epa.min_predicted_total', 28.0),
            (float) config('nfl.predictions.true_epa.max_predicted_total', 66.0)
        );

        $spreadCoefficient = (float) config('nfl.predictions.spread_to_probability_coefficient', 7.0);
        $adjustedWinProbability = $this->clamp(1 / (1 + exp(-$adjustedSpread / $spreadCoefficient)), 0.01, 0.99);

        $this->lastModelMetadata['contextual_factors'] = [
            'enabled' => true,
            'applied' => round($spreadAdjustment, 3) !== 0.0 || round($totalAdjustment, 3) !== 0.0,
            'home_away_strength' => $homeAway,
            'division_rivalry' => $division,
            'weather_total' => $weather,
            'schedule_spot' => $schedule,
            'coaching_prior' => $coaching,
            'spread_adjustment' => round($spreadAdjustment, 3),
            'total_adjustment' => round($totalAdjustment, 3),
            'blended_spread' => round($adjustedSpread, 3),
            'blended_total' => round($adjustedTotal, 3),
        ];

        return [$adjustedSpread, $adjustedWinProbability, $adjustedTotal];
    }

    /**
     * @return array<string,mixed>
     */
    protected function homeAwayStrengthContext(Game $game): array
    {
        $minGames = (int) config('nfl.predictions.contextual_factors.home_away_min_games', 2);
        $weight = (float) config('nfl.predictions.contextual_factors.home_away_weight', 0.12);
        $home = $this->venueSplitProfile($game, (int) $game->home_team_id, true);
        $away = $this->venueSplitProfile($game, (int) $game->away_team_id, false);

        if ($home['games'] < $minGames || $away['games'] < $minGames) {
            return [
                'applied' => false,
                'reason' => 'insufficient_prior_games',
                'home' => $home,
                'away' => $away,
                'spread_adjustment' => 0.0,
            ];
        }

        $adjustment = ((float) $home['avg_margin'] - (float) $away['avg_margin']) * $weight;

        return [
            'applied' => true,
            'home' => $home,
            'away' => $away,
            'weight' => round($weight, 4),
            'spread_adjustment' => round($adjustment, 3),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function divisionRivalryContext(Game $game): array
    {
        $isDivisionGame = $this->divisionKey($game->homeTeam) !== null
            && $this->divisionKey($game->homeTeam) === $this->divisionKey($game->awayTeam);
        $lookback = max(1, (int) config('nfl.predictions.contextual_factors.division_lookback_games', 6));
        $h2h = $this->headToHeadProfile($game, $lookback);
        $spreadWeight = (float) config('nfl.predictions.contextual_factors.division_h2h_weight', 0.10);
        $totalPenalty = $isDivisionGame
            ? (float) config('nfl.predictions.contextual_factors.division_total_penalty', -0.8)
            : 0.0;

        return [
            'applied' => $isDivisionGame || $h2h['games'] > 0,
            'is_division_game' => $isDivisionGame,
            'h2h' => $h2h,
            'spread_adjustment' => round((float) $h2h['home_avg_margin'] * $spreadWeight, 3),
            'total_adjustment' => round($totalPenalty, 3),
            'reason' => $isDivisionGame ? 'division_matchup' : ($h2h['games'] > 0 ? 'recent_h2h' : 'not_division_or_h2h'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function weatherTotalContext(Game $game): array
    {
        if ($this->isIndoorVenue($game)) {
            return [
                'applied' => false,
                'reason' => 'indoor_or_retractable_venue',
                'venue_name' => $game->venue_name,
                'total_adjustment' => 0.0,
            ];
        }

        $month = $this->asDate($game->game_date)?->month;
        $state = strtoupper((string) ($game->venue_state ?? ''));
        $coldStates = array_map('strtoupper', (array) config('nfl.predictions.contextual_factors.cold_weather_states', []));
        $hotStates = array_map('strtoupper', (array) config('nfl.predictions.contextual_factors.hot_weather_states', []));
        $adjustment = 0.0;
        $reason = 'neutral_weather_proxy';

        if ($month !== null && in_array($state, $coldStates, true) && in_array($month, [11, 12, 1, 2], true)) {
            $adjustment += (float) config('nfl.predictions.contextual_factors.cold_weather_total_adjustment', -1.2);
            $reason = 'cold_outdoor_proxy';
        }

        if ($month !== null && in_array($state, $hotStates, true) && in_array($month, [9, 10], true)) {
            $adjustment += (float) config('nfl.predictions.contextual_factors.hot_weather_total_adjustment', -0.4);
            $reason = 'hot_outdoor_proxy';
        }

        return [
            'applied' => round($adjustment, 3) !== 0.0,
            'reason' => $reason,
            'venue_name' => $game->venue_name,
            'venue_state' => $state ?: null,
            'month' => $month,
            'total_adjustment' => round($adjustment, 3),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function scheduleSpotContext(Game $game): array
    {
        $home = $this->scheduleProfile($game, (int) $game->home_team_id);
        $away = $this->scheduleProfile($game, (int) $game->away_team_id);
        $restDiffWeight = (float) config('nfl.predictions.contextual_factors.rest_diff_weight', 0.18);
        $shortRestPenalty = (float) config('nfl.predictions.contextual_factors.short_rest_penalty', -0.45);
        $roadTripPenalty = (float) config('nfl.predictions.contextual_factors.consecutive_road_penalty', -0.35);
        $shortRestTotalPenalty = (float) config('nfl.predictions.contextual_factors.short_rest_total_penalty', -0.4);

        $spreadAdjustment = 0.0;
        $totalAdjustment = 0.0;
        if ($home['rest_days'] !== null && $away['rest_days'] !== null) {
            $spreadAdjustment += $this->clamp(((int) $home['rest_days'] - (int) $away['rest_days']) * $restDiffWeight, -1.0, 1.0);
        }
        if (($home['rest_days'] ?? 99) <= 4) {
            $spreadAdjustment += $shortRestPenalty;
            $totalAdjustment += $shortRestTotalPenalty;
        }
        if (($away['rest_days'] ?? 99) <= 4) {
            $spreadAdjustment -= $shortRestPenalty;
            $totalAdjustment += $shortRestTotalPenalty;
        }
        if (($away['consecutive_road_games'] ?? 0) >= 2) {
            $spreadAdjustment -= $roadTripPenalty;
        }

        return [
            'applied' => round($spreadAdjustment, 3) !== 0.0 || round($totalAdjustment, 3) !== 0.0,
            'home' => $home,
            'away' => $away,
            'spread_adjustment' => round($spreadAdjustment, 3),
            'total_adjustment' => round($totalAdjustment, 3),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function coachingContext(Game $game): array
    {
        $priors = (array) config('nfl.predictions.contextual_factors.coaching_priors', []);
        $weight = (float) config('nfl.predictions.contextual_factors.coaching_weight', 0.25);
        $homeAbbr = strtoupper((string) ($game->homeTeam?->abbreviation ?? ''));
        $awayAbbr = strtoupper((string) ($game->awayTeam?->abbreviation ?? ''));
        $homeRating = is_numeric($priors[$homeAbbr] ?? null) ? (float) $priors[$homeAbbr] : 0.0;
        $awayRating = is_numeric($priors[$awayAbbr] ?? null) ? (float) $priors[$awayAbbr] : 0.0;
        $adjustment = ($homeRating - $awayRating) * $weight;

        return [
            'applied' => round($adjustment, 3) !== 0.0,
            'home_rating' => round($homeRating, 3),
            'away_rating' => round($awayRating, 3),
            'weight' => round($weight, 4),
            'spread_adjustment' => round($adjustment, 3),
            'reason' => $priors === [] ? 'no_coaching_priors_configured' : 'configured_prior',
        ];
    }

    /**
     * Blend in leakage-safe in-season team performance using only games before
     * this game date. This is intentionally simple and auditable for historical
     * backtests: scoring margin, recent margin, yard differential, and turnover
     * differential.
     *
     * @return array{0:float,1:float,2:float}
     */
    protected function applyRollingEfficiencyBlend(
        Game $game,
        float $predictedSpread,
        float $winProbability,
        float $predictedTotal
    ): array {
        $this->lastModelMetadata['rolling_efficiency'] = [
            'enabled' => (bool) config('nfl.predictions.rolling_efficiency.enabled', false),
            'applied' => false,
        ];

        if (! config('nfl.predictions.rolling_efficiency.enabled', false)) {
            $this->lastModelMetadata['rolling_efficiency']['reason'] = 'feature_disabled';

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $home = $this->rollingEfficiencyProfile($game, (int) $game->home_team_id);
        $away = $this->rollingEfficiencyProfile($game, (int) $game->away_team_id);
        $minGames = (int) config('nfl.predictions.rolling_efficiency.min_games', 2);

        if (($home['games'] ?? 0) < $minGames || ($away['games'] ?? 0) < $minGames) {
            $this->lastModelMetadata['rolling_efficiency']['reason'] = 'insufficient_prior_games';
            $this->lastModelMetadata['rolling_efficiency']['home_games'] = (int) ($home['games'] ?? 0);
            $this->lastModelMetadata['rolling_efficiency']['away_games'] = (int) ($away['games'] ?? 0);

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $marginWeight = (float) config('nfl.predictions.rolling_efficiency.margin_weight', 0.55);
        $recentMarginWeight = (float) config('nfl.predictions.rolling_efficiency.recent_margin_weight', 0.25);
        $yardDiffWeight = (float) config('nfl.predictions.rolling_efficiency.yard_diff_weight', 0.12);
        $turnoverWeight = (float) config('nfl.predictions.rolling_efficiency.turnover_weight', 0.75);
        $maxSignalSpread = (float) config('nfl.predictions.rolling_efficiency.max_signal_spread', 14.0);

        $homeSignal = ((float) $home['avg_margin'] * $marginWeight)
            + ((float) $home['recent_margin'] * $recentMarginWeight)
            + (((float) $home['yard_diff'] / 50.0) * $yardDiffWeight)
            + ((float) $home['turnover_diff'] * $turnoverWeight);
        $awaySignal = ((float) $away['avg_margin'] * $marginWeight)
            + ((float) $away['recent_margin'] * $recentMarginWeight)
            + (((float) $away['yard_diff'] / 50.0) * $yardDiffWeight)
            + ((float) $away['turnover_diff'] * $turnoverWeight);

        $homeFieldAdvantage = $game->neutral_site
            ? 0.0
            : (float) config('nfl.elo.home_field_advantage') * (float) config('nfl.predictions.points_per_elo');
        $signalSpread = $this->clamp($homeSignal - $awaySignal + $homeFieldAdvantage, -$maxSignalSpread, $maxSignalSpread);

        $weight = $this->clamp((float) config('nfl.predictions.rolling_efficiency.blend_weight', 0.35), 0.0, 1.0);
        $blendedSpread = $this->blend($predictedSpread, $signalSpread, $weight);
        $blendedSpread = $this->clamp(
            $blendedSpread,
            (float) config('nfl.predictions.min_spread'),
            (float) config('nfl.predictions.max_spread')
        );

        $spreadCoefficient = (float) config('nfl.predictions.spread_to_probability_coefficient', 7.0);
        $blendedWinProbability = $this->clamp(1 / (1 + exp(-$blendedSpread / $spreadCoefficient)), 0.01, 0.99);

        $totalSignal = ((float) $home['points_for'] + (float) $away['points_for'] + (float) $home['points_against'] + (float) $away['points_against']) / 2.0;
        $blendedTotal = $this->blend($predictedTotal, $totalSignal, min($weight, 0.25));
        $blendedTotal = $this->clamp(
            $blendedTotal,
            (float) config('nfl.predictions.true_epa.min_predicted_total', 28.0),
            (float) config('nfl.predictions.true_epa.max_predicted_total', 66.0)
        );

        $this->lastModelMetadata['rolling_efficiency'] = [
            'enabled' => true,
            'applied' => true,
            'weight' => round($weight, 4),
            'home' => $home,
            'away' => $away,
            'home_signal' => round($homeSignal, 3),
            'away_signal' => round($awaySignal, 3),
            'signal_spread' => round($signalSpread, 3),
            'blended_spread' => round($blendedSpread, 3),
            'total_signal' => round($totalSignal, 3),
            'blended_total' => round($blendedTotal, 3),
        ];

        return [$blendedSpread, $blendedWinProbability, $blendedTotal];
    }

    /**
     * Anchor the model spread/total toward the market consensus when bookmaker
     * lines exist for this game. Defaults: 50% model / 50% market on spreads,
     * 60% model / 40% market on totals.
     *
     * @return array{0:float,1:float,2:float}
     */
    protected function applyMarketBlend(
        Game $game,
        float $predictedSpread,
        float $winProbability,
        float $predictedTotal
    ): array {
        $this->lastModelMetadata['market_blend'] = [
            'enabled' => (bool) config('nfl.predictions.market_blend.enabled', true),
            'applied' => false,
        ];

        if (! config('nfl.predictions.market_blend.enabled', true)) {
            $this->lastModelMetadata['market_blend']['reason'] = 'feature_disabled';

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $oddsData = $game->odds_data;
        if (is_string($oddsData)) {
            $oddsData = json_decode($oddsData, true);
        }
        if (! is_array($oddsData) || empty($oddsData['bookmakers'])) {
            $this->lastModelMetadata['market_blend']['reason'] = 'no_odds_data';

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        [$marketSpread, $marketTotal] = $this->extractMarketSpreadAndTotal($oddsData);

        if ($marketSpread === null && $marketTotal === null) {
            $this->lastModelMetadata['market_blend']['reason'] = 'no_spread_or_total_market';

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $spreadModelWeight = $this->clamp(
            (float) config('nfl.predictions.market_blend.spread_model_weight', 0.5),
            0.0,
            1.0
        );
        $totalModelWeight = $this->clamp(
            (float) config('nfl.predictions.market_blend.total_model_weight', 0.6),
            0.0,
            1.0
        );

        $blendedSpread = $predictedSpread;
        if ($marketSpread !== null) {
            $blendedSpread = ($predictedSpread * $spreadModelWeight) + ($marketSpread * (1 - $spreadModelWeight));
            $blendedSpread = $this->clamp(
                $blendedSpread,
                (float) config('nfl.predictions.min_spread'),
                (float) config('nfl.predictions.max_spread')
            );
        }

        $blendedTotal = $predictedTotal;
        if ($marketTotal !== null) {
            $blendedTotal = ($predictedTotal * $totalModelWeight) + ($marketTotal * (1 - $totalModelWeight));
            $blendedTotal = $this->clamp(
                $blendedTotal,
                (float) config('nfl.predictions.true_epa.min_predicted_total', 28.0),
                (float) config('nfl.predictions.true_epa.max_predicted_total', 66.0)
            );
        }

        $spreadCoefficient = (float) config('nfl.predictions.spread_to_probability_coefficient', 7.0);
        $blendedWinProbability = $this->clamp(
            1 / (1 + exp(-$blendedSpread / $spreadCoefficient)),
            0.01,
            0.99
        );

        $this->lastModelMetadata['market_blend'] = [
            'enabled' => true,
            'applied' => true,
            'spread_model_weight' => round($spreadModelWeight, 4),
            'total_model_weight' => round($totalModelWeight, 4),
            'market_spread' => $marketSpread !== null ? round($marketSpread, 2) : null,
            'market_total' => $marketTotal !== null ? round($marketTotal, 2) : null,
            'blended_spread' => round($blendedSpread, 2),
            'blended_total' => round($blendedTotal, 2),
        ];

        return [$blendedSpread, $blendedWinProbability, $blendedTotal];
    }

    protected function applyAdaptiveWinProbabilityCalibration(Game $game, float $winProbability): float
    {
        $enabled = (bool) config('nfl.predictions.adaptive_win_probability_calibration.enabled', true);
        $this->lastModelMetadata['adaptive_win_probability_calibration'] = [
            'enabled' => $enabled,
            'applied' => false,
            'baseline_win_probability' => round($winProbability, 6),
            'calibrated_win_probability' => round($winProbability, 6),
        ];

        if (! $enabled) {
            $this->lastModelMetadata['adaptive_win_probability_calibration']['reason'] = 'feature_disabled';

            return $winProbability;
        }

        $coinFlipTolerance = (float) config('nfl.predictions.adaptive_win_probability_calibration.coin_flip_tolerance', 0.0005);
        if (abs($winProbability - 0.5) <= $coinFlipTolerance) {
            $this->lastModelMetadata['adaptive_win_probability_calibration']['reason'] = 'coin_flip_probability';
            $this->lastModelMetadata['adaptive_win_probability_calibration']['coin_flip_tolerance'] = round($coinFlipTolerance, 6);

            return $winProbability;
        }

        $date = $this->asDate($game->game_date);
        if (! $date) {
            $this->lastModelMetadata['adaptive_win_probability_calibration']['reason'] = 'missing_game_date';

            return $winProbability;
        }

        $lookbackGames = max(1, (int) config('nfl.predictions.adaptive_win_probability_calibration.lookback_games', 512));
        $priorPredictions = Prediction::query()
            ->with('game')
            ->select('nfl_predictions.*')
            ->join('nfl_games', 'nfl_games.id', '=', 'nfl_predictions.game_id')
            ->where('nfl_games.status', 'STATUS_FINAL')
            ->whereNotNull('nfl_games.home_score')
            ->whereNotNull('nfl_games.away_score')
            ->whereDate('nfl_games.game_date', '<', $date->toDateString())
            ->whereNotNull('nfl_predictions.win_probability')
            ->orderByDesc('nfl_games.game_date')
            ->orderByDesc('nfl_games.id')
            ->limit($lookbackGames)
            ->get();

        if ($priorPredictions->isEmpty()) {
            $this->lastModelMetadata['adaptive_win_probability_calibration']['reason'] = 'no_prior_predictions';

            return $winProbability;
        }

        $bucketWidth = $this->clamp(
            (float) config('nfl.predictions.adaptive_win_probability_calibration.bucket_width', 0.05),
            0.01,
            0.25
        );
        $minBucketSample = max(1, (int) config('nfl.predictions.adaptive_win_probability_calibration.min_bucket_sample', 30));
        $confidence = max($winProbability, 1 - $winProbability);
        $bucketFloor = max(0.5, floor($confidence / $bucketWidth) * $bucketWidth);
        $bucketCeiling = min(1.0, $bucketFloor + $bucketWidth);

        $bucketPredictions = $priorPredictions
            ->filter(function (Prediction $prediction) use ($bucketFloor, $bucketCeiling): bool {
                $priorConfidence = max((float) $prediction->win_probability, 1 - (float) $prediction->win_probability);

                return $priorConfidence >= $bucketFloor && $priorConfidence < $bucketCeiling;
            })
            ->values();

        $calibrationRows = $bucketPredictions->count() >= $minBucketSample
            ? $bucketPredictions
            : $priorPredictions;
        $source = $bucketPredictions->count() >= $minBucketSample ? 'confidence_bucket' : 'lookback_overall';

        $actualFavoriteWinRate = $this->priorFavoriteWinRate($calibrationRows);
        if ($actualFavoriteWinRate === null) {
            $this->lastModelMetadata['adaptive_win_probability_calibration']['reason'] = 'missing_prior_results';

            return $winProbability;
        }

        $maxAdjustment = (float) config('nfl.predictions.adaptive_win_probability_calibration.max_adjustment', 0.08);
        $blendWeight = $this->clamp(
            (float) config('nfl.predictions.adaptive_win_probability_calibration.blend_weight', 0.45),
            0.0,
            1.0
        );
        $minimumFavoriteProbability = (float) config('nfl.predictions.adaptive_win_probability_calibration.min_favorite_probability', 0.501);
        $targetFavoriteProbability = $this->clamp($actualFavoriteWinRate, $minimumFavoriteProbability, 0.95);
        $adjustedFavoriteProbability = $confidence + (($targetFavoriteProbability - $confidence) * $blendWeight);
        $adjustedFavoriteProbability = $this->clamp(
            $adjustedFavoriteProbability,
            max($minimumFavoriteProbability, $confidence - $maxAdjustment),
            min(0.95, $confidence + $maxAdjustment)
        );
        $calibratedWinProbability = $winProbability >= 0.5
            ? $adjustedFavoriteProbability
            : 1 - $adjustedFavoriteProbability;
        $calibratedWinProbability = $this->clamp($calibratedWinProbability, 0.01, 0.99);

        $this->lastModelMetadata['adaptive_win_probability_calibration'] = [
            'enabled' => true,
            'applied' => true,
            'reason' => 'calibrated',
            'source' => $source,
            'lookback_games' => $priorPredictions->count(),
            'bucket_sample' => $bucketPredictions->count(),
            'sample_used' => $calibrationRows->count(),
            'bucket_floor' => round($bucketFloor, 3),
            'bucket_ceiling' => round($bucketCeiling, 3),
            'favorite_confidence' => round($confidence, 6),
            'actual_favorite_win_rate' => round($actualFavoriteWinRate, 6),
            'blend_weight' => round($blendWeight, 4),
            'max_adjustment' => round($maxAdjustment, 4),
            'min_favorite_probability' => round($minimumFavoriteProbability, 4),
            'baseline_win_probability' => round($winProbability, 6),
            'calibrated_win_probability' => round($calibratedWinProbability, 6),
        ];

        return $calibratedWinProbability;
    }

    protected function priorFavoriteWinRate(iterable $predictions): ?float
    {
        $count = 0;
        $wins = 0;

        foreach ($predictions as $prediction) {
            $game = $prediction->game;
            if (! $game || $game->home_score === null || $game->away_score === null) {
                continue;
            }

            $predictedHomeWin = (float) $prediction->win_probability >= 0.5;
            $homeWon = (float) $game->home_score > (float) $game->away_score;

            $count++;
            if ($predictedHomeWin === $homeWon) {
                $wins++;
            }
        }

        return $count > 0 ? $wins / $count : null;
    }

    /**
     * Extract the home-team spread (as home margin: positive = home favored)
     * and the over/under total from an odds_data payload. Returns null for
     * whichever market is absent.
     *
     * @param  array<string, mixed>  $oddsData
     * @return array{0:?float,1:?float}
     */
    protected function extractMarketSpreadAndTotal(array $oddsData): array
    {
        $homeTeamName = $oddsData['home_team'] ?? null;
        $marketSpread = null;
        $marketTotal = null;

        foreach ($oddsData['bookmakers'] ?? [] as $bookmaker) {
            foreach ($bookmaker['markets'] ?? [] as $market) {
                if ($market['key'] === 'spreads' && $homeTeamName !== null && $marketSpread === null) {
                    foreach ($market['outcomes'] ?? [] as $outcome) {
                        if (($outcome['name'] ?? null) === $homeTeamName && isset($outcome['point'])) {
                            // Bookmaker's "home -3.5" means home favored by 3.5;
                            // we represent home margin as positive when home is favored.
                            $marketSpread = -1 * (float) $outcome['point'];
                            break;
                        }
                    }
                }

                if ($market['key'] === 'totals' && $marketTotal === null) {
                    foreach ($market['outcomes'] ?? [] as $outcome) {
                        if (isset($outcome['point'])) {
                            $marketTotal = (float) $outcome['point'];
                            break;
                        }
                    }
                }
            }

            if ($marketSpread !== null && $marketTotal !== null) {
                break;
            }
        }

        return [$marketSpread, $marketTotal];
    }

    /**
     * @return array{0:float,1:float,2:float}
     */
    protected function applyDepthChartInjuryAdjustments(
        Game $game,
        float $predictedSpread,
        float $winProbability,
        float $predictedTotal
    ): array {
        $this->lastModelMetadata['depth_chart_injuries'] = [
            'enabled' => (bool) config('nfl.predictions.depth_chart_injuries.enabled', true),
            'applied' => false,
        ];

        if (! config('nfl.predictions.depth_chart_injuries.enabled', true)) {
            $this->lastModelMetadata['depth_chart_injuries']['reason'] = 'feature_disabled';

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $homeCounts = $this->injuryCountsForTeam((int) $game->home_team_id, (int) $game->season, $game);
        $awayCounts = $this->injuryCountsForTeam((int) $game->away_team_id, (int) $game->season, $game);

        $outSpreadPenalty = (float) config('nfl.predictions.injury_out_spread_penalty', 0.50);
        $questionableSpreadPenalty = (float) config('nfl.predictions.injury_questionable_spread_penalty', 0.20);
        $outTotalPenalty = (float) config('nfl.predictions.injury_out_total_penalty', 0.30);
        $questionableTotalPenalty = (float) config('nfl.predictions.injury_questionable_total_penalty', 0.10);

        $homePenalty = ($homeCounts['out'] * $outSpreadPenalty) + ($homeCounts['questionable'] * $questionableSpreadPenalty);
        $awayPenalty = ($awayCounts['out'] * $outSpreadPenalty) + ($awayCounts['questionable'] * $questionableSpreadPenalty);

        $spreadAdj = $awayPenalty - $homePenalty;
        $totalAdj = -(
            (($homeCounts['out'] + $awayCounts['out']) * $outTotalPenalty)
            + (($homeCounts['questionable'] + $awayCounts['questionable']) * $questionableTotalPenalty)
        );

        $winAdjPerPoint = (float) config('nfl.predictions.depth_chart.win_probability_adjustment_per_point', 0.03);

        $adjustedSpread = round($predictedSpread + $spreadAdj, 1);
        $adjustedTotal = round($predictedTotal + $totalAdj, 1);
        $adjustedWin = $this->clamp($winProbability + ($spreadAdj * $winAdjPerPoint), 0.01, 0.99);

        $this->lastModelMetadata['depth_chart_injuries'] = [
            'enabled' => true,
            'applied' => round($spreadAdj, 3) !== 0.0 || round($totalAdj, 3) !== 0.0,
            'home_out_weighted' => round($homeCounts['out'], 2),
            'away_out_weighted' => round($awayCounts['out'], 2),
            'home_questionable_weighted' => round($homeCounts['questionable'], 2),
            'away_questionable_weighted' => round($awayCounts['questionable'], 2),
            'home_scoped_out' => $homeCounts['scoped_out'],
            'away_scoped_out' => $awayCounts['scoped_out'],
            'home_returned_before_game' => $homeCounts['returned_before_game'],
            'away_returned_before_game' => $awayCounts['returned_before_game'],
            'home_unknown_return_skipped' => $homeCounts['unknown_return_skipped'],
            'away_unknown_return_skipped' => $awayCounts['unknown_return_skipped'],
            'unknown_return_days' => $homeCounts['unknown_return_days'],
            'has_unscoped_injury_uncertainty' => ($homeCounts['unknown_return_skipped'] + $awayCounts['unknown_return_skipped']) > 0,
            'spread_adjustment' => round($spreadAdj, 2),
            'total_adjustment' => round($totalAdj, 2),
            'win_probability_adjustment' => round($spreadAdj * $winAdjPerPoint, 4),
        ];

        return [$adjustedSpread, $adjustedWin, $adjustedTotal];
    }

    /**
     * @return array{out:float,questionable:float,scoped_out:int,returned_before_game:int,unknown_return_skipped:int,unknown_return_days:int}
     */
    protected function injuryCountsForTeam(int $teamId, int $season, Game $game): array
    {
        $counts = [
            'out' => 0.0,
            'questionable' => 0.0,
            'scoped_out' => 0,
            'returned_before_game' => 0,
            'unknown_return_skipped' => 0,
            'unknown_return_days' => (int) config('nfl.predictions.injury_scope.unknown_return_days', 21),
        ];

        if ($teamId <= 0) {
            return $counts;
        }

        $injuries = PlayerInjury::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->get(['player_id', 'status', 'return_date']);

        foreach ($injuries as $injury) {
            $bucket = $this->injuryStatusBucket((string) ($injury->status ?? ''));
            if ($bucket === null) {
                continue;
            }

            if (! $this->injuryAppliesToGame($injury, $game, $counts)) {
                continue;
            }

            $counts[$bucket] += $this->depthChartImpactService->injuryMultiplier(
                'nfl',
                $teamId,
                (int) ($injury->player_id ?? 0),
                $season
            );
        }

        $counts['out'] = round($counts['out'], 2);
        $counts['questionable'] = round($counts['questionable'], 2);

        return $counts;
    }

    /**
     * @param  array{out:float,questionable:float,scoped_out:int,returned_before_game:int,unknown_return_skipped:int,unknown_return_days:int}  $counts
     */
    protected function injuryAppliesToGame(PlayerInjury $injury, Game $game, array &$counts): bool
    {
        $gameDate = $this->asDate($game->game_date);
        if (! $gameDate) {
            return true;
        }

        $returnDate = $this->asDate($injury->return_date);
        if ($returnDate && $returnDate->lt($gameDate->startOfDay())) {
            $counts['returned_before_game']++;

            return false;
        }

        if ($returnDate) {
            return true;
        }

        $unknownReturnDays = max(0, (int) $counts['unknown_return_days']);
        $unknownReturnCutoff = now()->startOfDay()->addDays($unknownReturnDays);
        if ($gameDate->startOfDay()->gt($unknownReturnCutoff)) {
            $counts['unknown_return_skipped']++;

            return false;
        }

        $counts['scoped_out']++;

        return true;
    }

    protected function injuryStatusBucket(string $status): ?string
    {
        $normalized = strtolower(trim($status));

        return match (true) {
            $normalized === '' => null,
            str_contains($normalized, 'out'),
            str_contains($normalized, 'inactive'),
            str_contains($normalized, 'injured reserve'),
            str_contains($normalized, 'ir') => 'out',
            str_contains($normalized, 'questionable'),
            str_contains($normalized, 'doubtful'),
            str_contains($normalized, 'probable'),
            str_contains($normalized, 'day-to-day') => 'questionable',
            default => null,
        };
    }

    /**
     * @return array{games:int,avg_margin:float,recent_margin:float,yard_diff:float,turnover_diff:float,points_for:float,points_against:float}
     */
    protected function rollingEfficiencyProfile(Game $game, int $teamId): array
    {
        $date = $this->asDate($game->game_date);
        $recentGames = max(1, (int) config('nfl.predictions.rolling_efficiency.recent_games', 5));

        $games = Game::query()
            ->with('teamStats')
            ->where('season', (int) $game->season)
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->when($date, fn ($query) => $query->whereDate('game_date', '<', $date->toDateString()))
            ->where(function ($query) use ($teamId): void {
                $query->where('home_team_id', $teamId)
                    ->orWhere('away_team_id', $teamId);
            })
            ->orderBy('game_date')
            ->orderBy('id')
            ->get();

        if ($games->isEmpty()) {
            return [
                'games' => 0,
                'avg_margin' => 0.0,
                'recent_margin' => 0.0,
                'yard_diff' => 0.0,
                'turnover_diff' => 0.0,
                'points_for' => 0.0,
                'points_against' => 0.0,
            ];
        }

        $margins = [];
        $yardDiffs = [];
        $turnoverDiffs = [];
        $pointsFor = [];
        $pointsAgainst = [];

        foreach ($games as $priorGame) {
            $isHome = (int) $priorGame->home_team_id === $teamId;
            $opponentId = (int) ($isHome ? $priorGame->away_team_id : $priorGame->home_team_id);
            $teamScore = (float) ($isHome ? $priorGame->home_score : $priorGame->away_score);
            $opponentScore = (float) ($isHome ? $priorGame->away_score : $priorGame->home_score);

            $teamStat = $priorGame->teamStats->firstWhere('team_id', $teamId)
                ?? $this->statByTeamType($priorGame, $isHome ? 'home' : 'away');
            $opponentStat = $priorGame->teamStats->firstWhere('team_id', $opponentId)
                ?? $this->statByTeamType($priorGame, $isHome ? 'away' : 'home');

            $margins[] = $teamScore - $opponentScore;
            $pointsFor[] = $teamScore;
            $pointsAgainst[] = $opponentScore;

            if ($teamStat && $opponentStat) {
                $yardDiffs[] = (float) ($teamStat->total_yards ?? 0) - (float) ($opponentStat->total_yards ?? 0);
                $teamTurnovers = (float) ($teamStat->interceptions ?? 0) + (float) ($teamStat->fumbles_lost ?? $teamStat->fumbles ?? 0);
                $opponentTurnovers = (float) ($opponentStat->interceptions ?? 0) + (float) ($opponentStat->fumbles_lost ?? $opponentStat->fumbles ?? 0);
                $turnoverDiffs[] = $opponentTurnovers - $teamTurnovers;
            }
        }

        return [
            'games' => $games->count(),
            'avg_margin' => round($this->average($margins), 3),
            'recent_margin' => round($this->average(array_slice($margins, -$recentGames)), 3),
            'yard_diff' => round($this->average($yardDiffs), 3),
            'turnover_diff' => round($this->average($turnoverDiffs), 3),
            'points_for' => round($this->average($pointsFor), 3),
            'points_against' => round($this->average($pointsAgainst), 3),
        ];
    }

    protected function statByTeamType(Game $game, string $teamType): ?object
    {
        return $game->teamStats->first(fn ($stat) => strtolower((string) ($stat->team_type ?? '')) === $teamType);
    }

    /**
     * @return array{games:int,avg_margin:float,points_for:float,points_against:float}
     */
    protected function venueSplitProfile(Game $game, int $teamId, bool $homeSplit): array
    {
        $date = $this->asDate($game->game_date);
        $query = Game::query()
            ->where('season', (int) $game->season)
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->when($date, fn ($query) => $query->whereDate('game_date', '<', $date->toDateString()))
            ->orderBy('game_date')
            ->orderBy('id');

        $games = $homeSplit
            ? $query->where('home_team_id', $teamId)->get()
            : $query->where('away_team_id', $teamId)->get();

        $margins = [];
        $pointsFor = [];
        $pointsAgainst = [];
        foreach ($games as $priorGame) {
            $teamScore = (float) ($homeSplit ? $priorGame->home_score : $priorGame->away_score);
            $opponentScore = (float) ($homeSplit ? $priorGame->away_score : $priorGame->home_score);
            $margins[] = $teamScore - $opponentScore;
            $pointsFor[] = $teamScore;
            $pointsAgainst[] = $opponentScore;
        }

        return [
            'games' => $games->count(),
            'avg_margin' => round($this->average($margins), 3),
            'points_for' => round($this->average($pointsFor), 3),
            'points_against' => round($this->average($pointsAgainst), 3),
        ];
    }

    /**
     * @return array{games:int,home_avg_margin:float,avg_total:float}
     */
    protected function headToHeadProfile(Game $game, int $lookback): array
    {
        $date = $this->asDate($game->game_date);
        $homeTeamId = (int) $game->home_team_id;
        $awayTeamId = (int) $game->away_team_id;

        $games = Game::query()
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->when($date, fn ($query) => $query->whereDate('game_date', '<', $date->toDateString()))
            ->where(function ($query) use ($homeTeamId, $awayTeamId): void {
                $query->where(function ($query) use ($homeTeamId, $awayTeamId): void {
                    $query->where('home_team_id', $homeTeamId)->where('away_team_id', $awayTeamId);
                })->orWhere(function ($query) use ($homeTeamId, $awayTeamId): void {
                    $query->where('home_team_id', $awayTeamId)->where('away_team_id', $homeTeamId);
                });
            })
            ->orderByDesc('game_date')
            ->orderByDesc('id')
            ->limit($lookback)
            ->get();

        $homeMargins = [];
        $totals = [];
        foreach ($games as $priorGame) {
            $homePerspectiveMargin = (int) $priorGame->home_team_id === $homeTeamId
                ? (float) $priorGame->home_score - (float) $priorGame->away_score
                : (float) $priorGame->away_score - (float) $priorGame->home_score;
            $homeMargins[] = $homePerspectiveMargin;
            $totals[] = (float) $priorGame->home_score + (float) $priorGame->away_score;
        }

        return [
            'games' => $games->count(),
            'home_avg_margin' => round($this->average($homeMargins), 3),
            'avg_total' => round($this->average($totals), 3),
        ];
    }

    /**
     * @return array{rest_days:?int,previous_game_date:?string,consecutive_road_games:int}
     */
    protected function scheduleProfile(Game $game, int $teamId): array
    {
        $date = $this->asDate($game->game_date);
        if (! $date) {
            return [
                'rest_days' => null,
                'previous_game_date' => null,
                'consecutive_road_games' => 0,
            ];
        }

        $previousGames = Game::query()
            ->where('status', 'STATUS_FINAL')
            ->whereDate('game_date', '<', $date->toDateString())
            ->where(function ($query) use ($teamId): void {
                $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->orderByDesc('game_date')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        $previousGame = $previousGames->first();
        $restDays = $previousGame?->game_date
            ? max(0, $this->asDate($previousGame->game_date)?->diffInDays($date) - 1)
            : null;
        $consecutiveRoad = 0;
        foreach ($previousGames as $priorGame) {
            if ((int) $priorGame->away_team_id !== $teamId) {
                break;
            }
            $consecutiveRoad++;
        }

        return [
            'rest_days' => $restDays,
            'previous_game_date' => $previousGame?->game_date?->toDateString(),
            'consecutive_road_games' => $consecutiveRoad,
        ];
    }

    protected function divisionKey(mixed $team): ?string
    {
        if (! $team) {
            return null;
        }

        $conference = strtolower(trim((string) ($team->conference ?? '')));
        $division = strtolower(trim((string) ($team->division ?? '')));

        return $conference !== '' && $division !== '' ? $conference.'-'.$division : null;
    }

    protected function isIndoorVenue(Game $game): bool
    {
        $venue = strtolower((string) ($game->venue_name ?? ''));
        foreach ((array) config('nfl.predictions.contextual_factors.indoor_venue_keywords', []) as $keyword) {
            if ($keyword !== '' && str_contains($venue, strtolower((string) $keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{games:int,off_sack_allowed_rate:float,off_rush_yards_per_attempt:float,def_sack_rate:float,def_rush_yards_allowed_per_attempt:float,off_pass_attempts:int,off_rush_attempts:int,def_pass_attempts:int,def_rush_attempts:int}
     */
    protected function lineProfile(Game $game, int $teamId): array
    {
        $date = $this->asDate($game->game_date);

        $games = Game::query()
            ->with('teamStats')
            ->where('season', (int) $game->season)
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->when($date, fn ($query) => $query->whereDate('game_date', '<', $date->toDateString()))
            ->where(function ($query) use ($teamId): void {
                $query->where('home_team_id', $teamId)
                    ->orWhere('away_team_id', $teamId);
            })
            ->orderBy('game_date')
            ->orderBy('id')
            ->get();

        $gamesWithStats = 0;
        $offSacksAllowed = 0;
        $offPassAttempts = 0;
        $offRushYards = 0;
        $offRushAttempts = 0;
        $defSacks = 0;
        $defPassAttempts = 0;
        $defRushYardsAllowed = 0;
        $defRushAttempts = 0;

        foreach ($games as $priorGame) {
            $isHome = (int) $priorGame->home_team_id === $teamId;
            $opponentId = (int) ($isHome ? $priorGame->away_team_id : $priorGame->home_team_id);
            $teamStat = $priorGame->teamStats->firstWhere('team_id', $teamId)
                ?? $this->statByTeamType($priorGame, $isHome ? 'home' : 'away');
            $opponentStat = $priorGame->teamStats->firstWhere('team_id', $opponentId)
                ?? $this->statByTeamType($priorGame, $isHome ? 'away' : 'home');

            if (! $teamStat || ! $opponentStat) {
                continue;
            }

            $gamesWithStats++;
            $offSacksAllowed += (int) ($teamStat->sacks_allowed ?? 0);
            $offPassAttempts += (int) ($teamStat->passing_attempts ?? 0);
            $offRushYards += (int) ($teamStat->rushing_yards ?? 0);
            $offRushAttempts += (int) ($teamStat->rushing_attempts ?? 0);

            $defSacks += (int) ($opponentStat->sacks_allowed ?? 0);
            $defPassAttempts += (int) ($opponentStat->passing_attempts ?? 0);
            $defRushYardsAllowed += (int) ($opponentStat->rushing_yards ?? 0);
            $defRushAttempts += (int) ($opponentStat->rushing_attempts ?? 0);
        }

        return [
            'games' => $gamesWithStats,
            'off_sack_allowed_rate' => round($offPassAttempts > 0 ? $offSacksAllowed / $offPassAttempts : 0.0, 4),
            'off_rush_yards_per_attempt' => round($offRushAttempts > 0 ? $offRushYards / $offRushAttempts : 0.0, 3),
            'def_sack_rate' => round($defPassAttempts > 0 ? $defSacks / $defPassAttempts : 0.0, 4),
            'def_rush_yards_allowed_per_attempt' => round($defRushAttempts > 0 ? $defRushYardsAllowed / $defRushAttempts : 0.0, 3),
            'off_pass_attempts' => $offPassAttempts,
            'off_rush_attempts' => $offRushAttempts,
            'def_pass_attempts' => $defPassAttempts,
            'def_rush_attempts' => $defRushAttempts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function qbContextForGame(Game $game, int $teamId): array
    {
        $qbStat = PlayerStat::query()
            ->with('player')
            ->where('game_id', (int) $game->id)
            ->where('team_id', $teamId)
            ->where('passing_attempts', '>', 0)
            ->orderByDesc('passing_attempts')
            ->orderByDesc('passing_yards')
            ->first();

        if (! $qbStat) {
            return [
                'qb_id' => null,
                'reason' => 'no_passing_line_for_game',
            ];
        }

        $prior = $this->priorQbStats((int) $qbStat->player_id, $teamId, $game);
        $score = $this->qbScore($prior);
        $experience = is_numeric($qbStat->player?->experience ?? null) ? (int) $qbStat->player->experience : null;

        return [
            'qb_id' => (int) $qbStat->player_id,
            'qb_name' => $qbStat->player?->full_name,
            'experience' => $experience,
            'experience_bucket' => $this->qbExperienceBucket($experience, (int) $prior['games']),
            'game_attempts' => (int) ($qbStat->passing_attempts ?? 0),
            'prior_games' => (int) $prior['games'],
            'prior_attempts' => (int) $prior['attempts'],
            'prior_yards_per_attempt' => round((float) $prior['yards_per_attempt'], 3),
            'prior_td_rate' => round((float) $prior['td_rate'], 4),
            'prior_int_rate' => round((float) $prior['int_rate'], 4),
            'prior_sack_rate' => round((float) $prior['sack_rate'], 4),
            'prior_rush_yards_per_game' => round((float) $prior['rush_yards_per_game'], 3),
            'score' => round($score, 3),
        ];
    }

    /**
     * @return array{games:int,attempts:int,yards:int,touchdowns:int,interceptions:int,sacks:int,rush_yards:int,yards_per_attempt:float,td_rate:float,int_rate:float,sack_rate:float,rush_yards_per_game:float}
     */
    protected function priorQbStats(int $playerId, int $teamId, Game $game): array
    {
        $date = $this->asDate($game->game_date);

        $rows = PlayerStat::query()
            ->join('nfl_games', 'nfl_games.id', '=', 'nfl_player_stats.game_id')
            ->where('nfl_player_stats.player_id', $playerId)
            ->where('nfl_player_stats.team_id', $teamId)
            ->where('nfl_player_stats.passing_attempts', '>', 0)
            ->when($date, fn ($query) => $query->whereDate('nfl_games.game_date', '<', $date->toDateString()))
            ->where('nfl_games.status', 'STATUS_FINAL')
            ->where('nfl_games.season', (int) $game->season)
            ->get([
                'nfl_player_stats.passing_attempts',
                'nfl_player_stats.passing_yards',
                'nfl_player_stats.passing_touchdowns',
                'nfl_player_stats.interceptions_thrown',
                'nfl_player_stats.sacks_taken',
                'nfl_player_stats.rushing_yards',
            ]);

        $games = $rows->count();
        $attempts = (int) $rows->sum(fn ($row) => (int) ($row->passing_attempts ?? 0));
        $yards = (int) $rows->sum(fn ($row) => (int) ($row->passing_yards ?? 0));
        $touchdowns = (int) $rows->sum(fn ($row) => (int) ($row->passing_touchdowns ?? 0));
        $interceptions = (int) $rows->sum(fn ($row) => (int) ($row->interceptions_thrown ?? 0));
        $sacks = (int) $rows->sum(fn ($row) => (int) ($row->sacks_taken ?? 0));
        $rushYards = (int) $rows->sum(fn ($row) => (int) ($row->rushing_yards ?? 0));
        $dropbacks = $attempts + $sacks;

        return [
            'games' => $games,
            'attempts' => $attempts,
            'yards' => $yards,
            'touchdowns' => $touchdowns,
            'interceptions' => $interceptions,
            'sacks' => $sacks,
            'rush_yards' => $rushYards,
            'yards_per_attempt' => $attempts > 0 ? $yards / $attempts : 0.0,
            'td_rate' => $attempts > 0 ? $touchdowns / $attempts : 0.0,
            'int_rate' => $attempts > 0 ? $interceptions / $attempts : 0.0,
            'sack_rate' => $dropbacks > 0 ? $sacks / $dropbacks : 0.0,
            'rush_yards_per_game' => $games > 0 ? $rushYards / $games : 0.0,
        ];
    }

    /**
     * @param  array{games:int,attempts:int,yards:int,touchdowns:int,interceptions:int,sacks:int,rush_yards:int,yards_per_attempt:float,td_rate:float,int_rate:float,sack_rate:float,rush_yards_per_game:float}  $prior
     */
    protected function qbScore(array $prior): float
    {
        $ypaDelta = (float) $prior['yards_per_attempt'] - (float) config('nfl.predictions.qb_form.baseline_yards_per_attempt', 6.9);
        $tdDelta = (float) $prior['td_rate'] - (float) config('nfl.predictions.qb_form.baseline_td_rate', 0.045);
        $intDelta = (float) $prior['int_rate'] - (float) config('nfl.predictions.qb_form.baseline_int_rate', 0.025);
        $sackDelta = (float) $prior['sack_rate'] - (float) config('nfl.predictions.qb_form.baseline_sack_rate', 0.065);

        $score = ($ypaDelta * (float) config('nfl.predictions.qb_form.ypa_weight', 1.2))
            + ($tdDelta * (float) config('nfl.predictions.qb_form.td_rate_weight', 28.0))
            - ($intDelta * (float) config('nfl.predictions.qb_form.int_rate_weight', 35.0))
            - ($sackDelta * (float) config('nfl.predictions.qb_form.sack_rate_weight', 18.0))
            + ((float) $prior['rush_yards_per_game'] * (float) config('nfl.predictions.qb_form.rush_yards_weight', 0.03));

        $maxQbScore = (float) config('nfl.predictions.qb_form.max_qb_score', 4.0);

        return $this->clamp($score, -$maxQbScore, $maxQbScore);
    }

    /**
     * @param  array<string, mixed>  $home
     * @param  array<string, mixed>  $away
     */
    protected function qbFormSampleWeight(array $home, array $away): float
    {
        $fullWeightAttempts = max(1, (int) config('nfl.predictions.qb_form.full_weight_attempts', 30));
        $fullWeightGames = max(1, (int) config('nfl.predictions.qb_form.full_weight_games', 1));
        $lowestAttempts = min((int) ($home['prior_attempts'] ?? 0), (int) ($away['prior_attempts'] ?? 0));
        $lowestGames = min((int) ($home['prior_games'] ?? 0), (int) ($away['prior_games'] ?? 0));

        return $this->clamp(min(
            $lowestAttempts / $fullWeightAttempts,
            $lowestGames / $fullWeightGames
        ), 0.0, 1.0);
    }

    protected function qbFormEarlySeasonMultiplier(Game $game): float
    {
        $week = (int) ($game->week ?? 0);
        $earlySeasonWeek = max(0, (int) config('nfl.predictions.qb_form.early_season_week', 4));

        if ($week > 0 && $earlySeasonWeek > 0 && $week <= $earlySeasonWeek) {
            return $this->clamp((float) config('nfl.predictions.qb_form.early_season_weight', 1.0), 0.0, 1.0);
        }

        return 1.0;
    }

    protected function qbExperienceBucket(?int $experience, int $priorGames): string
    {
        if ($experience === null) {
            return $priorGames <= 3 ? 'unknown_limited_starter' : 'unknown';
        }

        return match (true) {
            $experience <= 0 => 'rookie',
            $experience === 1 || $priorGames < 12 => 'first_year_starter',
            $experience >= 8 => 'elite_veteran',
            $experience >= 3 => 'veteran',
            default => 'developing',
        };
    }

    protected function qbExperienceScore(string $bucket): float
    {
        return match ($bucket) {
            'rookie' => -1.0,
            'first_year_starter' => -0.45,
            'developing' => -0.15,
            'veteran' => 0.35,
            'elite_veteran' => 0.75,
            default => 0.0,
        };
    }

    protected function applyAnalysisLayer(Game $game, float $predictedSpread, float $predictedTotal, float $winProbability): void
    {
        $enabled = (bool) config('nfl.predictions.analysis_layer.enabled', true);
        $analysis = [
            'enabled' => $enabled,
            'applied' => false,
        ];

        if (! $enabled) {
            $analysis['reason'] = 'feature_disabled';
            $this->lastModelMetadata['analysis_layer'] = $analysis;

            return;
        }

        [$marketSpread, $marketTotal] = $this->extractMarketSpreadAndTotalFromGame($game);
        $spreadEdge = $marketSpread !== null ? $predictedSpread - $marketSpread : null;
        $totalEdge = $marketTotal !== null ? $predictedTotal - $marketTotal : null;
        $riskFlags = $this->analysisRiskFlags($game, $winProbability, $spreadEdge, $totalEdge);
        $trustScore = $this->analysisTrustScore($winProbability, $riskFlags, $spreadEdge, $totalEdge);
        $betClassification = $this->betClassification($trustScore, $spreadEdge, $totalEdge);
        $modelSignalClassification = $this->modelSignalClassification($trustScore);
        $reasonCodes = $this->analysisReasonCodes($game, $winProbability, $trustScore, $spreadEdge, $totalEdge);

        $this->lastModelMetadata['analysis_layer'] = [
            'enabled' => true,
            'applied' => true,
            'trust_score' => round($trustScore, 1),
            'risk_flags' => $riskFlags,
            'bet_classification' => $betClassification,
            'model_signal_classification' => $modelSignalClassification,
            'reason_codes' => $reasonCodes,
            'calculated_edge' => [
                'spread_points' => $spreadEdge !== null ? round($spreadEdge, 3) : null,
                'total_points' => $totalEdge !== null ? round($totalEdge, 3) : null,
                'market_spread' => $marketSpread !== null ? round($marketSpread, 3) : null,
                'market_total' => $marketTotal !== null ? round($marketTotal, 3) : null,
            ],
            'analysis_confidence' => [
                'win_probability' => round($winProbability, 6),
                'favorite_confidence' => round(max($winProbability, 1 - $winProbability), 6),
                'trust_score' => round($trustScore, 1),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    protected function analysisRiskFlags(Game $game, float $winProbability, ?float $spreadEdge, ?float $totalEdge): array
    {
        $flags = [];
        $confidence = max($winProbability, 1 - $winProbability);

        if ($confidence < (float) config('nfl.predictions.analysis_layer.low_confidence_threshold', 0.58)) {
            $flags[] = 'low_model_confidence';
        }

        if ($spreadEdge === null && $totalEdge === null) {
            $flags[] = 'missing_market_edge';
        }

        if (($this->lastModelMetadata['contextual_factors']['division_rivalry']['is_division_game'] ?? false) === true) {
            $flags[] = 'division_rivalry';
        }

        foreach (['home', 'away'] as $side) {
            $bucket = $this->lastModelMetadata['qb_form'][$side]['experience_bucket'] ?? null;
            if (in_array($bucket, ['rookie', 'first_year_starter', 'unknown_limited_starter'], true)) {
                $flags[] = $side.'_qb_experience_risk';
            }
        }

        if (abs((float) ($this->lastModelMetadata['contextual_factors']['weather_total']['total_adjustment'] ?? 0.0)) >= 1.0) {
            $flags[] = 'weather_total_risk';
        }

        if (($this->lastModelMetadata['adaptive_win_probability_calibration']['applied'] ?? false) === true
            && abs((float) ($this->lastModelMetadata['adaptive_win_probability_calibration']['calibrated_win_probability'] ?? $winProbability) - (float) ($this->lastModelMetadata['adaptive_win_probability_calibration']['baseline_win_probability'] ?? $winProbability)) >= 0.03) {
            $flags[] = 'adaptive_calibration_moved_probability';
        }

        return array_values(array_unique($flags));
    }

    /**
     * @return list<string>
     */
    protected function analysisReasonCodes(Game $game, float $winProbability, float $trustScore, ?float $spreadEdge, ?float $totalEdge): array
    {
        $codes = [];
        $favoriteSide = $winProbability >= 0.5 ? 'home' : 'away';
        $favoriteConfidence = max($winProbability, 1 - $winProbability);

        if ($trustScore >= (float) config('nfl.predictions.analysis_layer.strong_model_signal_threshold', 65.0)) {
            $codes[] = 'strong_model_signal';
        } elseif ($trustScore >= (float) config('nfl.predictions.analysis_layer.lean_model_signal_threshold', 55.0)) {
            $codes[] = 'lean_model_signal';
        } else {
            $codes[] = 'pass_model_signal';
        }

        if ($favoriteConfidence >= 0.75) {
            $codes[] = 'high_favorite_confidence';
        } elseif ($favoriteConfidence >= 0.65) {
            $codes[] = 'solid_favorite_confidence';
        } elseif ($favoriteConfidence < (float) config('nfl.predictions.analysis_layer.low_confidence_threshold', 0.58)) {
            $codes[] = 'low_favorite_confidence';
        }

        if (($this->lastModelMetadata['rolling_efficiency']['applied'] ?? false) === true) {
            $codes[] = 'rolling_efficiency_signal';
            $rollingSpread = (float) ($this->lastModelMetadata['rolling_efficiency']['signal_spread'] ?? 0.0);
            $this->appendDirectionalReason($codes, 'rolling_efficiency', $rollingSpread, 2.0);
            $homeGames = (int) ($this->lastModelMetadata['rolling_efficiency']['home']['games'] ?? 0);
            $awayGames = (int) ($this->lastModelMetadata['rolling_efficiency']['away']['games'] ?? 0);
            if (min($homeGames, $awayGames) >= 5) {
                $codes[] = 'rolling_efficiency_mature_sample';
            } else {
                $codes[] = 'rolling_efficiency_small_sample';
            }
        }

        if (($this->lastModelMetadata['qb_form']['applied'] ?? false) === true) {
            $codes[] = 'qb_form_signal';
            $qbSignal = (float) ($this->lastModelMetadata['qb_form']['signal_spread'] ?? 0.0);
            $this->appendDirectionalReason($codes, 'qb_form', $qbSignal, 1.0);
            $experienceSignal = (float) ($this->lastModelMetadata['qb_form']['experience_signal_spread'] ?? 0.0);
            $this->appendDirectionalReason($codes, 'qb_experience', $experienceSignal, 0.2);
            $homeBucket = (string) ($this->lastModelMetadata['qb_form']['home']['experience_bucket'] ?? 'unknown');
            $awayBucket = (string) ($this->lastModelMetadata['qb_form']['away']['experience_bucket'] ?? 'unknown');
            $codes[] = 'home_qb_'.$homeBucket;
            $codes[] = 'away_qb_'.$awayBucket;
            if ($homeBucket !== 'unknown' && $awayBucket !== 'unknown') {
                $codes[] = $favoriteSide.'_qb_profile_supports_pick';
            }
        }

        if (($this->lastModelMetadata['line_matchup']['applied'] ?? false) === true) {
            $codes[] = 'ol_dl_matchup_signal';
            $lineSignal = (float) ($this->lastModelMetadata['line_matchup']['signal_spread'] ?? 0.0);
            $this->appendDirectionalReason($codes, 'trench_matchup', $lineSignal, 0.75);
            $homePressure = (float) ($this->lastModelMetadata['line_matchup']['home_pressure_edge'] ?? 0.0);
            $awayPressure = (float) ($this->lastModelMetadata['line_matchup']['away_pressure_edge'] ?? 0.0);
            if ($homePressure < $awayPressure) {
                $codes[] = 'home_pass_protection_edge';
            } elseif ($awayPressure < $homePressure) {
                $codes[] = 'away_pass_protection_edge';
            }
            $homeRun = (float) ($this->lastModelMetadata['line_matchup']['home_run_edge'] ?? 0.0);
            $awayRun = (float) ($this->lastModelMetadata['line_matchup']['away_run_edge'] ?? 0.0);
            if ($homeRun - $awayRun >= 0.5) {
                $codes[] = 'home_run_game_edge';
            } elseif ($awayRun - $homeRun >= 0.5) {
                $codes[] = 'away_run_game_edge';
            }
        }

        if (($this->lastModelMetadata['contextual_factors']['applied'] ?? false) === true) {
            $codes[] = 'contextual_adjustments';
            $contextSpread = (float) ($this->lastModelMetadata['contextual_factors']['spread_adjustment'] ?? 0.0);
            $this->appendDirectionalReason($codes, 'context_spread', $contextSpread, 0.5);

            if (($this->lastModelMetadata['contextual_factors']['home_away_strength']['applied'] ?? false) === true) {
                $codes[] = 'home_away_split_signal';
            }
            if (($this->lastModelMetadata['contextual_factors']['division_rivalry']['is_division_game'] ?? false) === true) {
                $codes[] = 'division_rivalry_context';
            }
            if (($this->lastModelMetadata['contextual_factors']['weather_total']['applied'] ?? false) === true) {
                $codes[] = 'weather_total_context';
            }
            if (($this->lastModelMetadata['contextual_factors']['schedule_spot']['applied'] ?? false) === true) {
                $codes[] = 'rest_travel_schedule_context';
            }
            if (($this->lastModelMetadata['contextual_factors']['coaching_prior']['applied'] ?? false) === true) {
                $codes[] = 'coaching_prior_context';
            }
        }

        if (($this->lastModelMetadata['adaptive_win_probability_calibration']['applied'] ?? false) === true) {
            $codes[] = 'adaptive_calibration_signal';
            $baseline = (float) ($this->lastModelMetadata['adaptive_win_probability_calibration']['baseline_win_probability'] ?? $winProbability);
            $calibrated = (float) ($this->lastModelMetadata['adaptive_win_probability_calibration']['calibrated_win_probability'] ?? $winProbability);
            if (max($calibrated, 1 - $calibrated) > max($baseline, 1 - $baseline)) {
                $codes[] = 'calibration_increased_confidence';
            } elseif (max($calibrated, 1 - $calibrated) < max($baseline, 1 - $baseline)) {
                $codes[] = 'calibration_reduced_confidence';
            }
        }

        if ($spreadEdge !== null && abs($spreadEdge) >= (float) config('nfl.predictions.analysis_layer.min_spread_edge', 2.0)) {
            $codes[] = 'spread_market_edge';
            $codes[] = $spreadEdge > 0 ? 'market_spread_edge_home' : 'market_spread_edge_away';
        }
        if ($totalEdge !== null && abs($totalEdge) >= (float) config('nfl.predictions.analysis_layer.min_total_edge', 3.0)) {
            $codes[] = 'total_market_edge';
            $codes[] = $totalEdge > 0 ? 'market_total_edge_over' : 'market_total_edge_under';
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  list<string>  $codes
     */
    protected function appendDirectionalReason(array &$codes, string $prefix, float $spreadSignal, float $threshold): void
    {
        if ($spreadSignal >= $threshold) {
            $codes[] = $prefix.'_home_edge';
        } elseif ($spreadSignal <= -$threshold) {
            $codes[] = $prefix.'_away_edge';
        }
    }

    /**
     * @param  list<string>  $riskFlags
     */
    protected function analysisTrustScore(float $winProbability, array $riskFlags, ?float $spreadEdge, ?float $totalEdge): float
    {
        $confidence = max($winProbability, 1 - $winProbability);
        $score = 50.0 + (($confidence - 0.5) * 100.0);
        if ($spreadEdge !== null) {
            $score += min(12.0, abs($spreadEdge) * 2.0);
        }
        if ($totalEdge !== null) {
            $score += min(8.0, abs($totalEdge) * 1.2);
        }

        $score -= count($riskFlags) * (float) config('nfl.predictions.analysis_layer.risk_flag_penalty', 4.0);

        return $this->clamp($score, 0.0, 100.0);
    }

    protected function betClassification(float $trustScore, ?float $spreadEdge, ?float $totalEdge): string
    {
        $hasEdge = ($spreadEdge !== null && abs($spreadEdge) >= (float) config('nfl.predictions.analysis_layer.min_spread_edge', 2.0))
            || ($totalEdge !== null && abs($totalEdge) >= (float) config('nfl.predictions.analysis_layer.min_total_edge', 3.0));

        return match (true) {
            ! $hasEdge => 'no_bet_no_edge',
            $trustScore >= 78 => 'bet',
            $trustScore >= 66 => 'lean',
            default => 'no_bet_risk',
        };
    }

    protected function modelSignalClassification(float $trustScore): string
    {
        return match (true) {
            $trustScore >= (float) config('nfl.predictions.analysis_layer.strong_model_signal_threshold', 65.0) => 'strong_model_side',
            $trustScore >= (float) config('nfl.predictions.analysis_layer.lean_model_signal_threshold', 55.0) => 'lean_model_side',
            default => 'pass_model_side',
        };
    }

    /**
     * @return array{0:?float,1:?float}
     */
    protected function extractMarketSpreadAndTotalFromGame(Game $game): array
    {
        $oddsData = $game->odds_data;
        if (is_string($oddsData)) {
            $oddsData = json_decode($oddsData, true);
        }

        return is_array($oddsData) ? $this->extractMarketSpreadAndTotal($oddsData) : [null, null];
    }

    protected function getEloAtDate(int $teamId, mixed $gameDate): float
    {
        $date = $this->asDate($gameDate);

        $eloRecord = EloRating::query()
            ->where('team_id', $teamId)
            ->when($date, fn ($query) => $query->whereDate('date', '<', $date->toDateString()))
            ->orderBy('date', 'desc')
            ->first();

        return $eloRecord ? (float) $eloRecord->elo_rating : config('nfl.elo.default_rating');
    }

    protected function calculateWinProbability(float $ratingA, float $ratingB): float
    {
        return 1 / (1 + pow(10, ($ratingB - $ratingA) / 400));
    }

    protected function blend(float $legacy, float $epaBased, float $weight): float
    {
        return ($legacy * (1 - $weight)) + ($epaBased * $weight);
    }

    /**
     * @param  array<int, float|int>  $values
     */
    protected function average(array $values): float
    {
        $values = array_values(array_filter($values, fn ($value) => is_numeric($value)));

        return $values === [] ? 0.0 : (array_sum($values) / count($values));
    }

    protected function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    protected function asDate(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy();
        }

        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
