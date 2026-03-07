<?php

namespace App\Services\BettingRecommendations;

use App\Services\OddsApi\OddsApiService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PlayerPropAnalyzer
{
    public function __construct(
        protected ?OddsApiService $oddsApiService = null
    ) {
        $this->oddsApiService ??= app(OddsApiService::class);
    }

    /**
     * Market-specific feature blending and decision thresholds.
     *
     * @var array<string, array<string, float>>
     */
    protected array $marketProfiles = [
        'player_points' => [
            'season_weight' => 0.30,
            'recent_weight' => 0.35,
            'last5_weight' => 0.25,
            'vs_opp_weight' => 0.07,
            'home_away_weight' => 0.03,
            'min_edge' => 0.04,
            'volatility_floor' => 2.5,
        ],
        'player_rebounds' => [
            'season_weight' => 0.35,
            'recent_weight' => 0.30,
            'last5_weight' => 0.20,
            'vs_opp_weight' => 0.10,
            'home_away_weight' => 0.05,
            'min_edge' => 0.035,
            'volatility_floor' => 2.0,
        ],
        'player_assists' => [
            'season_weight' => 0.30,
            'recent_weight' => 0.35,
            'last5_weight' => 0.20,
            'vs_opp_weight' => 0.10,
            'home_away_weight' => 0.05,
            'min_edge' => 0.04,
            'volatility_floor' => 2.2,
        ],
        'player_threes' => [
            'season_weight' => 0.28,
            'recent_weight' => 0.32,
            'last5_weight' => 0.28,
            'vs_opp_weight' => 0.08,
            'home_away_weight' => 0.04,
            'min_edge' => 0.05,
            'volatility_floor' => 1.2,
        ],
    ];
    /**
     * Analyze player props for any sport and generate betting recommendations
     */
    public function analyzeProps(
        string $sport = 'NBA',
        ?int $minGames = 3,
        ?string $dateFilter = null,
        ?int $gameFilter = null,
        ?string $marketFilter = null
    ): Collection
    {
        $sportConfig = $this->getSportConfig($sport);

        $playerPropModel = $sportConfig['player_prop_model'];

        // Get player props filtered by date/game selection
        $props = $playerPropModel::query()
            ->whereHas('game', function ($q) use ($dateFilter, $gameFilter) {
                if ($dateFilter) {
                    $q->whereDate('game_date', $dateFilter);
                }

                if ($gameFilter) {
                    $q->where('id', $gameFilter);
                }
            })
            ->when($marketFilter !== null && $marketFilter !== '', fn ($query) => $query->where('market', $marketFilter))
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->get();

        $recommendations = collect();

        foreach ($props as $prop) {
            $recommendation = $this->analyzeProp($prop, $minGames, $sportConfig);

            if ($recommendation && $recommendation['confidence'] >= 60) {
                $recommendations->push($recommendation);
            }
        }

        return $recommendations->sortByDesc('confidence')->values();
    }

    /**
     * Analyze a single prop and generate recommendation
     */
    protected function analyzeProp(Model $prop, int $minGames, array $sportConfig): ?array
    {
        // Try to find player by name fuzzy matching
        $playerMatch = $this->findPlayerByName(
            name: $prop->player_name,
            playerModel: $sportConfig['player_model'],
            oddsSportKey: $sportConfig['odds_sport_key'],
            game: $prop->game
        );

        if (! $playerMatch) {
            return null;
        }

        $player = $playerMatch['player'];
        $matchQualityScore = $playerMatch['match_quality_score'];

        // Get stat field based on market
        $statField = $this->getStatFieldForMarket($prop->market);

        if (! $statField) {
            return null;
        }

        $game = $prop->game;
        $opponentId = $game->home_team_id === $player->team_id ? $game->away_team_id : $game->home_team_id;
        $isHome = $game->home_team_id === $player->team_id;

        // Calculate player averages
        $seasonAvg = $this->calculateSeasonAverage($player->id, $statField, $minGames, $sportConfig['player_stat_model']);
        $recentAvg = $this->calculateRecentAverage($player->id, $statField, 10, $sportConfig['player_stat_model']);
        $last5Avg = $this->calculateRecentAverage($player->id, $statField, 5, $sportConfig['player_stat_model']);

        // Advanced stats
        $vsOpponentAvg = $this->calculateVsOpponentAverage($player->id, $opponentId, $statField, $sportConfig['player_stat_model']);
        $homeAwayAvg = $this->calculateHomeAwayAverage($player->id, $isHome, $statField, $sportConfig['player_stat_model']);
        $hitRate = $this->calculateHitRateVsOpponent($player->id, $opponentId, $statField, $prop->line, $sportConfig['player_stat_model']);
        $timesCoveredLast5 = $this->calculateTimesCovered($player->id, $statField, $prop->line, 5, $sportConfig['player_stat_model']);
        $timesCoveredSeason = $this->calculateTimesCovered($player->id, $statField, $prop->line, 82, $sportConfig['player_stat_model']);
        $consistency = $this->calculateConsistency($player->id, $statField, 10, $sportConfig['player_stat_model']);
        $streak = $this->calculateStreak($player->id, $statField, $prop->line, $sportConfig['player_stat_model']);
        $context = $this->buildContextAdjustments(
            game: $game,
            playerId: $player->id,
            playerTeamId: (int) $player->team_id,
            opponentTeamId: (int) $opponentId,
            market: $prop->market,
            sportConfig: $sportConfig
        );
        $dataQualityScore = $this->calculateDataQualityScore(
            seasonSample: $timesCoveredSeason['games'] ?? 0,
            recentSample: $timesCoveredLast5['games'] ?? 0,
            hasVsOpponent: $vsOpponentAvg !== null,
            hasHomeAway: $homeAwayAvg !== null,
            hasConsistency: $consistency !== null,
            hasHitRate: $hitRate !== null
        );

        if ($seasonAvg === null) {
            return null;
        }

        // Calculate edge and confidence with advanced factors
        $analysis = $this->calculateEdge(
            $prop,
            $seasonAvg,
            $recentAvg,
            $last5Avg,
            $vsOpponentAvg,
            $homeAwayAvg,
            $hitRate,
            $isHome,
            $consistency,
            $context,
            $dataQualityScore,
            $matchQualityScore
        );

        if (! $analysis['recommendation']) {
            return null;
        }

        $this->persistPredictionSnapshot($prop, $analysis, $dataQualityScore, $matchQualityScore, $context['combined_factor']);

        return [
            'prop' => $prop,
            'player' => $player,
            'game' => $prop->game,
            'market' => $this->formatMarketName($prop->market),
            'line' => $prop->line,
            'recommendation' => $analysis['recommendation'],
            'odds' => $analysis['odds'],
            'confidence' => $analysis['confidence'],
            'season_avg' => round($seasonAvg, 1),
            'recent_avg' => round($recentAvg ?? $seasonAvg, 1),
            'last5_avg' => round($last5Avg ?? $seasonAvg, 1),
            'vs_opponent_avg' => $vsOpponentAvg ? round($vsOpponentAvg, 1) : null,
            'home_away_avg' => $homeAwayAvg ? round($homeAwayAvg, 1) : null,
            'hit_rate_vs_opponent' => $hitRate,
            'times_covered_last5' => $timesCoveredLast5,
            'times_covered_season' => $timesCoveredSeason,
            'consistency' => $consistency,
            'streak' => $streak,
            'edge' => $analysis['edge'],
            'model_over_probability' => $analysis['model_over_probability'] ?? null,
            'market_over_probability' => $analysis['market_over_probability'] ?? null,
            'edge_probability' => $analysis['edge_probability'] ?? null,
            'reasoning' => $analysis['reasoning'],
            'context' => $context,
            'data_quality_score' => $dataQualityScore,
            'match_quality_score' => $matchQualityScore,
            'confidence_decomposition' => $analysis['confidence_decomposition'] ?? null,
        ];
    }

    /**
     * Calculate edge and generate recommendation
     */
    protected function calculateEdge(
        Model $prop,
        float $seasonAvg,
        ?float $recentAvg,
        ?float $last5Avg,
        ?float $vsOpponentAvg,
        ?float $homeAwayAvg,
        ?array $hitRate,
        bool $isHome,
        ?array $consistency,
        array $context,
        int $dataQualityScore,
        int $matchQualityScore
    ): array {
        $profile = $this->marketProfiles[$prop->market] ?? [
            'season_weight' => 0.33,
            'recent_weight' => 0.33,
            'last5_weight' => 0.24,
            'vs_opp_weight' => 0.07,
            'home_away_weight' => 0.03,
            'min_edge' => 0.04,
            'volatility_floor' => 2.0,
        ];

        $line = (float) $prop->line;
        $projection = $this->buildProjection(
            seasonAvg: $seasonAvg,
            recentAvg: $recentAvg,
            last5Avg: $last5Avg,
            vsOpponentAvg: $vsOpponentAvg,
            homeAwayAvg: $homeAwayAvg,
            profile: $profile
        ) * ($context['combined_factor'] ?? 1.0);
        $projectionDiff = $projection - $line;

        $volatility = $this->estimateVolatility($consistency, $profile['volatility_floor']);
        $parametricOverProbability = $this->probabilityOverLine($projection, $line, $volatility);
        $simulatedOverProbability = $this->simulationOverProbability(
            mean: $projection,
            line: $line,
            stdDev: $volatility,
            iterations: 300
        );
        $modelOverProbability = ($parametricOverProbability * 0.65) + ($simulatedOverProbability * 0.35);
        $marketOverProbability = $this->fairMarketOverProbability(
            overOdds: $prop->over_price,
            underOdds: $prop->under_price
        );
        $marketUnderProbability = 1 - $marketOverProbability;
        $modelUnderProbability = 1 - $modelOverProbability;

        $overEdgeProbability = $modelOverProbability - $marketOverProbability;
        $underEdgeProbability = $modelUnderProbability - $marketUnderProbability;

        $recommendation = null;
        $odds = null;
        $confidence = 0;
        $reasoning = [];
        $edgeProbability = 0.0;
        $minEdge = $profile['min_edge'];

        if ($overEdgeProbability >= $minEdge) {
            $recommendation = 'Over';
            $odds = $prop->over_price;
            $edgeProbability = $overEdgeProbability;
        } elseif ($underEdgeProbability >= $minEdge) {
            $recommendation = 'Under';
            $odds = $prop->under_price;
            $edgeProbability = $underEdgeProbability;
        }

        if ($recommendation === null) {
            return [
                'recommendation' => null,
                'odds' => null,
                'confidence' => 0,
                'edge' => round($projectionDiff, 1),
                'model_over_probability' => round($modelOverProbability * 100, 1),
                'market_over_probability' => round($marketOverProbability * 100, 1),
                'edge_probability' => round(max($overEdgeProbability, $underEdgeProbability) * 100, 1),
                'reasoning' => [
                    sprintf(
                        'No edge threshold met: model over %.1f%% vs market over %.1f%%.',
                        $modelOverProbability * 100,
                        $marketOverProbability * 100
                    ),
                ],
                'confidence_decomposition' => [
                    'model_edge_score' => 0,
                    'data_quality_score' => $dataQualityScore,
                    'match_quality_score' => $matchQualityScore,
                    'context_factor' => round((float) ($context['combined_factor'] ?? 1.0), 3),
                ],
            ];
        }

        // Scale edge into a 0-100 score without saturating too quickly.
        $modelEdgeScore = (int) round(max(0, min(100, ($edgeProbability / 0.20) * 100)));
        $confidence = (int) round(
            42
            + ($modelEdgeScore * 0.34)
            + ($dataQualityScore * 0.16)
            + ($matchQualityScore * 0.10)
        );
        $reasoning[] = sprintf(
            'Model %s probability %.1f%% vs market implied %.1f%%.',
            strtolower($recommendation),
            ($recommendation === 'Over' ? $modelOverProbability : $modelUnderProbability) * 100,
            ($recommendation === 'Over' ? $marketOverProbability : $marketUnderProbability) * 100
        );
        $reasoning[] = sprintf(
            'Probability blend: parametric %.1f%%, simulation %.1f%%.',
            $parametricOverProbability * 100,
            $simulatedOverProbability * 100
        );
        $reasoning[] = sprintf(
            'Projection %.1f vs line %.1f (edge %.1f).',
            $projection,
            $line,
            $projectionDiff
        );
        $reasoning[] = sprintf(
            'Context multiplier %.3f (pace %.3f, opponent %.3f, minutes %.3f).',
            $context['combined_factor'] ?? 1.0,
            $context['pace_factor'] ?? 1.0,
            $context['opponent_factor'] ?? 1.0,
            $context['minutes_factor'] ?? 1.0
        );

        if ($hitRate && $hitRate['games'] >= 3) {
            $hitRatePercent = ($hitRate['hits'] / $hitRate['games']) * 100;
            $reasoning[] = sprintf(
                'Vs opponent hit rate over %.1f: %d/%d (%.0f%%).',
                $line,
                $hitRate['hits'],
                $hitRate['games'],
                $hitRatePercent
            );
        }

        if ($consistency && isset($consistency['std_dev'])) {
            $stdDev = (float) $consistency['std_dev'];
            if ($stdDev <= 3.0) {
                $confidence += 4;
                $reasoning[] = 'Low volatility profile supports confidence.';
            } elseif ($stdDev >= 7.0) {
                $confidence -= 10;
                $reasoning[] = 'High volatility profile reduces confidence.';
            } elseif ($stdDev >= 5.5) {
                $confidence -= 4;
            }
        }

        if ($odds !== null && $odds > 0) {
            $confidence += 2;
            $reasoning[] = sprintf('Positive odds (+%d) improve expected value.', $odds);
        }

        if ($edgeProbability < 0.06) {
            $confidence -= 8;
        }

        return [
            'recommendation' => $recommendation,
            'odds' => $odds,
            'confidence' => round(max(35, min(96, $confidence))),
            'edge' => round($projectionDiff, 1),
            'model_over_probability' => round($modelOverProbability * 100, 1),
            'market_over_probability' => round($marketOverProbability * 100, 1),
            'edge_probability' => round($edgeProbability * 100, 1),
            'reasoning' => $reasoning,
            'confidence_decomposition' => [
                'model_edge_score' => $modelEdgeScore,
                'data_quality_score' => $dataQualityScore,
                'match_quality_score' => $matchQualityScore,
                'context_factor' => round((float) ($context['combined_factor'] ?? 1.0), 3),
            ],
        ];
    }

    protected function buildProjection(
        float $seasonAvg,
        ?float $recentAvg,
        ?float $last5Avg,
        ?float $vsOpponentAvg,
        ?float $homeAwayAvg,
        array $profile
    ): float {
        $projection = $seasonAvg * $profile['season_weight']
            + ($recentAvg ?? $seasonAvg) * $profile['recent_weight']
            + ($last5Avg ?? $seasonAvg) * $profile['last5_weight'];

        if ($vsOpponentAvg !== null) {
            $projection += $vsOpponentAvg * $profile['vs_opp_weight'];
        } else {
            $projection += $seasonAvg * $profile['vs_opp_weight'];
        }

        if ($homeAwayAvg !== null) {
            $projection += $homeAwayAvg * $profile['home_away_weight'];
        } else {
            $projection += $seasonAvg * $profile['home_away_weight'];
        }

        return $projection;
    }

    protected function estimateVolatility(?array $consistency, float $floor): float
    {
        $stdDev = isset($consistency['std_dev']) ? (float) $consistency['std_dev'] : $floor;

        return max($floor, $stdDev);
    }

    protected function fairMarketOverProbability(?int $overOdds, ?int $underOdds): float
    {
        $over = $this->impliedProbabilityFromAmericanOdds($overOdds);
        $under = $this->impliedProbabilityFromAmericanOdds($underOdds);

        if ($over === null || $under === null) {
            return 0.5;
        }

        $total = $over + $under;

        return $total > 0 ? $over / $total : 0.5;
    }

    protected function impliedProbabilityFromAmericanOdds(?int $odds): ?float
    {
        if ($odds === null || $odds === 0) {
            return null;
        }

        if ($odds > 0) {
            return 100 / ($odds + 100);
        }

        return abs($odds) / (abs($odds) + 100);
    }

    protected function probabilityOverLine(float $mean, float $line, float $stdDev): float
    {
        if ($stdDev <= 0) {
            return $mean > $line ? 1.0 : 0.0;
        }

        $z = ($line - $mean) / $stdDev;
        $cdf = $this->normalCdf($z);

        return max(0.001, min(0.999, 1 - $cdf));
    }

    protected function simulationOverProbability(float $mean, float $line, float $stdDev, int $iterations = 300): float
    {
        if ($stdDev <= 0 || $iterations < 20) {
            return $mean > $line ? 1.0 : 0.0;
        }

        $overCount = 0;
        for ($i = 1; $i <= $iterations; $i++) {
            // Deterministic quantile sampling keeps results stable across runs.
            $u = $i / ($iterations + 1);
            $z = $this->inverseNormalCdf($u);
            $sample = $mean + ($z * $stdDev);

            if ($sample > $line) {
                $overCount++;
            }
        }

        return max(0.001, min(0.999, $overCount / $iterations));
    }

    protected function inverseNormalCdf(float $p): float
    {
        $p = max(1e-12, min(1 - 1e-12, $p));

        // Peter J. Acklam inverse normal approximation.
        $a = [-39.6968302866538, 220.946098424521, -275.928510446969, 138.357751867269, -30.6647980661472, 2.50662827745924];
        $b = [-54.4760987982241, 161.585836858041, -155.698979859887, 66.8013118877197, -13.2806815528857];
        $c = [-0.00778489400243029, -0.322396458041136, -2.40075827716184, -2.54973253934373, 4.37466414146497, 2.93816398269878];
        $d = [0.00778469570904146, 0.32246712907004, 2.445134137143, 3.75440866190742];

        $plow = 0.02425;
        $phigh = 1 - $plow;

        if ($p < $plow) {
            $q = sqrt(-2 * log($p));

            return (((((($c[0] * $q) + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / ((((($d[0] * $q) + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }

        if ($p > $phigh) {
            $q = sqrt(-2 * log(1 - $p));

            return -(((((($c[0] * $q) + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / ((((($d[0] * $q) + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }

        $q = $p - 0.5;
        $r = $q * $q;

        return (((((($a[0] * $r) + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
            / (((((($b[0] * $r) + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
    }

    protected function normalCdf(float $x): float
    {
        return 0.5 * (1 + $this->erf($x / sqrt(2)));
    }

    protected function erf(float $x): float
    {
        // Abramowitz and Stegun approximation.
        $sign = $x < 0 ? -1 : 1;
        $x = abs($x);
        $a1 = 0.254829592;
        $a2 = -0.284496736;
        $a3 = 1.421413741;
        $a4 = -1.453152027;
        $a5 = 1.061405429;
        $p = 0.3275911;

        $t = 1 / (1 + $p * $x);
        $y = 1 - (((((($a5 * $t) + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);

        return $sign * $y;
    }

    /**
     * @return array{pace_factor: float, opponent_factor: float, minutes_factor: float, combined_factor: float}
     */
    protected function buildContextAdjustments(
        Model $game,
        int $playerId,
        int $playerTeamId,
        int $opponentTeamId,
        string $market,
        array $sportConfig
    ): array {
        $paceFactor = 1.0;
        $opponentFactor = 1.0;
        $minutesFactor = $this->minutesTrendFactor($playerId, $sportConfig['player_stat_model']);

        if (isset($sportConfig['team_metric_model'])) {
            $teamMetricModel = $sportConfig['team_metric_model'];
            $season = (int) ($game->season ?? date('Y'));

            $teamMetric = $teamMetricModel::query()->where('team_id', $playerTeamId)->where('season', $season)->first();
            $oppMetric = $teamMetricModel::query()->where('team_id', $opponentTeamId)->where('season', $season)->first();
            $leaguePace = (float) ($sportConfig['league_pace_baseline'] ?? 100.0);

            if ($teamMetric && $oppMetric && $leaguePace > 0 && isset($teamMetric->tempo, $oppMetric->tempo)) {
                $paceFactor = max(0.90, min(1.10, (((float) $teamMetric->tempo + (float) $oppMetric->tempo) / 2) / $leaguePace));
            }
        }

        if (isset($sportConfig['team_stat_model'])) {
            $allowedField = $this->opponentAllowedStatField($market);
            if ($allowedField !== null) {
                $teamStatModel = $sportConfig['team_stat_model'];
                $opponentQuery = $teamStatModel::query()
                    ->where('team_id', $opponentTeamId)
                    ->whereHas('game', function ($query) use ($game) {
                        $query->where('status', 'STATUS_FINAL')
                            ->whereDate('game_date', '<', $game->game_date);
                    })
                    ->orderByDesc('id')
                    ->take(12);

                $opponentAllowed = (float) ($opponentQuery->avg($allowedField) ?? 0);
                $leagueAllowed = (float) $teamStatModel::query()
                    ->whereHas('game', function ($query) use ($game) {
                        $query->where('status', 'STATUS_FINAL')
                            ->whereDate('game_date', '<', $game->game_date);
                    })
                    ->avg($allowedField);

                if ($opponentAllowed > 0 && $leagueAllowed > 0) {
                    $opponentFactor = max(0.90, min(1.10, $opponentAllowed / $leagueAllowed));
                }
            }
        }

        $combined = max(0.85, min(1.15, $paceFactor * $opponentFactor * $minutesFactor));

        return [
            'pace_factor' => round($paceFactor, 3),
            'opponent_factor' => round($opponentFactor, 3),
            'minutes_factor' => round($minutesFactor, 3),
            'combined_factor' => round($combined, 3),
        ];
    }

    protected function opponentAllowedStatField(string $market): ?string
    {
        return match ($market) {
            'player_points' => 'points',
            'player_rebounds' => 'rebounds',
            'player_assists' => 'assists',
            'player_threes' => 'three_point_made',
            'player_blocks' => 'blocks',
            'player_steals' => 'steals',
            default => null,
        };
    }

    protected function minutesTrendFactor(int $playerId, string $playerStatModel): float
    {
        $stats = $this->finalizedPlayerStatsQuery($playerId, $playerStatModel)->take(16)->get();
        if ($stats->count() < 6) {
            return 1.0;
        }

        $recent = $stats->take(8)->map(fn ($stat) => $this->parseMinutes((string) ($stat->minutes_played ?? '0:00')));
        $prior = $stats->skip(8)->take(8)->map(fn ($stat) => $this->parseMinutes((string) ($stat->minutes_played ?? '0:00')));

        $recentAvg = (float) $recent->avg();
        $priorAvg = (float) $prior->avg();
        if ($recentAvg <= 0 || $priorAvg <= 0) {
            return 1.0;
        }

        $delta = ($recentAvg - $priorAvg) / max(1.0, $priorAvg);

        return max(0.94, min(1.06, 1 + ($delta * 0.30)));
    }

    protected function parseMinutes(string $minutes): float
    {
        if (! str_contains($minutes, ':')) {
            return (float) $minutes;
        }

        [$mm, $ss] = array_pad(explode(':', $minutes, 2), 2, '0');

        return ((float) $mm) + (((float) $ss) / 60);
    }

    protected function calculateDataQualityScore(
        int $seasonSample,
        int $recentSample,
        bool $hasVsOpponent,
        bool $hasHomeAway,
        bool $hasConsistency,
        bool $hasHitRate
    ): int {
        $score = 35;
        $score += min(30, $seasonSample * 2);
        $score += min(10, $recentSample * 2);
        $score += $hasVsOpponent ? 8 : 0;
        $score += $hasHomeAway ? 6 : 0;
        $score += $hasConsistency ? 6 : 0;
        $score += $hasHitRate ? 5 : 0;

        return (int) max(0, min(100, $score));
    }

    protected function persistPredictionSnapshot(
        Model $prop,
        array $analysis,
        int $dataQualityScore,
        int $matchQualityScore,
        float $contextFactor
    ): void {
        $prop->forceFill([
            'recommended_side' => $analysis['recommendation'] ?? null,
            'confidence_score' => $analysis['confidence'] ?? null,
            'predicted_over_probability' => $analysis['model_over_probability'] ?? null,
            'market_over_probability' => $analysis['market_over_probability'] ?? null,
            'edge_probability' => $analysis['edge_probability'] ?? null,
            'data_quality_score' => $dataQualityScore,
            'match_quality_score' => $matchQualityScore,
            'context_adjustment_factor' => $contextFactor,
            'confidence_decomposition' => $analysis['confidence_decomposition'] ?? null,
        ])->saveQuietly();
    }

    /**
     * Calculate season average for a stat
     */
    protected function calculateSeasonAverage(int $playerId, string $statField, int $minGames, string $playerStatModel): ?float
    {
        $stats = $this->finalizedPlayerStatsQuery($playerId, $playerStatModel)
            ->take(82) // Full season
            ->get();

        if ($stats->count() < $minGames) {
            return null;
        }

        return $stats->avg($statField);
    }

    /**
     * Calculate recent N games average
     */
    protected function calculateRecentAverage(int $playerId, string $statField, int $games, string $playerStatModel): ?float
    {
        $stats = $this->finalizedPlayerStatsQuery($playerId, $playerStatModel)
            ->take($games)
            ->get();

        if ($stats->isEmpty()) {
            return null;
        }

        return $stats->avg($statField);
    }

    /**
     * Calculate average vs specific opponent
     */
    protected function calculateVsOpponentAverage(
        int $playerId,
        int $opponentId,
        string $statField,
        string $playerStatModel
    ): ?float {
        $stats = $this->finalizedPlayerStatsQuery($playerId, $playerStatModel)
            ->whereHas('game', function ($q) use ($opponentId) {
                $q->where(function ($query) use ($opponentId) {
                    $query->where('home_team_id', $opponentId)
                        ->orWhere('away_team_id', $opponentId);
                });
            })
            ->take(10) // Last 10 games vs this opponent
            ->get();

        if ($stats->isEmpty()) {
            return null;
        }

        return $stats->avg($statField);
    }

    /**
     * Calculate home or away average
     */
    protected function calculateHomeAwayAverage(
        int $playerId,
        bool $isHome,
        string $statField,
        string $playerStatModel
    ): ?float {
        // Get player's team ID first
        $playerStat = $playerStatModel::where('player_id', $playerId)->first();
        if (! $playerStat || ! $playerStat->player || ! $playerStat->player->team_id) {
            return null;
        }

        $teamId = $playerStat->player->team_id;

        $stats = $this->finalizedPlayerStatsQuery($playerId, $playerStatModel)
            ->whereHas('game', function ($q) use ($isHome, $teamId) {
                if ($isHome) {
                    $q->where('home_team_id', $teamId);
                } else {
                    $q->where('away_team_id', $teamId);
                }
            })
            ->take(20) // Last 20 home or away games
            ->get();

        if ($stats->count() < 3) {
            return null;
        }

        return $stats->avg($statField);
    }

    /**
     * Calculate hit rate vs opponent (how often player crosses the line)
     */
    protected function calculateHitRateVsOpponent(
        int $playerId,
        int $opponentId,
        string $statField,
        float $line,
        string $playerStatModel
    ): ?array {
        $stats = $this->finalizedPlayerStatsQuery($playerId, $playerStatModel)
            ->whereHas('game', function ($q) use ($opponentId) {
                $q->where(function ($query) use ($opponentId) {
                    $query->where('home_team_id', $opponentId)
                        ->orWhere('away_team_id', $opponentId);
                });
            })
            ->take(10) // Last 10 games vs this opponent
            ->get();

        if ($stats->isEmpty()) {
            return null;
        }

        $hits = $stats->filter(fn ($stat) => $stat->{$statField} > $line)->count();

        return [
            'hits' => $hits,
            'games' => $stats->count(),
        ];
    }

    /**
     * Calculate how many times player has covered the line in recent games
     */
    protected function calculateTimesCovered(
        int $playerId,
        string $statField,
        float $line,
        int $games,
        string $playerStatModel
    ): ?array {
        $stats = $this->finalizedPlayerStatsQuery($playerId, $playerStatModel)
            ->take($games)
            ->get();

        if ($stats->isEmpty()) {
            return null;
        }

        $hits = $stats->filter(fn ($stat) => $stat->{$statField} > $line)->count();

        return [
            'hits' => $hits,
            'games' => $stats->count(),
        ];
    }

    /**
     * Calculate consistency (standard deviation) over recent games
     */
    protected function calculateConsistency(
        int $playerId,
        string $statField,
        int $games,
        string $playerStatModel
    ): ?array {
        $stats = $this->finalizedPlayerStatsQuery($playerId, $playerStatModel)
            ->take($games)
            ->get();

        if ($stats->count() < 3) {
            return null;
        }

        $values = $stats->pluck($statField)->toArray();
        $mean = array_sum($values) / count($values);

        // Calculate standard deviation
        $variance = array_sum(array_map(fn ($x) => pow($x - $mean, 2), $values)) / count($values);
        $stdDev = sqrt($variance);

        // Determine consistency level
        $consistency = match (true) {
            $stdDev <= 2.0 => 'Very Consistent',
            $stdDev <= 4.0 => 'Consistent',
            $stdDev <= 6.0 => 'Moderate',
            $stdDev <= 8.0 => 'Volatile',
            default => 'Very Volatile',
        };

        return [
            'std_dev' => round($stdDev, 1),
            'level' => $consistency,
            'min' => min($values),
            'max' => max($values),
        ];
    }

    /**
     * Calculate recent streak (consecutive overs or unders)
     */
    protected function calculateStreak(
        int $playerId,
        string $statField,
        float $line,
        string $playerStatModel
    ): ?array {
        $stats = $this->finalizedPlayerStatsQuery($playerId, $playerStatModel)
            ->take(10)
            ->get();

        if ($stats->count() < 2) {
            return null;
        }

        $streakCount = 0;
        $streakType = null;
        $lastResult = null;

        foreach ($stats as $stat) {
            $isOver = $stat->{$statField} > $line;
            $currentResult = $isOver ? 'over' : 'under';

            if ($lastResult === null) {
                // First game in the sequence
                $lastResult = $currentResult;
                $streakType = $currentResult;
                $streakCount = 1;
            } elseif ($currentResult === $lastResult) {
                // Streak continues
                $streakCount++;
            } else {
                // Streak ends
                break;
            }
        }

        // Only return if there's a meaningful streak (2+)
        if ($streakCount < 2) {
            return null;
        }

        return [
            'count' => $streakCount,
            'type' => $streakType, // 'over' or 'under'
            'status' => $streakType === 'over' ? 'hot' : 'cold',
        ];
    }

    protected function finalizedPlayerStatsQuery(int $playerId, string $playerStatModel)
    {
        return $playerStatModel::where('player_id', $playerId)
            ->whereHas('game', fn ($q) => $q->where('status', 'STATUS_FINAL'))
            ->orderBy('id', 'desc');
    }

    /**
     * Find player by fuzzy name matching
     */
    protected function findPlayerByName(string $name, string $playerModel, string $oddsSportKey, ?Model $game = null): ?array
    {
        $baseQuery = $this->playerBaseQuery($playerModel, $game);
        $mappedEspnName = $this->oddsApiService?->mappedEspnPlayerName($oddsSportKey, $name);

        if ($mappedEspnName) {
            $mappedPlayer = (clone $baseQuery)
                ->where(function (Builder $query) use ($mappedEspnName) {
                    $query->whereRaw('LOWER(full_name) = ?', [mb_strtolower($mappedEspnName)])
                        ->orWhere('full_name', 'like', "%{$mappedEspnName}%");
                })
                ->first();

            if ($mappedPlayer) {
                return [
                    'player' => $mappedPlayer,
                    'match_quality_score' => 98,
                ];
            }
        }

        $player = (clone $baseQuery)
            ->where(function (Builder $query) use ($name) {
                $query->whereRaw('LOWER(full_name) = ?', [mb_strtolower($name)])
                    ->orWhere('full_name', 'like', "%{$name}%");
            })
            ->first();

        if ($player) {
            return [
                'player' => $player,
                'match_quality_score' => 95,
            ];
        }

        $normalizedInput = $this->oddsApiService?->normalizePlayerName($name) ?? mb_strtolower($name);
        $candidate = (clone $baseQuery)->get()
            ->map(function ($player) use ($normalizedInput) {
                $candidateName = $this->oddsApiService?->normalizePlayerName((string) $player->full_name)
                    ?? mb_strtolower((string) $player->full_name);
                similar_text($normalizedInput, $candidateName, $score);

                return ['player' => $player, 'score' => $score];
            })
            ->sortByDesc('score')
            ->first();

        if ($candidate && $candidate['score'] >= 82.0) {
            return [
                'player' => $candidate['player'],
                'match_quality_score' => (int) round(max(82, min(94, $candidate['score']))),
            ];
        }

        $nameParts = explode(' ', trim($normalizedInput));
        $lastName = end($nameParts);

        $lastNameMatch = (clone $baseQuery)
            ->whereRaw('LOWER(last_name) = ?', [$lastName])
            ->first();

        if ($lastNameMatch) {
            return [
                'player' => $lastNameMatch,
                'match_quality_score' => 75,
            ];
        }

        $this->oddsApiService?->rememberUnmappedPlayer($oddsSportKey, $name);

        return null;
    }

    protected function playerBaseQuery(string $playerModel, ?Model $game): Builder
    {
        $query = $playerModel::query()->with('team');

        if ($game && isset($game->home_team_id, $game->away_team_id)) {
            $query->whereIn('team_id', [$game->home_team_id, $game->away_team_id]);
        }

        return $query;
    }

    /**
     * Map prop market to stat field
     */
    protected function getStatFieldForMarket(string $market): ?string
    {
        return match ($market) {
            'player_points' => 'points',
            'player_rebounds' => 'rebounds_total',
            'player_assists' => 'assists',
            'player_threes' => 'three_point_made',
            'player_blocks' => 'blocks',
            'player_steals' => 'steals',
            default => null,
        };
    }

    /**
     * Format market name for display
     */
    protected function formatMarketName(string $market): string
    {
        return match ($market) {
            'player_points' => 'Points',
            'player_rebounds' => 'Rebounds',
            'player_assists' => 'Assists',
            'player_threes' => '3-Pointers Made',
            'player_blocks' => 'Blocks',
            'player_steals' => 'Steals',
            'player_points_rebounds_assists' => 'Points + Rebounds + Assists',
            default => str_replace('_', ' ', ucwords($market, '_')),
        };
    }

    /**
     * Get sport configuration for models and keys
     */
    protected function getSportConfig(string $sport): array
    {
        return match ($sport) {
            'NBA' => [
                'game_model' => 'App\\Models\\NBA\\Game',
                'player_model' => 'App\\Models\\NBA\\Player',
                'player_stat_model' => 'App\\Models\\NBA\\PlayerStat',
                'team_model' => 'App\\Models\\NBA\\Team',
                'team_metric_model' => 'App\\Models\\NBA\\TeamMetric',
                'team_stat_model' => 'App\\Models\\NBA\\TeamStat',
                'player_prop_model' => 'App\\Models\\NBA\\PlayerProp',
                'odds_sport_key' => 'basketball_nba',
                'league_pace_baseline' => 100.0,
            ],
            'MLB' => [
                'game_model' => 'App\\Models\\MLB\\Game',
                'player_model' => 'App\\Models\\MLB\\Player',
                'player_stat_model' => 'App\\Models\\MLB\\PlayerStat',
                'team_model' => 'App\\Models\\MLB\\Team',
                'player_prop_model' => 'App\\Models\\MLB\\PlayerProp',
                'odds_sport_key' => 'baseball_mlb',
            ],
            'NFL' => [
                'game_model' => 'App\\Models\\NFL\\Game',
                'player_model' => 'App\\Models\\NFL\\Player',
                'player_stat_model' => 'App\\Models\\NFL\\PlayerStat',
                'team_model' => 'App\\Models\\NFL\\Team',
                'player_prop_model' => 'App\\Models\\NFL\\PlayerProp',
                'odds_sport_key' => 'americanfootball_nfl',
            ],
            'CBB' => [
                'game_model' => 'App\\Models\\CBB\\Game',
                'player_model' => 'App\\Models\\CBB\\Player',
                'player_stat_model' => 'App\\Models\\CBB\\PlayerStat',
                'team_model' => 'App\\Models\\CBB\\Team',
                'team_metric_model' => 'App\\Models\\CBB\\TeamMetric',
                'team_stat_model' => 'App\\Models\\CBB\\TeamStat',
                'player_prop_model' => 'App\\Models\\CBB\\PlayerProp',
                'odds_sport_key' => 'basketball_ncaab',
                'league_pace_baseline' => 69.0,
            ],
            default => throw new \InvalidArgumentException("Unsupported sport: {$sport}"),
        };
    }

    /**
     * Get available game dates for sport (for filter dropdown)
     */
    public function getAvailableDatesForSport(string $sport): Collection
    {
        $sportConfig = $this->getSportConfig($sport);
        $gameModel = $sportConfig['game_model'];

        return $gameModel::query()
            ->whereHas('playerProps')
            ->selectRaw('DATE(game_date) as date')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($game) {
                $date = is_string($game->date) ? $game->date : $game->date->format('Y-m-d');

                return [
                    'value' => $date,
                    'label' => \Carbon\Carbon::parse($date)->format('l, F j, Y'),
                ];
            });
    }

    /**
     * Get available games/matchups for sport (for filter dropdown)
     */
    public function getAvailableGamesForSport(string $sport, ?string $date = null): Collection
    {
        $sportConfig = $this->getSportConfig($sport);
        $gameModel = $sportConfig['game_model'];

        $query = $gameModel::query()
            ->whereHas('playerProps')
            ->with(['homeTeam', 'awayTeam']);

        if ($date) {
            $query->whereDate('game_date', $date);
        }

        return $query->orderBy('game_date')
            ->orderBy('game_time')
            ->get()
            ->map(function ($game) {
                // Ensure we get just the date part (Y-m-d)
                $gameDate = \Carbon\Carbon::parse($game->game_date)->toDateString();

                // Parse the time separately
                $gameTime = \Carbon\Carbon::parse($game->game_time);

                return [
                    'id' => $game->id,
                    'label' => sprintf(
                        '%s @ %s - %s',
                        $game->awayTeam->abbreviation ?? $game->awayTeam->name,
                        $game->homeTeam->abbreviation ?? $game->homeTeam->name,
                        $gameTime->format('g:i A')
                    ),
                    'date' => $gameDate,
                    'time' => $game->game_time,
                ];
            });
    }

    public function getAvailableMarketsForSport(string $sport, ?string $date = null, ?int $game = null): Collection
    {
        $sportConfig = $this->getSportConfig($sport);
        $playerPropModel = $sportConfig['player_prop_model'];

        $query = $playerPropModel::query();

        if ($date || $game) {
            $query->whereHas('game', function ($q) use ($date, $game) {
                if ($date) {
                    $q->whereDate('game_date', $date);
                }
                if ($game) {
                    $q->where('id', $game);
                }
            });
        }

        return $query
            ->select('market')
            ->distinct()
            ->orderBy('market')
            ->pluck('market')
            ->map(fn ($market) => [
                'value' => $market,
                'label' => $this->formatMarketName((string) $market),
            ])
            ->values();
    }
}
