<?php

namespace App\Actions\NFL;

use App\Models\NFL\EloRating;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\TeamMetric;
use App\Services\Sports\DepthChartImpactService;

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
        $confidenceScore = max($winProbability, 1 - $winProbability) * 100;

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

        $spreadCoefficient = (float) config('nfl.prediction.spread_to_probability_coefficient', 7.0);
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

        $spreadCoefficient = (float) config('nfl.prediction.spread_to_probability_coefficient', 7.0);
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
        $homeCounts = $this->injuryCountsForTeam((int) $game->home_team_id, (int) $game->season);
        $awayCounts = $this->injuryCountsForTeam((int) $game->away_team_id, (int) $game->season);

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
            'applied' => round($spreadAdj, 3) !== 0.0 || round($totalAdj, 3) !== 0.0,
            'home_out_weighted' => round($homeCounts['out'], 2),
            'away_out_weighted' => round($awayCounts['out'], 2),
            'home_questionable_weighted' => round($homeCounts['questionable'], 2),
            'away_questionable_weighted' => round($awayCounts['questionable'], 2),
            'spread_adjustment' => round($spreadAdj, 2),
            'total_adjustment' => round($totalAdj, 2),
            'win_probability_adjustment' => round($spreadAdj * $winAdjPerPoint, 4),
        ];

        return [$adjustedSpread, $adjustedWin, $adjustedTotal];
    }

    /**
     * @return array{out:float,questionable:float}
     */
    protected function injuryCountsForTeam(int $teamId, int $season): array
    {
        $counts = ['out' => 0.0, 'questionable' => 0.0];

        if ($teamId <= 0) {
            return $counts;
        }

        $injuries = PlayerInjury::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->get(['player_id', 'status']);

        foreach ($injuries as $injury) {
            $bucket = $this->injuryStatusBucket((string) ($injury->status ?? ''));
            if ($bucket === null) {
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

    protected function getEloAtDate(int $teamId, mixed $gameDate): float
    {
        $eloRecord = EloRating::query()
            ->where('team_id', $teamId)
            ->where('date', '<=', $gameDate)
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

    protected function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
