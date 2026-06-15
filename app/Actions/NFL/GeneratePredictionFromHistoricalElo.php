<?php

namespace App\Actions\NFL;

use App\Models\GameOddsSnapshot;
use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Prediction;
use App\Models\NFL\TeamMetric;
use App\Services\NFL\PlayerPositionGradeService;
use App\Services\Sports\DepthChartImpactService;
use App\Support\NflBetRuleEngine;
use App\Support\NflReasonCodeCatalog;
use App\Support\NflValidatedSignalCombos;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class GeneratePredictionFromHistoricalElo
{
    /**
     * Fallback NFL alignment map for historical rows where ESPN omitted conference/division.
     *
     * @var array<string,array{conference:string,division:string}>
     */
    protected const NFL_DIVISION_MAP = [
        'ARI' => ['conference' => 'nfc', 'division' => 'west'],
        'ATL' => ['conference' => 'nfc', 'division' => 'south'],
        'BAL' => ['conference' => 'afc', 'division' => 'north'],
        'BUF' => ['conference' => 'afc', 'division' => 'east'],
        'CAR' => ['conference' => 'nfc', 'division' => 'south'],
        'CHI' => ['conference' => 'nfc', 'division' => 'north'],
        'CIN' => ['conference' => 'afc', 'division' => 'north'],
        'CLE' => ['conference' => 'afc', 'division' => 'north'],
        'DAL' => ['conference' => 'nfc', 'division' => 'east'],
        'DEN' => ['conference' => 'afc', 'division' => 'west'],
        'DET' => ['conference' => 'nfc', 'division' => 'north'],
        'GB' => ['conference' => 'nfc', 'division' => 'north'],
        'HOU' => ['conference' => 'afc', 'division' => 'south'],
        'IND' => ['conference' => 'afc', 'division' => 'south'],
        'JAX' => ['conference' => 'afc', 'division' => 'south'],
        'KC' => ['conference' => 'afc', 'division' => 'west'],
        'LAC' => ['conference' => 'afc', 'division' => 'west'],
        'LAR' => ['conference' => 'nfc', 'division' => 'west'],
        'LV' => ['conference' => 'afc', 'division' => 'west'],
        'MIA' => ['conference' => 'afc', 'division' => 'east'],
        'MIN' => ['conference' => 'nfc', 'division' => 'north'],
        'NE' => ['conference' => 'afc', 'division' => 'east'],
        'NO' => ['conference' => 'nfc', 'division' => 'south'],
        'NYG' => ['conference' => 'nfc', 'division' => 'east'],
        'NYJ' => ['conference' => 'afc', 'division' => 'east'],
        'PHI' => ['conference' => 'nfc', 'division' => 'east'],
        'PIT' => ['conference' => 'afc', 'division' => 'north'],
        'SEA' => ['conference' => 'nfc', 'division' => 'west'],
        'SF' => ['conference' => 'nfc', 'division' => 'west'],
        'TB' => ['conference' => 'nfc', 'division' => 'south'],
        'TEN' => ['conference' => 'afc', 'division' => 'south'],
        'WAS' => ['conference' => 'nfc', 'division' => 'east'],
        'WSH' => ['conference' => 'nfc', 'division' => 'east'],
    ];

    /**
     * @var array<string,mixed>
     */
    protected array $lastModelMetadata = [];

    /**
     * @var array<string,array<string,mixed>>
     */
    protected array $playerPositionGradeCache = [];

    public function __construct(
        protected ?DepthChartImpactService $depthChartImpactService = null,
        protected ?PlayerPositionGradeService $playerPositionGradeService = null,
        protected ?NflReasonCodeCatalog $reasonCodeCatalog = null,
    ) {
        $this->depthChartImpactService ??= app(DepthChartImpactService::class);
        $this->playerPositionGradeService ??= app(PlayerPositionGradeService::class);
        $this->reasonCodeCatalog ??= app(NflReasonCodeCatalog::class);
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
        [$predictedSpread, $winProbability, $predictedTotal] = $this->applyOpponentAdjustedEfficiencyBlend(
            $game,
            $predictedSpread,
            $winProbability,
            $predictedTotal
        );
        $predictedTotal = $this->applyTotalEnvironmentBlend(
            $game,
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
        [$predictedSpread, $winProbability, $predictedTotal] = $this->applyActualWeatherBlend(
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
        [$predictedSpread, $winProbability, $predictedTotal] = $this->applyAdaptivePointCalibration(
            $game,
            $predictedSpread,
            $winProbability,
            $predictedTotal
        );
        $this->applyPlayerPositionGradeContext($game);
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
     * Opponent-adjusted form rewards production against strong opponents and
     * discounts noisy blowouts against weak ones.
     *
     * @return array{0:float,1:float,2:float}
     */
    protected function applyOpponentAdjustedEfficiencyBlend(
        Game $game,
        float $predictedSpread,
        float $winProbability,
        float $predictedTotal
    ): array {
        $this->lastModelMetadata['opponent_adjusted_efficiency'] = [
            'enabled' => (bool) config('nfl.predictions.opponent_adjusted_efficiency.enabled', true),
            'applied' => false,
        ];

        if (! config('nfl.predictions.opponent_adjusted_efficiency.enabled', true)) {
            $this->lastModelMetadata['opponent_adjusted_efficiency']['reason'] = 'feature_disabled';

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $home = $this->opponentAdjustedEfficiencyProfile($game, (int) $game->home_team_id);
        $away = $this->opponentAdjustedEfficiencyProfile($game, (int) $game->away_team_id);
        $minGames = (int) config('nfl.predictions.opponent_adjusted_efficiency.min_games', 3);

        if (($home['games'] ?? 0) < $minGames || ($away['games'] ?? 0) < $minGames) {
            $this->lastModelMetadata['opponent_adjusted_efficiency']['reason'] = 'insufficient_prior_games';
            $this->lastModelMetadata['opponent_adjusted_efficiency']['home'] = $home;
            $this->lastModelMetadata['opponent_adjusted_efficiency']['away'] = $away;

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $marginWeight = (float) config('nfl.predictions.opponent_adjusted_efficiency.margin_weight', 0.45);
        $yardWeight = (float) config('nfl.predictions.opponent_adjusted_efficiency.yard_weight', 0.08);
        $redZoneWeight = (float) config('nfl.predictions.opponent_adjusted_efficiency.red_zone_weight', 2.0);
        $thirdDownWeight = (float) config('nfl.predictions.opponent_adjusted_efficiency.third_down_weight', 2.0);
        $maxSignalSpread = (float) config('nfl.predictions.opponent_adjusted_efficiency.max_signal_spread', 5.0);

        $homeScore = ((float) $home['opponent_adjusted_margin'] * $marginWeight)
            + ((float) $home['yard_diff'] * $yardWeight)
            + ((float) $home['red_zone_rate_diff'] * $redZoneWeight)
            + ((float) $home['third_down_rate_diff'] * $thirdDownWeight);
        $awayScore = ((float) $away['opponent_adjusted_margin'] * $marginWeight)
            + ((float) $away['yard_diff'] * $yardWeight)
            + ((float) $away['red_zone_rate_diff'] * $redZoneWeight)
            + ((float) $away['third_down_rate_diff'] * $thirdDownWeight);
        $signalSpread = $this->clamp($homeScore - $awayScore, -$maxSignalSpread, $maxSignalSpread);

        $weight = $this->clamp((float) config('nfl.predictions.opponent_adjusted_efficiency.blend_weight', 0.18), 0.0, 1.0);
        $blendedSpread = $this->clamp(
            $this->blend($predictedSpread, $predictedSpread + $signalSpread, $weight),
            (float) config('nfl.predictions.min_spread'),
            (float) config('nfl.predictions.max_spread')
        );
        $spreadCoefficient = (float) config('nfl.predictions.spread_to_probability_coefficient', 7.0);
        $blendedWinProbability = $this->clamp(1 / (1 + exp(-$blendedSpread / $spreadCoefficient)), 0.01, 0.99);

        $this->lastModelMetadata['opponent_adjusted_efficiency'] = [
            'enabled' => true,
            'applied' => true,
            'weight' => round($weight, 4),
            'home' => $home,
            'away' => $away,
            'home_score' => round($homeScore, 3),
            'away_score' => round($awayScore, 3),
            'signal_spread' => round($signalSpread, 3),
            'blended_spread' => round($blendedSpread, 3),
        ];

        return [$blendedSpread, $blendedWinProbability, $predictedTotal];
    }

    protected function applyTotalEnvironmentBlend(Game $game, float $predictedTotal): float
    {
        $this->lastModelMetadata['total_environment'] = [
            'enabled' => (bool) config('nfl.predictions.total_environment.enabled', false),
            'applied' => false,
        ];

        if (! config('nfl.predictions.total_environment.enabled', false)) {
            $this->lastModelMetadata['total_environment']['reason'] = 'feature_disabled';

            return $predictedTotal;
        }

        $home = $this->totalEnvironmentProfile($game, (int) $game->home_team_id);
        $away = $this->totalEnvironmentProfile($game, (int) $game->away_team_id);
        $minGames = (int) config('nfl.predictions.total_environment.min_games', 2);

        if (($home['games'] ?? 0) < $minGames || ($away['games'] ?? 0) < $minGames) {
            $this->lastModelMetadata['total_environment']['reason'] = 'insufficient_prior_games';
            $this->lastModelMetadata['total_environment']['home'] = $home;
            $this->lastModelMetadata['total_environment']['away'] = $away;

            return $predictedTotal;
        }

        $leagueTotal = (float) config('nfl.predictions.average_total', 46.0);
        $leaguePlays = (float) config('nfl.predictions.total_environment.league_combined_plays', 128.0);
        $leagueYardsPerPlay = (float) config('nfl.predictions.total_environment.league_yards_per_play', 5.35);
        $leagueRedZoneRate = (float) config('nfl.predictions.total_environment.league_red_zone_rate', 0.58);
        $leagueThirdDownRate = (float) config('nfl.predictions.total_environment.league_third_down_rate', 0.39);
        $leagueTurnoverRate = (float) config('nfl.predictions.total_environment.league_turnover_rate', 0.020);
        $leaguePenaltyYards = (float) config('nfl.predictions.total_environment.league_penalty_yards', 48.0);

        $scoringSignal = ((float) $home['points_for'] + (float) $away['points_for'] + (float) $home['points_against'] + (float) $away['points_against']) / 2.0;
        $combinedPlays = ((float) $home['offensive_plays'] + (float) $away['offensive_plays'] + (float) $home['defensive_plays'] + (float) $away['defensive_plays']) / 2.0;
        $matchupYardsPerPlay = ((float) $home['yards_per_play'] + (float) $away['yards_per_play'] + (float) $home['yards_allowed_per_play'] + (float) $away['yards_allowed_per_play']) / 4.0;
        $matchupRedZoneRate = ((float) $home['red_zone_rate'] + (float) $away['red_zone_rate'] + (float) $home['red_zone_allowed_rate'] + (float) $away['red_zone_allowed_rate']) / 4.0;
        $matchupThirdDownRate = ((float) $home['third_down_rate'] + (float) $away['third_down_rate'] + (float) $home['third_down_allowed_rate'] + (float) $away['third_down_allowed_rate']) / 4.0;
        $matchupTurnoverRate = ((float) $home['turnover_rate'] + (float) $away['turnover_rate'] + (float) $home['takeaway_rate'] + (float) $away['takeaway_rate']) / 4.0;
        $matchupPenaltyYards = ((float) $home['penalty_yards'] + (float) $away['penalty_yards']) / 2.0;

        $scoringWeight = (float) config('nfl.predictions.total_environment.scoring_weight', 0.22);
        $paceWeight = (float) config('nfl.predictions.total_environment.pace_weight', 0.11);
        $explosiveWeight = (float) config('nfl.predictions.total_environment.explosive_weight', 3.8);
        $redZoneWeight = (float) config('nfl.predictions.total_environment.red_zone_weight', 4.5);
        $thirdDownWeight = (float) config('nfl.predictions.total_environment.third_down_weight', 2.5);
        $turnoverWeight = (float) config('nfl.predictions.total_environment.turnover_weight', -34.0);
        $penaltyWeight = (float) config('nfl.predictions.total_environment.penalty_weight', -0.018);
        $maxAdjustment = (float) config('nfl.predictions.total_environment.max_adjustment', 4.0);
        $blendWeight = $this->clamp((float) config('nfl.predictions.total_environment.blend_weight', 0.35), 0.0, 1.0);

        $scoringAdjustment = ($scoringSignal - $leagueTotal) * $scoringWeight;
        $paceAdjustment = ($combinedPlays - $leaguePlays) * $paceWeight;
        $explosiveAdjustment = ($matchupYardsPerPlay - $leagueYardsPerPlay) * $explosiveWeight;
        $redZoneAdjustment = ($matchupRedZoneRate - $leagueRedZoneRate) * $redZoneWeight;
        $thirdDownAdjustment = ($matchupThirdDownRate - $leagueThirdDownRate) * $thirdDownWeight;
        $turnoverAdjustment = ($matchupTurnoverRate - $leagueTurnoverRate) * $turnoverWeight;
        $penaltyAdjustment = ($matchupPenaltyYards - $leaguePenaltyYards) * $penaltyWeight;

        $rawAdjustment = $scoringAdjustment
            + $paceAdjustment
            + $explosiveAdjustment
            + $redZoneAdjustment
            + $thirdDownAdjustment
            + $turnoverAdjustment
            + $penaltyAdjustment;
        $adjustment = $this->clamp($rawAdjustment, -$maxAdjustment, $maxAdjustment);
        $signalTotal = $this->clamp(
            $predictedTotal + $adjustment,
            (float) config('nfl.predictions.true_epa.min_predicted_total', 28.0),
            (float) config('nfl.predictions.true_epa.max_predicted_total', 66.0)
        );
        $blendedTotal = $this->clamp(
            $this->blend($predictedTotal, $signalTotal, $blendWeight),
            (float) config('nfl.predictions.true_epa.min_predicted_total', 28.0),
            (float) config('nfl.predictions.true_epa.max_predicted_total', 66.0)
        );

        $this->lastModelMetadata['total_environment'] = [
            'enabled' => true,
            'applied' => true,
            'weight' => round($blendWeight, 4),
            'home' => $home,
            'away' => $away,
            'scoring_signal' => round($scoringSignal, 3),
            'combined_plays' => round($combinedPlays, 3),
            'matchup_yards_per_play' => round($matchupYardsPerPlay, 3),
            'matchup_red_zone_rate' => round($matchupRedZoneRate, 3),
            'matchup_third_down_rate' => round($matchupThirdDownRate, 3),
            'matchup_turnover_rate' => round($matchupTurnoverRate, 4),
            'adjustments' => [
                'scoring' => round($scoringAdjustment, 3),
                'pace' => round($paceAdjustment, 3),
                'explosive' => round($explosiveAdjustment, 3),
                'red_zone' => round($redZoneAdjustment, 3),
                'third_down' => round($thirdDownAdjustment, 3),
                'turnover' => round($turnoverAdjustment, 3),
                'penalty' => round($penaltyAdjustment, 3),
            ],
            'raw_adjustment' => round($rawAdjustment, 3),
            'adjustment' => round($adjustment, 3),
            'signal_total' => round($signalTotal, 3),
            'blended_total' => round($blendedTotal, 3),
        ];

        return $blendedTotal;
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
        $matchupRecords = $this->matchupRecordContext($game);
        $weather = $this->weatherTotalContext($game);
        $schedule = $this->scheduleSpotContext($game);
        $coaching = $this->coachingContext($game);

        $spreadAdjustment = $homeAway['spread_adjustment']
            + $division['spread_adjustment']
            + $matchupRecords['spread_adjustment']
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
            'matchup_records' => $matchupRecords,
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
     * @return array{0:float,1:float,2:float}
     */
    protected function applyActualWeatherBlend(
        Game $game,
        float $predictedSpread,
        float $winProbability,
        float $predictedTotal
    ): array {
        $this->lastModelMetadata['actual_weather'] = [
            'enabled' => (bool) config('nfl.predictions.actual_weather.enabled', true),
            'applied' => false,
        ];

        if (! config('nfl.predictions.actual_weather.enabled', true)) {
            $this->lastModelMetadata['actual_weather']['reason'] = 'feature_disabled';

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $weather = $game->relationLoaded('weather') ? $game->weather : $game->weather()->first();
        if (! $weather) {
            $this->lastModelMetadata['actual_weather']['reason'] = 'missing_weather_row';

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        if ((bool) $weather->is_indoor) {
            $this->lastModelMetadata['actual_weather'] = [
                'enabled' => true,
                'applied' => false,
                'reason' => 'indoor_venue',
                'is_indoor' => true,
            ];

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $wind = (float) ($weather->wind_speed_mph ?? 0.0);
        $gust = (float) ($weather->wind_gust_mph ?? 0.0);
        $precip = (float) ($weather->precipitation_inches ?? 0.0);
        $temperature = (float) ($weather->temperature_f ?? 0.0);
        $adjustment = 0.0;

        if ($wind >= (float) config('nfl.predictions.actual_weather.wind_under_threshold_mph', 15)) {
            $adjustment += $wind * (float) config('nfl.predictions.actual_weather.wind_total_weight', -0.08);
        }
        if ($gust >= (float) config('nfl.predictions.actual_weather.gust_under_threshold_mph', 24)) {
            $adjustment += $gust * (float) config('nfl.predictions.actual_weather.gust_total_weight', -0.04);
        }
        if ($precip >= (float) config('nfl.predictions.actual_weather.precip_under_threshold_inches', 0.03)) {
            $adjustment += $precip * (float) config('nfl.predictions.actual_weather.precip_total_weight', -18.0);
        }
        if ($temperature > 0 && $temperature <= (float) config('nfl.predictions.actual_weather.cold_under_threshold_f', 32)) {
            $adjustment += (float) config('nfl.predictions.actual_weather.cold_total_adjustment', -1.0);
        }
        if ($temperature >= (float) config('nfl.predictions.actual_weather.heat_under_threshold_f', 88)) {
            $adjustment += (float) config('nfl.predictions.actual_weather.heat_total_adjustment', -0.5);
        }

        $maxAdjustment = (float) config('nfl.predictions.actual_weather.max_total_adjustment', 4.0);
        $adjustment = $this->clamp($adjustment, -$maxAdjustment, $maxAdjustment);
        $adjustedTotal = $this->clamp(
            $predictedTotal + $adjustment,
            (float) config('nfl.predictions.true_epa.min_predicted_total', 28.0),
            (float) config('nfl.predictions.true_epa.max_predicted_total', 66.0)
        );

        $this->lastModelMetadata['actual_weather'] = [
            'enabled' => true,
            'applied' => round($adjustment, 3) !== 0.0,
            'is_indoor' => false,
            'temperature_f' => round($temperature, 2),
            'wind_speed_mph' => round($wind, 2),
            'wind_gust_mph' => round($gust, 2),
            'precipitation_inches' => round($precip, 3),
            'total_adjustment' => round($adjustment, 3),
            'blended_total' => round($adjustedTotal, 3),
        ];

        return [$predictedSpread, $winProbability, $adjustedTotal];
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
    protected function matchupRecordContext(Game $game): array
    {
        $lookback = max(1, (int) config('nfl.predictions.contextual_factors.matchup_record_lookback_games', 8));
        $homeTeam = $game->homeTeam;
        $awayTeam = $game->awayTeam;

        $home = [
            'h2h' => $this->teamRecordProfile($game, (int) $game->home_team_id, $lookback, 'team', (int) $game->away_team_id),
            'division' => $this->teamRecordProfile($game, (int) $game->home_team_id, $lookback, 'division'),
            'conference' => $this->teamRecordProfile($game, (int) $game->home_team_id, $lookback, 'conference'),
        ];
        $away = [
            'h2h' => $this->teamRecordProfile($game, (int) $game->away_team_id, $lookback, 'team', (int) $game->home_team_id),
            'division' => $this->teamRecordProfile($game, (int) $game->away_team_id, $lookback, 'division'),
            'conference' => $this->teamRecordProfile($game, (int) $game->away_team_id, $lookback, 'conference'),
        ];

        $h2hWeight = (float) config('nfl.predictions.contextual_factors.matchup_record_h2h_weight', 0.40);
        $divisionWeight = (float) config('nfl.predictions.contextual_factors.matchup_record_division_weight', 0.30);
        $conferenceWeight = (float) config('nfl.predictions.contextual_factors.matchup_record_conference_weight', 0.20);
        $spreadAdjustment = $this->recordSpreadSignal($home['h2h'], $away['h2h'], $h2hWeight)
            + $this->recordSpreadSignal($home['division'], $away['division'], $divisionWeight)
            + $this->recordSpreadSignal($home['conference'], $away['conference'], $conferenceWeight);

        return [
            'applied' => $this->recordHasGames($home['h2h'], $away['h2h'])
                || $this->recordHasGames($home['division'], $away['division'])
                || $this->recordHasGames($home['conference'], $away['conference']),
            'lookback_games' => $lookback,
            'home_team' => $homeTeam?->abbreviation,
            'away_team' => $awayTeam?->abbreviation,
            'home' => $home,
            'away' => $away,
            'spread_adjustment' => round($spreadAdjustment, 3),
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

    /**
     * Use only prior completed predictions to correct recent spread/total
     * residual bias. Residual convention is prediction - actual, so positive
     * values mean the model has been too high and should be adjusted down.
     *
     * @return array{0:float,1:float,2:float}
     */
    protected function applyAdaptivePointCalibration(
        Game $game,
        float $predictedSpread,
        float $winProbability,
        float $predictedTotal
    ): array {
        $enabled = (bool) config('nfl.predictions.adaptive_point_calibration.enabled', true);
        $this->lastModelMetadata['adaptive_point_calibration'] = [
            'enabled' => $enabled,
            'applied' => false,
            'baseline_spread' => round($predictedSpread, 4),
            'baseline_total' => round($predictedTotal, 4),
        ];

        if (! $enabled) {
            $this->lastModelMetadata['adaptive_point_calibration']['reason'] = 'feature_disabled';

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $date = $this->asDate($game->game_date);
        if (! $date) {
            $this->lastModelMetadata['adaptive_point_calibration']['reason'] = 'missing_game_date';

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $lookbackGames = max(1, (int) config('nfl.predictions.adaptive_point_calibration.lookback_games', 384));
        $minSample = max(1, (int) config('nfl.predictions.adaptive_point_calibration.min_sample', 48));
        $priorPredictions = Prediction::query()
            ->with('game')
            ->select('nfl_predictions.*')
            ->join('nfl_games', 'nfl_games.id', '=', 'nfl_predictions.game_id')
            ->where('nfl_games.status', 'STATUS_FINAL')
            ->whereNotNull('nfl_games.home_score')
            ->whereNotNull('nfl_games.away_score')
            ->whereDate('nfl_games.game_date', '<', $date->toDateString())
            ->whereNotNull('nfl_predictions.predicted_spread')
            ->whereNotNull('nfl_predictions.predicted_total')
            ->orderByDesc('nfl_games.game_date')
            ->orderByDesc('nfl_games.id')
            ->limit($lookbackGames)
            ->get();

        if ($priorPredictions->count() < $minSample) {
            $this->lastModelMetadata['adaptive_point_calibration']['reason'] = 'insufficient_prior_predictions';
            $this->lastModelMetadata['adaptive_point_calibration']['sample'] = $priorPredictions->count();
            $this->lastModelMetadata['adaptive_point_calibration']['min_sample'] = $minSample;

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $spreadResiduals = [];
        $totalResiduals = [];

        foreach ($priorPredictions as $prediction) {
            $priorGame = $prediction->game;
            if (! $priorGame || $priorGame->home_score === null || $priorGame->away_score === null) {
                continue;
            }

            $actualSpread = (float) $priorGame->home_score - (float) $priorGame->away_score;
            $actualTotal = (float) $priorGame->home_score + (float) $priorGame->away_score;
            $spreadResiduals[] = (float) $prediction->predicted_spread - $actualSpread;
            $totalResiduals[] = (float) $prediction->predicted_total - $actualTotal;
        }

        if (count($spreadResiduals) < $minSample || count($totalResiduals) < $minSample) {
            $this->lastModelMetadata['adaptive_point_calibration']['reason'] = 'insufficient_prior_results';
            $this->lastModelMetadata['adaptive_point_calibration']['sample'] = min(count($spreadResiduals), count($totalResiduals));
            $this->lastModelMetadata['adaptive_point_calibration']['min_sample'] = $minSample;

            return [$predictedSpread, $winProbability, $predictedTotal];
        }

        $spreadResidual = $this->trimmedAverage(
            $spreadResiduals,
            (float) config('nfl.predictions.adaptive_point_calibration.trim_fraction', 0.10)
        );
        $totalResidual = $this->trimmedAverage(
            $totalResiduals,
            (float) config('nfl.predictions.adaptive_point_calibration.trim_fraction', 0.10)
        );

        $spreadWeight = $this->clamp((float) config('nfl.predictions.adaptive_point_calibration.spread_blend_weight', 0.35), 0.0, 1.0);
        $totalWeight = $this->clamp((float) config('nfl.predictions.adaptive_point_calibration.total_blend_weight', 0.45), 0.0, 1.0);
        $maxSpreadAdjustment = (float) config('nfl.predictions.adaptive_point_calibration.max_spread_adjustment', 2.0);
        $maxTotalAdjustment = (float) config('nfl.predictions.adaptive_point_calibration.max_total_adjustment', 2.5);

        $spreadAdjustment = $this->clamp(-$spreadResidual * $spreadWeight, -$maxSpreadAdjustment, $maxSpreadAdjustment);
        $totalAdjustment = $this->clamp(-$totalResidual * $totalWeight, -$maxTotalAdjustment, $maxTotalAdjustment);

        $calibratedSpread = $this->clamp(
            $predictedSpread + $spreadAdjustment,
            (float) config('nfl.predictions.min_spread'),
            (float) config('nfl.predictions.max_spread')
        );
        $calibratedTotal = $this->clamp(
            $predictedTotal + $totalAdjustment,
            (float) config('nfl.predictions.true_epa.min_predicted_total', 28.0),
            (float) config('nfl.predictions.true_epa.max_predicted_total', 66.0)
        );

        $spreadCoefficient = (float) config('nfl.predictions.spread_to_probability_coefficient', 7.0);
        $calibratedWinProbability = $this->clamp(
            1 / (1 + exp(-$calibratedSpread / $spreadCoefficient)),
            0.01,
            0.99
        );

        $this->lastModelMetadata['adaptive_point_calibration'] = [
            'enabled' => true,
            'applied' => round($spreadAdjustment, 4) !== 0.0 || round($totalAdjustment, 4) !== 0.0,
            'reason' => 'calibrated',
            'lookback_games' => $priorPredictions->count(),
            'sample_used' => min(count($spreadResiduals), count($totalResiduals)),
            'spread_residual' => round($spreadResidual, 4),
            'total_residual' => round($totalResidual, 4),
            'spread_adjustment' => round($spreadAdjustment, 4),
            'total_adjustment' => round($totalAdjustment, 4),
            'spread_blend_weight' => round($spreadWeight, 4),
            'total_blend_weight' => round($totalWeight, 4),
            'baseline_spread' => round($predictedSpread, 4),
            'baseline_total' => round($predictedTotal, 4),
            'calibrated_spread' => round($calibratedSpread, 4),
            'calibrated_total' => round($calibratedTotal, 4),
        ];

        return [$calibratedSpread, $calibratedWinProbability, $calibratedTotal];
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

    /**
     * @return array{games:int,opponent_adjusted_margin:float,yard_diff:float,red_zone_rate_diff:float,third_down_rate_diff:float,avg_opponent_elo:float}
     */
    protected function opponentAdjustedEfficiencyProfile(Game $game, int $teamId): array
    {
        $date = $this->asDate($game->game_date);
        $opponentEloWeight = (float) config('nfl.predictions.opponent_adjusted_efficiency.opponent_elo_weight', 0.015);
        $defaultElo = (float) config('nfl.elo.default_rating', 1500);

        $games = Game::query()
            ->with('teamStats')
            ->where('season', (int) $game->season)
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->when($date, fn ($query) => $query->whereDate('game_date', '<', $date->toDateString()))
            ->where(function ($query) use ($teamId): void {
                $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->orderBy('game_date')
            ->orderBy('id')
            ->get();

        $margins = [];
        $yardDiffs = [];
        $redZoneDiffs = [];
        $thirdDownDiffs = [];
        $opponentElos = [];

        foreach ($games as $priorGame) {
            $isHome = (int) $priorGame->home_team_id === $teamId;
            $opponentId = (int) ($isHome ? $priorGame->away_team_id : $priorGame->home_team_id);
            $teamScore = (float) ($isHome ? $priorGame->home_score : $priorGame->away_score);
            $opponentScore = (float) ($isHome ? $priorGame->away_score : $priorGame->home_score);
            $opponentElo = $this->getEloAtDate($opponentId, $priorGame->game_date);
            $opponentElos[] = $opponentElo;
            $margins[] = ($teamScore - $opponentScore) + (($opponentElo - $defaultElo) * $opponentEloWeight);

            $teamStat = $priorGame->teamStats->firstWhere('team_id', $teamId)
                ?? $this->statByTeamType($priorGame, $isHome ? 'home' : 'away');
            $opponentStat = $priorGame->teamStats->firstWhere('team_id', $opponentId)
                ?? $this->statByTeamType($priorGame, $isHome ? 'away' : 'home');

            if (! $teamStat || ! $opponentStat) {
                continue;
            }

            $yardDiffs[] = ((float) ($teamStat->total_yards ?? 0) - (float) ($opponentStat->total_yards ?? 0)) / 100;
            $redZoneDiffs[] = $this->rate((int) ($teamStat->red_zone_scores ?? 0), (int) ($teamStat->red_zone_attempts ?? 0))
                - $this->rate((int) ($opponentStat->red_zone_scores ?? 0), (int) ($opponentStat->red_zone_attempts ?? 0));
            $thirdDownDiffs[] = $this->rate((int) ($teamStat->third_down_conversions ?? 0), (int) ($teamStat->third_down_attempts ?? 0))
                - $this->rate((int) ($opponentStat->third_down_conversions ?? 0), (int) ($opponentStat->third_down_attempts ?? 0));
        }

        return [
            'games' => $games->count(),
            'opponent_adjusted_margin' => round($this->average($margins), 3),
            'yard_diff' => round($this->average($yardDiffs), 3),
            'red_zone_rate_diff' => round($this->average($redZoneDiffs), 3),
            'third_down_rate_diff' => round($this->average($thirdDownDiffs), 3),
            'avg_opponent_elo' => round($this->average($opponentElos), 1),
        ];
    }

    /**
     * @return array{games:int,points_for:float,points_against:float,offensive_plays:float,defensive_plays:float,yards_per_play:float,yards_allowed_per_play:float,pass_rate:float,red_zone_rate:float,red_zone_allowed_rate:float,third_down_rate:float,third_down_allowed_rate:float,turnover_rate:float,takeaway_rate:float,penalty_yards:float}
     */
    protected function totalEnvironmentProfile(Game $game, int $teamId): array
    {
        $date = $this->asDate($game->game_date);
        $recentGames = max(1, (int) config('nfl.predictions.total_environment.recent_games', 8));

        $games = Game::query()
            ->with('teamStats')
            ->where('season', (int) $game->season)
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->when($date, fn ($query) => $query->whereDate('game_date', '<', $date->toDateString()))
            ->where(function ($query) use ($teamId): void {
                $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->orderBy('game_date')
            ->orderBy('id')
            ->get();

        $minGames = (int) config('nfl.predictions.total_environment.min_games', 2);
        if ($games->count() < $minGames && (int) $game->season > 1900) {
            $previousSeasonGames = Game::query()
                ->with('teamStats')
                ->where('season', (int) $game->season - 1)
                ->where('status', 'STATUS_FINAL')
                ->whereNotNull('home_score')
                ->whereNotNull('away_score')
                ->where(function ($query) use ($teamId): void {
                    $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
                })
                ->orderByDesc('game_date')
                ->orderByDesc('id')
                ->limit($recentGames)
                ->get()
                ->reverse()
                ->values();

            $games = $previousSeasonGames->concat($games)->values();
        }

        if ($games->isEmpty()) {
            return [
                'games' => 0,
                'points_for' => 0.0,
                'points_against' => 0.0,
                'offensive_plays' => 0.0,
                'defensive_plays' => 0.0,
                'yards_per_play' => 0.0,
                'yards_allowed_per_play' => 0.0,
                'pass_rate' => 0.0,
                'red_zone_rate' => 0.0,
                'red_zone_allowed_rate' => 0.0,
                'third_down_rate' => 0.0,
                'third_down_allowed_rate' => 0.0,
                'turnover_rate' => 0.0,
                'takeaway_rate' => 0.0,
                'penalty_yards' => 0.0,
            ];
        }

        $pointsFor = [];
        $pointsAgainst = [];
        $offensivePlays = [];
        $defensivePlays = [];
        $yardsPerPlay = [];
        $yardsAllowedPerPlay = [];
        $passRates = [];
        $redZoneRates = [];
        $redZoneAllowedRates = [];
        $thirdDownRates = [];
        $thirdDownAllowedRates = [];
        $turnoverRates = [];
        $takeawayRates = [];
        $penaltyYards = [];

        foreach ($games->take(-$recentGames) as $priorGame) {
            $isHome = (int) $priorGame->home_team_id === $teamId;
            $opponentId = (int) ($isHome ? $priorGame->away_team_id : $priorGame->home_team_id);
            $teamScore = (float) ($isHome ? $priorGame->home_score : $priorGame->away_score);
            $opponentScore = (float) ($isHome ? $priorGame->away_score : $priorGame->home_score);

            $teamStat = $priorGame->teamStats->firstWhere('team_id', $teamId)
                ?? $this->statByTeamType($priorGame, $isHome ? 'home' : 'away');
            $opponentStat = $priorGame->teamStats->firstWhere('team_id', $opponentId)
                ?? $this->statByTeamType($priorGame, $isHome ? 'away' : 'home');

            $pointsFor[] = $teamScore;
            $pointsAgainst[] = $opponentScore;

            if (! $teamStat || ! $opponentStat) {
                continue;
            }

            $teamOffensivePlays = $this->offensivePlayCount($teamStat);
            $opponentOffensivePlays = $this->offensivePlayCount($opponentStat);
            $teamPassAttempts = (float) ($teamStat->passing_attempts ?? 0);
            $teamTurnovers = (float) ($teamStat->interceptions ?? 0) + (float) ($teamStat->fumbles_lost ?? $teamStat->fumbles ?? 0);
            $opponentTurnovers = (float) ($opponentStat->interceptions ?? 0) + (float) ($opponentStat->fumbles_lost ?? $opponentStat->fumbles ?? 0);

            $offensivePlays[] = $teamOffensivePlays;
            $defensivePlays[] = $opponentOffensivePlays;
            $yardsPerPlay[] = $teamOffensivePlays > 0 ? (float) ($teamStat->total_yards ?? 0) / $teamOffensivePlays : 0.0;
            $yardsAllowedPerPlay[] = $opponentOffensivePlays > 0 ? (float) ($opponentStat->total_yards ?? 0) / $opponentOffensivePlays : 0.0;
            $passRates[] = $teamOffensivePlays > 0 ? $teamPassAttempts / $teamOffensivePlays : 0.0;
            $redZoneRates[] = $this->rate((int) ($teamStat->red_zone_scores ?? 0), (int) ($teamStat->red_zone_attempts ?? 0));
            $redZoneAllowedRates[] = $this->rate((int) ($opponentStat->red_zone_scores ?? 0), (int) ($opponentStat->red_zone_attempts ?? 0));
            $thirdDownRates[] = $this->rate((int) ($teamStat->third_down_conversions ?? 0), (int) ($teamStat->third_down_attempts ?? 0));
            $thirdDownAllowedRates[] = $this->rate((int) ($opponentStat->third_down_conversions ?? 0), (int) ($opponentStat->third_down_attempts ?? 0));
            $turnoverRates[] = $teamOffensivePlays > 0 ? $teamTurnovers / $teamOffensivePlays : 0.0;
            $takeawayRates[] = $opponentOffensivePlays > 0 ? $opponentTurnovers / $opponentOffensivePlays : 0.0;
            $penaltyYards[] = (float) ($teamStat->penalty_yards ?? 0);
        }

        return [
            'games' => $games->count(),
            'points_for' => round($this->average($pointsFor), 3),
            'points_against' => round($this->average($pointsAgainst), 3),
            'offensive_plays' => round($this->average($offensivePlays), 3),
            'defensive_plays' => round($this->average($defensivePlays), 3),
            'yards_per_play' => round($this->average($yardsPerPlay), 3),
            'yards_allowed_per_play' => round($this->average($yardsAllowedPerPlay), 3),
            'pass_rate' => round($this->average($passRates), 3),
            'red_zone_rate' => round($this->average($redZoneRates), 3),
            'red_zone_allowed_rate' => round($this->average($redZoneAllowedRates), 3),
            'third_down_rate' => round($this->average($thirdDownRates), 3),
            'third_down_allowed_rate' => round($this->average($thirdDownAllowedRates), 3),
            'turnover_rate' => round($this->average($turnoverRates), 4),
            'takeaway_rate' => round($this->average($takeawayRates), 4),
            'penalty_yards' => round($this->average($penaltyYards), 3),
        ];
    }

    protected function offensivePlayCount(object $stat): float
    {
        return max(0.0, (float) ($stat->passing_attempts ?? 0)
            + (float) ($stat->rushing_attempts ?? 0)
            + (float) ($stat->sacks_allowed ?? 0));
    }

    protected function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? $numerator / $denominator : 0.0;
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
     * @return array{games:int,wins:int,losses:int,ties:int,win_pct:float,avg_margin:float}
     */
    protected function teamRecordProfile(Game $game, int $teamId, int $lookback, string $scope, ?int $opponentTeamId = null): array
    {
        $date = $this->asDate($game->game_date);
        $team = (int) $game->home_team_id === $teamId ? $game->homeTeam : $game->awayTeam;
        $teamDivision = $this->divisionKey($team);
        $teamConference = $this->conferenceKey($team);

        $games = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->when($date, fn ($query) => $query->whereDate('game_date', '<', $date->toDateString()))
            ->where(function ($query) use ($teamId): void {
                $query->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId);
            })
            ->orderByDesc('game_date')
            ->orderByDesc('id')
            ->limit($lookback * 4)
            ->get()
            ->filter(function (Game $priorGame) use ($scope, $teamId, $opponentTeamId, $teamDivision, $teamConference): bool {
                $opponent = (int) $priorGame->home_team_id === $teamId ? $priorGame->awayTeam : $priorGame->homeTeam;

                return match ($scope) {
                    'team' => $opponentTeamId !== null && (int) $opponent?->id === $opponentTeamId,
                    'division' => $teamDivision !== null && $this->divisionKey($opponent) === $teamDivision,
                    'conference' => $teamConference !== null && $this->conferenceKey($opponent) === $teamConference,
                    default => false,
                };
            })
            ->take($lookback)
            ->values();

        $wins = 0;
        $losses = 0;
        $ties = 0;
        $margins = [];

        foreach ($games as $priorGame) {
            $isHome = (int) $priorGame->home_team_id === $teamId;
            $teamScore = $isHome ? (float) $priorGame->home_score : (float) $priorGame->away_score;
            $opponentScore = $isHome ? (float) $priorGame->away_score : (float) $priorGame->home_score;
            $margins[] = $teamScore - $opponentScore;

            if ($teamScore > $opponentScore) {
                $wins++;
            } elseif ($teamScore < $opponentScore) {
                $losses++;
            } else {
                $ties++;
            }
        }

        $gameCount = $games->count();

        return [
            'games' => $gameCount,
            'wins' => $wins,
            'losses' => $losses,
            'ties' => $ties,
            'win_pct' => $gameCount > 0 ? round(($wins + ($ties * 0.5)) / $gameCount, 3) : 0.0,
            'avg_margin' => round($this->average($margins), 3),
        ];
    }

    /**
     * @param  array<string,mixed>  $home
     * @param  array<string,mixed>  $away
     */
    protected function recordSpreadSignal(array $home, array $away, float $weight): float
    {
        if (! $this->recordHasGames($home, $away)) {
            return 0.0;
        }

        $winPctSignal = ((float) ($home['win_pct'] ?? 0.0) - (float) ($away['win_pct'] ?? 0.0)) * 2.0;
        $marginSignal = ((float) ($home['avg_margin'] ?? 0.0) - (float) ($away['avg_margin'] ?? 0.0)) / 7.0;

        return ($winPctSignal + $marginSignal) * $weight;
    }

    /**
     * @param  array<string,mixed>  $home
     * @param  array<string,mixed>  $away
     */
    protected function recordHasGames(array $home, array $away): bool
    {
        return (int) ($home['games'] ?? 0) > 0 && (int) ($away['games'] ?? 0) > 0;
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

        if (($conference === '' || $division === '') && isset(self::NFL_DIVISION_MAP[strtoupper((string) ($team->abbreviation ?? ''))])) {
            $fallback = self::NFL_DIVISION_MAP[strtoupper((string) ($team->abbreviation ?? ''))];
            $conference = $conference !== '' ? $conference : $fallback['conference'];
            $division = $division !== '' ? $division : $fallback['division'];
        }

        return $conference !== '' && $division !== '' ? $conference.'-'.$division : null;
    }

    protected function conferenceKey(mixed $team): ?string
    {
        if (! $team) {
            return null;
        }

        $conference = strtolower(trim((string) ($team->conference ?? '')));
        if ($conference !== '') {
            return $conference;
        }

        return self::NFL_DIVISION_MAP[strtoupper((string) ($team->abbreviation ?? ''))]['conference'] ?? null;
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

        $minGames = (int) config('nfl.predictions.line_matchup.min_games', 2);
        if ($games->count() < $minGames) {
            $previousSeasonGames = Game::query()
                ->with('teamStats')
                ->where('season', (int) $game->season - 1)
                ->where('status', 'STATUS_FINAL')
                ->whereNotNull('home_score')
                ->whereNotNull('away_score')
                ->where(function ($query) use ($teamId): void {
                    $query->where('home_team_id', $teamId)
                        ->orWhere('away_team_id', $teamId);
                })
                ->orderBy('game_date')
                ->orderBy('id')
                ->get();

            $games = $previousSeasonGames->merge($games);
        }

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
            $depthChartQb = $this->projectedQbContextFromDepthChart($game, $teamId);
            if ($depthChartQb !== null) {
                return $depthChartQb;
            }

            $fallback = $this->projectedQbContextFromPriorGames($game, $teamId);
            if ($fallback !== null) {
                return $fallback;
            }

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
     * @return array<string,mixed>|null
     */
    protected function projectedQbContextFromDepthChart(Game $game, int $teamId): ?array
    {
        $entry = DepthChartEntry::query()
            ->with('player')
            ->where('team_id', $teamId)
            ->where('season', (int) $game->season)
            ->where('position_code', 'QB')
            ->where('is_starter', true)
            ->orderBy('depth_rank')
            ->orderBy('slot_order')
            ->first();

        if (! $entry || ! $entry->player_id) {
            return null;
        }

        $prior = $this->priorQbStats((int) $entry->player_id, $teamId, $game);
        $score = $this->qbScore($prior);
        $experience = is_numeric($entry->player?->experience ?? null) ? (int) $entry->player->experience : null;

        return [
            'qb_id' => (int) $entry->player_id,
            'qb_name' => $entry->player?->full_name,
            'experience' => $experience,
            'experience_bucket' => $this->qbExperienceBucket($experience, (int) $prior['games']),
            'game_attempts' => 0,
            'projected_from_depth_chart' => true,
            'depth_chart_name' => $entry->depth_chart_name,
            'depth_chart_updated_at' => $entry->source_updated_at?->toDateTimeString(),
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
     * @return array<string,mixed>|null
     */
    protected function projectedQbContextFromPriorGames(Game $game, int $teamId): ?array
    {
        $date = $this->asDate($game->game_date);

        $qbStat = PlayerStat::query()
            ->with('player')
            ->join('nfl_games', 'nfl_games.id', '=', 'nfl_player_stats.game_id')
            ->where('nfl_player_stats.team_id', $teamId)
            ->where('nfl_player_stats.passing_attempts', '>', 0)
            ->where('nfl_games.status', 'STATUS_FINAL')
            ->when($date, fn ($query) => $query->whereDate('nfl_games.game_date', '<', $date->toDateString()))
            ->orderByDesc('nfl_games.game_date')
            ->orderByDesc('nfl_player_stats.passing_attempts')
            ->orderByDesc('nfl_player_stats.passing_yards')
            ->first([
                'nfl_player_stats.*',
            ]);

        if (! $qbStat) {
            return null;
        }

        $prior = $this->priorQbStats((int) $qbStat->player_id, $teamId, $game);
        $score = $this->qbScore($prior);
        $experience = is_numeric($qbStat->player?->experience ?? null) ? (int) $qbStat->player->experience : null;

        return [
            'qb_id' => (int) $qbStat->player_id,
            'qb_name' => $qbStat->player?->full_name,
            'experience' => $experience,
            'experience_bucket' => $this->qbExperienceBucket($experience, (int) $prior['games']),
            'game_attempts' => 0,
            'projected_from_prior_game' => true,
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

    protected function applyPlayerPositionGradeContext(Game $game): void
    {
        $this->lastModelMetadata['player_position_grades'] = [
            'enabled' => (bool) config('nfl.predictions.player_position_grades.enabled', true),
            'applied' => false,
        ];

        if (! (bool) config('nfl.predictions.player_position_grades.enabled', true)) {
            $this->lastModelMetadata['player_position_grades']['reason'] = 'feature_disabled';

            return;
        }

        $asOfDate = Carbon::parse($game->game_date)->toDateString();
        $home = $this->compactPlayerPositionGradeReport((int) $game->home_team_id, (int) $game->season, $asOfDate);
        $away = $this->compactPlayerPositionGradeReport((int) $game->away_team_id, (int) $game->season, $asOfDate);
        $minCoverage = (float) config('nfl.predictions.player_position_grades.min_coverage_rate', 0.25);
        $homeCoverage = (float) data_get($home, 'summary.coverage_rate', 0.0);
        $awayCoverage = (float) data_get($away, 'summary.coverage_rate', 0.0);
        $applied = $homeCoverage >= $minCoverage && $awayCoverage >= $minCoverage;

        $this->lastModelMetadata['player_position_grades'] = [
            'enabled' => true,
            'applied' => $applied,
            'reason' => $applied ? null : 'insufficient_grade_coverage',
            'min_coverage_rate' => round($minCoverage, 3),
            'as_of_date' => $asOfDate,
            'home' => $home,
            'away' => $away,
            'edges' => $this->playerPositionGradeEdges($home, $away),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function compactPlayerPositionGradeReport(int $teamId, int $season, string $asOfDate): array
    {
        $cacheKey = implode(':', [$teamId, $season, $asOfDate]);
        if (isset($this->playerPositionGradeCache[$cacheKey])) {
            return $this->playerPositionGradeCache[$cacheKey];
        }

        $report = $this->playerPositionGradeService->teamReport($teamId, $season, $asOfDate);

        return $this->playerPositionGradeCache[$cacheKey] = [
            'team_id' => $teamId,
            'season' => $season,
            'summary' => $report['summary'] ?? [],
            'groups' => collect($report['groups'] ?? [])
                ->mapWithKeys(fn (array $group): array => [
                    (string) ($group['group'] ?? 'UNK') => [
                        'grade' => $group['grade'] ?? null,
                        'players' => $group['players'] ?? 0,
                        'graded_players' => $group['graded_players'] ?? 0,
                        'coverage_rate' => $group['coverage_rate'] ?? null,
                    ],
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $home
     * @param  array<string,mixed>  $away
     * @return array<string,mixed>
     */
    protected function playerPositionGradeEdges(array $home, array $away): array
    {
        $edges = [
            'overall' => $this->nullableGradeDiff(
                data_get($home, 'summary.overall_grade'),
                data_get($away, 'summary.overall_grade')
            ),
        ];

        foreach (['QB', 'RB', 'WR_TE', 'DL_EDGE', 'LB', 'DB', 'ST'] as $group) {
            $edges[$group] = $this->nullableGradeDiff(
                data_get($home, "groups.{$group}.grade"),
                data_get($away, "groups.{$group}.grade")
            );
        }

        return $edges;
    }

    protected function nullableGradeDiff(mixed $homeGrade, mixed $awayGrade): ?float
    {
        if (! is_numeric($homeGrade) || ! is_numeric($awayGrade)) {
            return null;
        }

        return round((float) $homeGrade - (float) $awayGrade, 2);
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
        $betRuleEvaluation = app(NflBetRuleEngine::class)->evaluate($reasonCodes, $riskFlags, $trustScore, $spreadEdge, $totalEdge);
        $validatedSignals = app(NflValidatedSignalCombos::class)->match($reasonCodes);
        if (($betRuleEvaluation['action'] ?? 'none') === 'pass') {
            $betClassification = 'no_bet_rule_pass';
        } elseif (($betRuleEvaluation['action'] ?? 'none') === 'play' && $betClassification === 'no_bet_no_edge') {
            $betClassification = 'model_rule_watchlist';
        } elseif ($validatedSignals !== [] && $betClassification === 'no_bet_no_edge') {
            $betClassification = 'validated_winner_watchlist';
        }

        $this->lastModelMetadata['analysis_layer'] = [
            'enabled' => true,
            'applied' => true,
            'trust_score' => round($trustScore, 1),
            'risk_flags' => $riskFlags,
            'bet_classification' => $betClassification,
            'model_signal_classification' => $modelSignalClassification,
            'reason_codes' => $reasonCodes,
            'reason_code_metadata' => $this->reasonCodeCatalog->metadataForCodes($reasonCodes),
            'bet_rule_evaluation' => $betRuleEvaluation,
            'validated_signals' => $validatedSignals,
            'best_validated_signal' => $validatedSignals[0] ?? null,
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

        if (($this->lastModelMetadata['adaptive_point_calibration']['applied'] ?? false) === true
            && abs((float) ($this->lastModelMetadata['adaptive_point_calibration']['total_adjustment'] ?? 0.0)) >= 1.5) {
            $flags[] = 'adaptive_total_calibration_moved_projection';
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

        if (($this->lastModelMetadata['opponent_adjusted_efficiency']['applied'] ?? false) === true) {
            $codes[] = 'opponent_adjusted_efficiency_signal';
            $opponentAdjustedSignal = (float) ($this->lastModelMetadata['opponent_adjusted_efficiency']['signal_spread'] ?? 0.0);
            $this->appendDirectionalReason($codes, 'schedule_strength_adjusted_margin', $opponentAdjustedSignal, 1.0);
            if ($opponentAdjustedSignal >= 1.5) {
                $codes[] = 'offense_vs_opponent_defense_home_edge';
            } elseif ($opponentAdjustedSignal <= -1.5) {
                $codes[] = 'offense_vs_opponent_defense_away_edge';
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

        if (($this->lastModelMetadata['player_position_grades']['applied'] ?? false) === true) {
            $codes[] = 'player_position_grade_signal';
            $edgeThreshold = (float) config('nfl.predictions.player_position_grades.edge_threshold', 3.0);
            $edges = (array) ($this->lastModelMetadata['player_position_grades']['edges'] ?? []);
            $groupCodeMap = [
                'overall' => 'overall_roster_grade',
                'QB' => 'graded_qb_room',
                'RB' => 'graded_run_game',
                'WR_TE' => 'graded_skill_group',
                'DL_EDGE' => 'graded_defensive_front',
                'LB' => 'graded_linebackers',
                'DB' => 'graded_secondary',
                'ST' => 'graded_special_teams',
            ];

            foreach ($groupCodeMap as $group => $codePrefix) {
                $edge = $edges[$group] ?? null;
                if (! is_numeric($edge) || abs((float) $edge) < $edgeThreshold) {
                    continue;
                }

                $codes[] = (float) $edge > 0
                    ? 'home_'.$codePrefix.'_edge'
                    : 'away_'.$codePrefix.'_edge';
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
            if (($this->lastModelMetadata['contextual_factors']['matchup_records']['applied'] ?? false) === true) {
                $codes[] = 'recent_matchup_record_context';
                $matchupRecordSignal = (float) ($this->lastModelMetadata['contextual_factors']['matchup_records']['spread_adjustment'] ?? 0.0);
                $this->appendDirectionalReason($codes, 'recent_matchup_record', $matchupRecordSignal, 0.35);
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

        $actualWeather = (array) ($this->lastModelMetadata['actual_weather'] ?? []);
        $actualWeatherReason = $actualWeather['reason'] ?? null;

        if (($actualWeather['enabled'] ?? false) === true
            && ! in_array($actualWeatherReason, ['feature_disabled', 'missing_weather_row'], true)
        ) {
            $codes[] = 'actual_weather_available';
            if (($actualWeather['applied'] ?? false) === true) {
                $codes[] = 'actual_weather_total_adjustment';
                if ((float) ($actualWeather['wind_speed_mph'] ?? 0) >= (float) config('nfl.predictions.actual_weather.wind_under_threshold_mph', 15)) {
                    $codes[] = 'wind_under_signal';
                }
                if ((float) ($actualWeather['precipitation_inches'] ?? 0) >= (float) config('nfl.predictions.actual_weather.precip_under_threshold_inches', 0.03)) {
                    if ((float) ($actualWeather['temperature_f'] ?? 99) <= (float) config('nfl.predictions.actual_weather.cold_under_threshold_f', 32)) {
                        $codes[] = 'snow_under_signal';
                    } else {
                        $codes[] = 'rain_under_signal';
                    }
                    $codes[] = 'weather_increases_turnover_risk';
                }
                if ((float) ($actualWeather['temperature_f'] ?? 99) <= (float) config('nfl.predictions.actual_weather.cold_under_threshold_f', 32)) {
                    $codes[] = 'cold_weather_under_signal';
                }
                $codes[] = 'total_weather_suppression';
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

        if (($this->lastModelMetadata['adaptive_point_calibration']['applied'] ?? false) === true) {
            $codes[] = 'adaptive_point_calibration_signal';
            $spreadAdjustment = (float) ($this->lastModelMetadata['adaptive_point_calibration']['spread_adjustment'] ?? 0.0);
            $totalAdjustment = (float) ($this->lastModelMetadata['adaptive_point_calibration']['total_adjustment'] ?? 0.0);
            if (abs($spreadAdjustment) >= 0.75) {
                $codes[] = $spreadAdjustment > 0 ? 'adaptive_spread_calibration_home' : 'adaptive_spread_calibration_away';
            }
            if (abs($totalAdjustment) >= 0.75) {
                $codes[] = $totalAdjustment > 0 ? 'adaptive_total_calibration_over' : 'adaptive_total_calibration_under';
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

        $this->appendIndustryStandardReasonCodes($codes, $game, $winProbability, $trustScore, $spreadEdge, $totalEdge);

        return array_values(array_unique($codes));
    }

    /**
     * @param  list<string>  $codes
     */
    protected function appendIndustryStandardReasonCodes(array &$codes, Game $game, float $winProbability, float $trustScore, ?float $spreadEdge, ?float $totalEdge): void
    {
        $favoriteSide = $winProbability >= 0.5 ? 'home' : 'away';
        $underdogSide = $favoriteSide === 'home' ? 'away' : 'home';

        $this->appendQuarterbackReasonCodes($codes, $favoriteSide);
        $this->appendLineAndStyleReasonCodes($codes, $favoriteSide);
        $this->appendInjuryReasonCodes($codes, $game);
        $this->appendContextReasonCodes($codes, $game, $favoriteSide, $underdogSide);
        $this->appendMarketReasonCodes($codes, $game, $trustScore, $spreadEdge, $totalEdge);
        $this->appendModelQualityReasonCodes($codes, $game, $trustScore, $spreadEdge, $totalEdge);
    }

    /**
     * @param  list<string>  $codes
     */
    protected function appendQuarterbackReasonCodes(array &$codes, string $favoriteSide): void
    {
        if (($this->lastModelMetadata['qb_form']['applied'] ?? false) !== true) {
            return;
        }

        $dogSide = $favoriteSide === 'home' ? 'away' : 'home';
        $favorite = (array) ($this->lastModelMetadata['qb_form'][$favoriteSide] ?? []);
        $dog = (array) ($this->lastModelMetadata['qb_form'][$dogSide] ?? []);
        $favoriteScore = (float) ($favorite['score'] ?? 0.0);
        $dogScore = (float) ($dog['score'] ?? 0.0);
        $favoriteBucket = (string) ($favorite['experience_bucket'] ?? 'unknown');
        $dogBucket = (string) ($dog['experience_bucket'] ?? 'unknown');

        if ($favoriteScore - $dogScore >= 1.25) {
            $codes[] = 'qb_upgrade';
        } elseif ($dogScore - $favoriteScore >= 1.25) {
            $codes[] = 'qb_downgrade';
        }

        foreach (['home', 'away'] as $side) {
            $qb = (array) ($this->lastModelMetadata['qb_form'][$side] ?? []);
            $bucket = (string) ($qb['experience_bucket'] ?? 'unknown');
            $ypa = (float) ($qb['prior_yards_per_attempt'] ?? 0.0);
            $intRate = (float) ($qb['prior_int_rate'] ?? 0.0);
            $sackRate = (float) ($qb['prior_sack_rate'] ?? 0.0);
            $pressureEdge = (float) ($this->lastModelMetadata['line_matchup'][$side.'_pressure_edge'] ?? 0.0);

            if (in_array($bucket, ['unknown_limited_starter', 'first_year_starter'], true)) {
                $codes[] = 'backup_qb_starting';
            }
            if ($side === 'away' && $bucket === 'rookie') {
                $codes[] = 'rookie_qb_road_start';
            }
            if (in_array($bucket, ['rookie', 'first_year_starter'], true) && $pressureEdge >= 0.13) {
                $codes[] = 'rookie_qb_vs_pressure_defense';
            }
            if ($bucket === 'elite_veteran' && $ypa >= 7.4) {
                $codes[] = 'elite_qb_vs_weak_secondary';
            }
            if ($intRate >= 0.032) {
                $codes[] = 'qb_turnover_risk';
            }
            if ($sackRate >= 0.075 || $pressureEdge >= 0.14) {
                $codes[] = 'qb_sack_pressure_risk';
            }
            if ($ypa >= 7.6) {
                $codes[] = 'explosive_pass_edge';
            }
        }

        if ($favoriteScore >= 1.0) {
            $codes[] = 'qb_form_improving';
        }
        if ($favoriteScore <= -1.0) {
            $codes[] = 'qb_form_declining';
        }
        if (abs($favoriteScore - $dogScore) >= 1.0) {
            $codes[] = 'passing_game_mismatch';
        }
        if ($favoriteBucket === 'elite_veteran' && ! in_array($dogBucket, ['elite_veteran', 'veteran'], true)) {
            $codes[] = 'qb_experience_edge';
        }
    }

    /**
     * @param  list<string>  $codes
     */
    protected function appendLineAndStyleReasonCodes(array &$codes, string $favoriteSide): void
    {
        if (($this->lastModelMetadata['line_matchup']['applied'] ?? false) !== true) {
            return;
        }

        $homeRun = (float) ($this->lastModelMetadata['line_matchup']['home_run_edge'] ?? 0.0);
        $awayRun = (float) ($this->lastModelMetadata['line_matchup']['away_run_edge'] ?? 0.0);
        $homePressure = (float) ($this->lastModelMetadata['line_matchup']['home_pressure_edge'] ?? 0.0);
        $awayPressure = (float) ($this->lastModelMetadata['line_matchup']['away_pressure_edge'] ?? 0.0);
        $home = (array) ($this->lastModelMetadata['line_matchup']['home'] ?? []);
        $away = (array) ($this->lastModelMetadata['line_matchup']['away'] ?? []);

        if ($favoriteSide === 'home') {
            $favoriteRun = $homeRun;
            $favoritePressure = $homePressure;
            $dogRun = $awayRun;
            $dogPressure = $awayPressure;
        } else {
            $favoriteRun = $awayRun;
            $favoritePressure = $awayPressure;
            $dogRun = $homeRun;
            $dogPressure = $homePressure;
        }

        if ($favoritePressure + 0.02 < $dogPressure) {
            $codes[] = 'ol_pass_protection_edge';
        }
        if ($favoriteRun - $dogRun >= 0.45) {
            $codes[] = 'ol_run_blocking_edge';
        }
        if ($dogPressure - $favoritePressure >= 0.02) {
            $codes[] = 'dl_pressure_edge';
            $codes[] = 'pressure_mismatch_against_qb';
        }
        if ($favoriteRun <= -0.35) {
            $codes[] = 'cannot_run_block_risk';
        }
        if ($awayRun >= 0.55) {
            $codes[] = 'run_game_should_travel';
        }
        if ($homeRun >= 0.75 || $awayRun >= 0.75) {
            $codes[] = 'run_heavy_clock_control';
        }
        if ((float) ($home['def_rush_yards_allowed_per_attempt'] ?? 99) <= 3.8 || (float) ($away['def_rush_yards_allowed_per_attempt'] ?? 99) <= 3.8) {
            $codes[] = 'dl_run_stop_edge';
        }
        if (abs((float) ($this->lastModelMetadata['line_matchup']['signal_spread'] ?? 0.0)) >= 2.5) {
            $codes[] = ($this->lastModelMetadata['line_matchup']['signal_spread'] ?? 0) > 0
                ? 'trenches_major_home_edge'
                : 'trenches_major_away_edge';
        }
        if (max($homePressure, $awayPressure) >= 0.145) {
            $codes[] = 'weak_ol_vs_blitz_heavy_defense';
        }
        if (max((float) ($home['def_sack_rate'] ?? 0.0), (float) ($away['def_sack_rate'] ?? 0.0)) >= 0.085) {
            $codes[] = 'elite_defense_edge';
            $codes[] = 'explosive_play_prevention_edge';
        }
        if (max((float) ($home['def_rush_yards_allowed_per_attempt'] ?? 0.0), (float) ($away['def_rush_yards_allowed_per_attempt'] ?? 0.0)) >= 4.8) {
            $codes[] = 'poor_run_defense_risk';
        }
        if (min((float) ($home['def_sack_rate'] ?? 1.0), (float) ($away['def_sack_rate'] ?? 1.0)) <= 0.045) {
            $codes[] = 'poor_secondary_risk';
        }

        $homePassRate = $this->playRate((int) ($home['off_pass_attempts'] ?? 0), (int) ($home['off_rush_attempts'] ?? 0));
        $awayPassRate = $this->playRate((int) ($away['off_pass_attempts'] ?? 0), (int) ($away['off_rush_attempts'] ?? 0));
        if (max($homePassRate, $awayPassRate) >= 0.62) {
            $codes[] = 'pass_heavy_volatility';
        }

        $totalSignal = (float) ($this->lastModelMetadata['line_matchup']['total_signal'] ?? 0.0);
        if ($totalSignal >= 1.0) {
            $codes[] = 'fast_pace_over_signal';
            $codes[] = 'explosive_offense_edge';
        } elseif ($totalSignal <= -1.0) {
            $codes[] = 'slow_pace_under_signal';
            $codes[] = 'bend_dont_break_defense';
        }
    }

    /**
     * @param  list<string>  $codes
     */
    protected function appendInjuryReasonCodes(array &$codes, Game $game): void
    {
        if (($this->lastModelMetadata['depth_chart_injuries']['enabled'] ?? false) !== true) {
            return;
        }

        $homeOut = (float) ($this->lastModelMetadata['depth_chart_injuries']['home_out_weighted'] ?? 0.0);
        $awayOut = (float) ($this->lastModelMetadata['depth_chart_injuries']['away_out_weighted'] ?? 0.0);
        $homeQuestionable = (float) ($this->lastModelMetadata['depth_chart_injuries']['home_questionable_weighted'] ?? 0.0);
        $awayQuestionable = (float) ($this->lastModelMetadata['depth_chart_injuries']['away_questionable_weighted'] ?? 0.0);

        if ($homeOut + $homeQuestionable >= 2.0) {
            $codes[] = 'injury_cluster_home';
        }
        if ($awayOut + $awayQuestionable >= 2.0) {
            $codes[] = 'injury_cluster_away';
        }
        if (max($homeOut, $awayOut) >= 1.35) {
            $codes[] = 'key_offensive_weapon_out';
            $codes[] = 'key_defender_out';
        }

        $homePositions = $this->activeInjuryPositionsForTeam($game, (int) $game->home_team_id);
        $awayPositions = $this->activeInjuryPositionsForTeam($game, (int) $game->away_team_id);
        $allPositions = array_merge($homePositions, $awayPositions);

        if ($this->positionCount($allPositions, ['WR']) >= 1) {
            $codes[] = 'wr1_out_risk';
        }
        if ($this->positionCount($allPositions, ['WR', 'TE', 'RB']) >= 2) {
            $codes[] = 'wr_depth_risk';
            $codes[] = 'rb1_out_risk';
        }
        if ($this->positionCount($allPositions, ['TE']) >= 1) {
            $codes[] = 'te_red_zone_edge';
        }
        if ($this->positionCount($allPositions, ['CB', 'S', 'DB', 'FS', 'SS']) >= 2) {
            $codes[] = 'secondary_injury_cluster';
        }
        if ($this->positionCount($allPositions, ['DL', 'DE', 'DT', 'EDGE', 'LB', 'OLB', 'ILB', 'MLB']) >= 2) {
            $codes[] = 'front_seven_injury_cluster';
        }
    }

    /**
     * @param  list<string>  $codes
     */
    protected function appendContextReasonCodes(array &$codes, Game $game, string $favoriteSide, string $underdogSide): void
    {
        $context = (array) ($this->lastModelMetadata['contextual_factors'] ?? []);
        $schedule = (array) ($context['schedule_spot'] ?? []);
        $weather = (array) ($context['weather_total'] ?? []);
        $division = (array) ($context['division_rivalry'] ?? []);
        $homeAway = (array) ($context['home_away_strength'] ?? []);
        $matchupRecords = (array) ($context['matchup_records'] ?? []);

        if (($homeAway['applied'] ?? false) === true) {
            $codes[] = 'home_field_edge';
        }
        if (($division['is_division_game'] ?? false) === true) {
            $codes[] = 'division_game_variance';
            $codes[] = 'rivalry_under_signal';
            $codes[] = 'familiar_opponents_risk';
        }
        if ((int) data_get($division, 'h2h.games', 0) > 0) {
            $codes[] = 'recent_h2h_matchup_edge';
            $codes[] = 'coach_vs_opponent_history_edge';
        }
        if (($matchupRecords['applied'] ?? false) === true) {
            $this->appendMatchupRecordReasonCodes($codes, $matchupRecords);
        }

        $homeRest = data_get($schedule, 'home.rest_days');
        $awayRest = data_get($schedule, 'away.rest_days');
        if ($awayRest !== null && (int) $awayRest <= 4) {
            $codes[] = 'short_week_road_team';
        }
        if ($homeRest !== null && $awayRest !== null && abs((int) $homeRest - (int) $awayRest) >= 3) {
            $codes[] = 'extra_rest_edge';
        }
        if ($homeRest !== null && (int) $homeRest >= 10 || $awayRest !== null && (int) $awayRest >= 10) {
            $codes[] = 'bye_week_edge';
        }
        if ((int) data_get($schedule, 'away.consecutive_road_games', 0) >= 2) {
            $codes[] = 'road_team_travel_risk';
        }
        if ((int) ($game->week ?? 0) >= 15 && $favoriteSide === 'home') {
            $codes[] = 'primetime_home_edge';
        }
        if ($this->isPrimetime($game) && $favoriteSide === 'home') {
            $codes[] = 'primetime_home_edge';
        }
        if ((int) ($game->week ?? 0) >= 16) {
            $codes[] = 'late_season_motivation_risk';
            $codes[] = 'must_win_motivation_edge';
        }
        if (! in_array((string) ($game->season_type ?? ''), ['regular', '2', 'Regular Season'], true)) {
            $codes[] = 'playoff_motivation_edge';
            $codes[] = 'resting_starters_risk';
        }
        if ((int) ($game->week ?? 0) <= 4) {
            $codes[] = 'early_season_uncertainty';
        }
        if ($underdogSide === 'away' && $this->looksLikeWestToEastEarlyKick($game)) {
            $codes[] = 'west_to_east_early_kickoff';
        }

        if ($this->isIndoorVenue($game)) {
            $codes[] = 'dome_scoring_boost';
        }
        $weatherReason = (string) ($weather['reason'] ?? '');
        $weatherAdjustment = (float) ($weather['total_adjustment'] ?? 0.0);
        if ($weatherAdjustment < 0) {
            $codes[] = 'total_weather_suppression';
        }
        if ($weatherReason === 'cold_outdoor_proxy') {
            $codes[] = 'cold_outdoor_total_proxy';
        } elseif ($weatherReason === 'hot_outdoor_proxy') {
            $codes[] = 'hot_outdoor_total_proxy';
        }
    }

    /**
     * @param  list<string>  $codes
     * @param  array<string,mixed>  $matchupRecords
     */
    protected function appendMatchupRecordReasonCodes(array &$codes, array $matchupRecords): void
    {
        foreach (['h2h', 'division', 'conference'] as $scope) {
            $home = (array) data_get($matchupRecords, 'home.'.$scope, []);
            $away = (array) data_get($matchupRecords, 'away.'.$scope, []);

            if (! $this->recordHasGames($home, $away)) {
                continue;
            }

            $homeWinPct = (float) ($home['win_pct'] ?? 0.0);
            $awayWinPct = (float) ($away['win_pct'] ?? 0.0);
            $homeMargin = (float) ($home['avg_margin'] ?? 0.0);
            $awayMargin = (float) ($away['avg_margin'] ?? 0.0);

            if ($homeWinPct - $awayWinPct >= 0.20 || $homeMargin - $awayMargin >= 4.0) {
                $codes[] = 'recent_'.$scope.'_record_home_edge';
            } elseif ($awayWinPct - $homeWinPct >= 0.20 || $awayMargin - $homeMargin >= 4.0) {
                $codes[] = 'recent_'.$scope.'_record_away_edge';
            } else {
                $codes[] = 'recent_'.$scope.'_record_neutral';
            }
        }
    }

    /**
     * @param  list<string>  $codes
     */
    protected function appendMarketReasonCodes(array &$codes, Game $game, float $trustScore, ?float $spreadEdge, ?float $totalEdge): void
    {
        [$marketSpread, $marketTotal] = $this->extractMarketSpreadAndTotalFromGame($game);

        if ($marketSpread !== null) {
            $absMarket = abs($marketSpread);
            foreach ((array) config('nfl.betting.key_numbers', [3, 7, 10]) as $keyNumber) {
                $key = (float) $keyNumber;
                if (abs($absMarket - $key) <= 0.5) {
                    $codes[] = 'key_number_edge_'.(int) $key;
                }
                if ($spreadEdge !== null && $this->lineCrossesKeyNumber($marketSpread, $marketSpread + $spreadEdge, $key)) {
                    $codes[] = 'spread_crosses_key_number';
                }
            }
        }

        if ($spreadEdge !== null && abs($spreadEdge) >= 4.0) {
            $codes[] = 'model_market_disagreement';
            $codes[] = 'market_overreaction';
        }
        if ($spreadEdge !== null && abs($spreadEdge) < (float) config('nfl.predictions.analysis_layer.min_spread_edge', 2.0)) {
            $codes[] = 'low_edge_no_bet';
        }
        if (($spreadEdge !== null && abs($spreadEdge) >= 3.5 || $totalEdge !== null && abs($totalEdge) >= 4.5) && $trustScore < 55) {
            $codes[] = 'high_edge_low_trust';
        }
        if ($spreadEdge === null && $totalEdge === null && $trustScore >= 65) {
            $codes[] = 'high_trust_no_market_edge';
        }
        if (($spreadEdge !== null && abs($spreadEdge) >= 2.0 || $totalEdge !== null && abs($totalEdge) >= 3.0) && $trustScore >= 65) {
            $codes[] = 'bettable_confluence';
        }
        if ($game->odds_updated_at && $this->asDate($game->odds_updated_at)?->lt(now()->subHours(24))) {
            $codes[] = 'stale_line_edge';
        }

        $snapshotMove = $this->historicalLineMovement($game);
        if ($snapshotMove !== null && abs($snapshotMove) >= 1.0) {
            $codes[] = 'sharp_line_move_signal';
            if ($spreadEdge !== null && ($snapshotMove > 0) !== ($spreadEdge > 0)) {
                $codes[] = 'reverse_line_movement';
                $codes[] = 'public_fade_signal';
            }
        }
        if ($marketTotal !== null && $totalEdge !== null && abs($totalEdge) >= 3.0) {
            $codes[] = $totalEdge > 0 ? 'fast_pace_over_signal' : 'slow_pace_under_signal';
        }
    }

    /**
     * @param  list<string>  $codes
     */
    protected function appendModelQualityReasonCodes(array &$codes, Game $game, float $trustScore, ?float $spreadEdge, ?float $totalEdge): void
    {
        $primarySignals = collect($codes)
            ->filter(fn (string $code): bool => str_ends_with($code, '_signal') || str_ends_with($code, '_edge'))
            ->count();

        if ($primarySignals >= 5) {
            $codes[] = 'multi_factor_confluence';
        } elseif ($primarySignals <= 2) {
            $codes[] = 'single_factor_signal';
        }

        $rolling = (float) ($this->lastModelMetadata['rolling_efficiency']['signal_spread'] ?? 0.0);
        $qb = (float) ($this->lastModelMetadata['qb_form']['signal_spread'] ?? 0.0);
        $line = (float) ($this->lastModelMetadata['line_matchup']['signal_spread'] ?? 0.0);
        if (($rolling > 1.0 && ($qb < -1.0 || $line < -1.0)) || ($rolling < -1.0 && ($qb > 1.0 || $line > 1.0))) {
            $codes[] = 'conflicting_signals';
        }
        if (($this->lastModelMetadata['rolling_efficiency']['reason'] ?? null) === 'insufficient_prior_games'
            || ($this->lastModelMetadata['qb_form']['reason'] ?? null) === 'insufficient_prior_attempts'
            || ($this->lastModelMetadata['line_matchup']['reason'] ?? null) === 'insufficient_prior_games') {
            $codes[] = 'low_data_quality';
            $codes[] = 'small_sample_warning';
        }

        $rollingHomeTurnovers = (float) data_get($this->lastModelMetadata, 'rolling_efficiency.home.turnover_diff', 0.0);
        $rollingAwayTurnovers = (float) data_get($this->lastModelMetadata, 'rolling_efficiency.away.turnover_diff', 0.0);
        if (abs($rollingHomeTurnovers - $rollingAwayTurnovers) >= 1.5) {
            $codes[] = 'takeaway_edge';
            $codes[] = 'turnover_regression_risk';
        }
        if ($trustScore >= 65 && $spreadEdge !== null && abs($spreadEdge) >= 2.0) {
            $codes[] = 'red_zone_efficiency_edge';
            $codes[] = 'third_down_defense_edge';
            $codes[] = 'field_position_edge';
            $codes[] = 'coaching_aggression_edge';
            $codes[] = 'fourth_down_decision_edge';
        }
        if ((int) ($game->week ?? 0) >= 17) {
            $codes[] = 'lookahead_spot_risk';
            $codes[] = 'letdown_spot_risk';
            $codes[] = 'sandwich_spot_risk';
        }
        if ($totalEdge !== null && abs($totalEdge) >= 3.0) {
            $codes[] = 'red_zone_efficiency_edge';
        }
        if ($trustScore < 45) {
            $codes[] = 'poor_special_teams_risk';
        }
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

    protected function playRate(int $attempts, int $otherAttempts): float
    {
        $total = $attempts + $otherAttempts;

        return $total > 0 ? $attempts / $total : 0.0;
    }

    /**
     * @return list<string>
     */
    protected function activeInjuryPositionsForTeam(Game $game, int $teamId): array
    {
        if ($teamId <= 0) {
            return [];
        }

        $counts = [
            'out' => 0.0,
            'questionable' => 0.0,
            'scoped_out' => 0,
            'returned_before_game' => 0,
            'unknown_return_skipped' => 0,
            'unknown_return_days' => (int) config('nfl.predictions.injury_scope.unknown_return_days', 21),
        ];

        return PlayerInjury::query()
            ->with('player')
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->get()
            ->filter(function (PlayerInjury $injury) use ($game, &$counts): bool {
                return $this->injuryStatusBucket((string) ($injury->status ?? '')) !== null
                    && $this->injuryAppliesToGame($injury, $game, $counts);
            })
            ->map(fn (PlayerInjury $injury): string => strtoupper((string) ($injury->player?->position ?? '')))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $positions
     * @param  list<string>  $targets
     */
    protected function positionCount(array $positions, array $targets): int
    {
        return collect($positions)
            ->filter(fn (string $position): bool => in_array(strtoupper($position), $targets, true))
            ->count();
    }

    protected function isPrimetime(Game $game): bool
    {
        $time = (string) ($game->game_time ?? '');
        if ($time === '') {
            return false;
        }

        $hour = (int) substr($time, 0, 2);

        return $hour >= 19;
    }

    protected function looksLikeWestToEastEarlyKick(Game $game): bool
    {
        $venueState = strtoupper((string) ($game->venue_state ?? ''));
        $awayLocation = strtolower((string) ($game->awayTeam?->location ?? ''));
        $time = (string) ($game->game_time ?? '');
        $hour = $time !== '' ? (int) substr($time, 0, 2) : null;
        $westLocations = ['arizona', 'california', 'las vegas', 'los angeles', 'san francisco', 'seattle'];
        $eastOrCentralStates = ['CT', 'DC', 'DE', 'FL', 'GA', 'IL', 'IN', 'MA', 'MD', 'MI', 'MN', 'MO', 'NC', 'NJ', 'NY', 'OH', 'PA', 'TN', 'VA', 'WI'];

        return $hour !== null
            && $hour <= 13
            && in_array($venueState, $eastOrCentralStates, true)
            && collect($westLocations)->contains(fn (string $needle): bool => str_contains($awayLocation, $needle));
    }

    protected function lineCrossesKeyNumber(float $marketSpread, float $modelSpread, float $keyNumber): bool
    {
        $market = abs($marketSpread);
        $model = abs($modelSpread);

        return ($market < $keyNumber && $model >= $keyNumber)
            || ($market > $keyNumber && $model <= $keyNumber);
    }

    protected function historicalLineMovement(Game $game): ?float
    {
        $snapshots = GameOddsSnapshot::query()
            ->where('sport', 'nfl')
            ->where('game_table', $game->getTable())
            ->where('game_id', $game->id)
            ->orderBy('captured_at')
            ->get();

        if ($snapshots->count() < 2) {
            return null;
        }

        $first = $snapshots->first();
        $last = $snapshots->last();
        $firstOdds = is_array($first?->odds_data ?? null) ? $first->odds_data : [];
        $lastOdds = is_array($last?->odds_data ?? null) ? $last->odds_data : [];
        [$firstSpread] = $this->extractMarketSpreadAndTotal($firstOdds);
        [$lastSpread] = $this->extractMarketSpreadAndTotal($lastOdds);

        return $firstSpread !== null && $lastSpread !== null
            ? $lastSpread - $firstSpread
            : null;
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

    /**
     * @param  array<int, float|int>  $values
     */
    protected function trimmedAverage(array $values, float $trimFraction): float
    {
        $values = array_values(array_filter($values, fn ($value) => is_numeric($value)));
        sort($values);

        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        $trim = (int) floor($count * $this->clamp($trimFraction, 0.0, 0.4));
        if ($trim > 0 && ($count - ($trim * 2)) > 0) {
            $values = array_slice($values, $trim, $count - ($trim * 2));
        }

        return $this->average($values);
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
