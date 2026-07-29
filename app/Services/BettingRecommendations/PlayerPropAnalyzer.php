<?php

namespace App\Services\BettingRecommendations;

use App\Services\OddsApi\OddsApiService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlayerPropAnalyzer
{
    protected const SIGNAL_MODEL_VERSION = 'player-prop-signal-v2';

    public function __construct(
        protected ?OddsApiService $oddsApiService = null,
        protected ?PlayerPropNarrativeService $playerPropNarrativeService = null
    ) {
        $this->oddsApiService ??= app(OddsApiService::class);
        $this->playerPropNarrativeService ??= app(PlayerPropNarrativeService::class);
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
        'batter_home_runs' => [
            'season_weight' => 0.32,
            'recent_weight' => 0.30,
            'last5_weight' => 0.26,
            'vs_opp_weight' => 0.08,
            'home_away_weight' => 0.04,
            'min_edge' => 0.055,
            'volatility_floor' => 0.75,
        ],
        'batter_hits' => [
            'season_weight' => 0.34,
            'recent_weight' => 0.32,
            'last5_weight' => 0.22,
            'vs_opp_weight' => 0.08,
            'home_away_weight' => 0.04,
            'min_edge' => 0.04,
            'volatility_floor' => 1.05,
        ],
        'batter_rbis' => [
            'season_weight' => 0.30,
            'recent_weight' => 0.34,
            'last5_weight' => 0.24,
            'vs_opp_weight' => 0.08,
            'home_away_weight' => 0.04,
            'min_edge' => 0.05,
            'volatility_floor' => 1.0,
        ],
        'batter_runs_scored' => [
            'season_weight' => 0.30,
            'recent_weight' => 0.34,
            'last5_weight' => 0.24,
            'vs_opp_weight' => 0.08,
            'home_away_weight' => 0.04,
            'min_edge' => 0.05,
            'volatility_floor' => 1.0,
        ],
        'batter_walks' => [
            'season_weight' => 0.34,
            'recent_weight' => 0.30,
            'last5_weight' => 0.22,
            'vs_opp_weight' => 0.10,
            'home_away_weight' => 0.04,
            'min_edge' => 0.05,
            'volatility_floor' => 0.9,
        ],
        'batter_strikeouts' => [
            'season_weight' => 0.34,
            'recent_weight' => 0.30,
            'last5_weight' => 0.22,
            'vs_opp_weight' => 0.10,
            'home_away_weight' => 0.04,
            'min_edge' => 0.05,
            'volatility_floor' => 1.05,
        ],
        'pitcher_strikeouts' => [
            'season_weight' => 0.35,
            'recent_weight' => 0.33,
            'last5_weight' => 0.22,
            'vs_opp_weight' => 0.06,
            'home_away_weight' => 0.04,
            'min_edge' => 0.045,
            'volatility_floor' => 1.8,
        ],
        'pitcher_hits_allowed' => [
            'season_weight' => 0.35,
            'recent_weight' => 0.33,
            'last5_weight' => 0.22,
            'vs_opp_weight' => 0.06,
            'home_away_weight' => 0.04,
            'min_edge' => 0.045,
            'volatility_floor' => 1.8,
        ],
        'pitcher_walks' => [
            'season_weight' => 0.36,
            'recent_weight' => 0.30,
            'last5_weight' => 0.22,
            'vs_opp_weight' => 0.08,
            'home_away_weight' => 0.04,
            'min_edge' => 0.05,
            'volatility_floor' => 1.05,
        ],
        'pitcher_earned_runs' => [
            'season_weight' => 0.35,
            'recent_weight' => 0.33,
            'last5_weight' => 0.22,
            'vs_opp_weight' => 0.06,
            'home_away_weight' => 0.04,
            'min_edge' => 0.05,
            'volatility_floor' => 1.5,
        ],
        'player_pass_yds' => [
            'season_weight' => 0.34,
            'recent_weight' => 0.33,
            'last5_weight' => 0.20,
            'vs_opp_weight' => 0.08,
            'home_away_weight' => 0.05,
            'min_edge' => 0.045,
            'volatility_floor' => 48.0,
        ],
        'player_pass_tds' => [
            'season_weight' => 0.34,
            'recent_weight' => 0.32,
            'last5_weight' => 0.20,
            'vs_opp_weight' => 0.08,
            'home_away_weight' => 0.06,
            'min_edge' => 0.06,
            'volatility_floor' => 1.05,
        ],
        'player_pass_completions' => [
            'season_weight' => 0.34,
            'recent_weight' => 0.33,
            'last5_weight' => 0.20,
            'vs_opp_weight' => 0.08,
            'home_away_weight' => 0.05,
            'min_edge' => 0.045,
            'volatility_floor' => 5.5,
        ],
        'player_pass_attempts' => [
            'season_weight' => 0.34,
            'recent_weight' => 0.33,
            'last5_weight' => 0.20,
            'vs_opp_weight' => 0.08,
            'home_away_weight' => 0.05,
            'min_edge' => 0.045,
            'volatility_floor' => 7.0,
        ],
        'player_pass_interceptions' => [
            'season_weight' => 0.36,
            'recent_weight' => 0.30,
            'last5_weight' => 0.18,
            'vs_opp_weight' => 0.08,
            'home_away_weight' => 0.08,
            'min_edge' => 0.065,
            'volatility_floor' => 0.85,
        ],
        'player_rush_yds' => [
            'season_weight' => 0.32,
            'recent_weight' => 0.34,
            'last5_weight' => 0.22,
            'vs_opp_weight' => 0.07,
            'home_away_weight' => 0.05,
            'min_edge' => 0.05,
            'volatility_floor' => 22.0,
        ],
        'player_rush_attempts' => [
            'season_weight' => 0.30,
            'recent_weight' => 0.36,
            'last5_weight' => 0.22,
            'vs_opp_weight' => 0.06,
            'home_away_weight' => 0.06,
            'min_edge' => 0.05,
            'volatility_floor' => 5.0,
        ],
        'player_receptions' => [
            'season_weight' => 0.32,
            'recent_weight' => 0.34,
            'last5_weight' => 0.22,
            'vs_opp_weight' => 0.07,
            'home_away_weight' => 0.05,
            'min_edge' => 0.05,
            'volatility_floor' => 2.0,
        ],
        'player_reception_yds' => [
            'season_weight' => 0.32,
            'recent_weight' => 0.34,
            'last5_weight' => 0.22,
            'vs_opp_weight' => 0.07,
            'home_away_weight' => 0.05,
            'min_edge' => 0.05,
            'volatility_floor' => 24.0,
        ],
        'player_anytime_td' => [
            'season_weight' => 0.36,
            'recent_weight' => 0.30,
            'last5_weight' => 0.20,
            'vs_opp_weight' => 0.06,
            'home_away_weight' => 0.08,
            'min_edge' => 0.07,
            'volatility_floor' => 0.55,
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
        ?string $marketFilter = null,
        bool $attachNarratives = true
    ): Collection {
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
            $recommendation = $this->analyzeProp($prop, $minGames, $sportConfig, $sport, $attachNarratives);

            if ($recommendation && $recommendation['confidence'] >= 60) {
                $recommendations->push($recommendation);
            } elseif ($recommendation === null) {
                $this->clearPredictionSnapshot($prop);
            }
        }

        return $recommendations->sortByDesc('confidence')->values();
    }

    public function precomputedRecommendations(
        string $sport = 'NBA',
        ?string $dateFilter = null,
        ?int $gameFilter = null,
        ?string $marketFilter = null,
        int $limit = 75
    ): Collection {
        $sportConfig = $this->getSportConfig($sport);
        $playerPropModel = $sportConfig['player_prop_model'];

        $props = $playerPropModel::query()
            ->whereNotNull('recommended_side')
            ->where('confidence_score', '>=', 60)
            ->whereHas('game', function ($query) use ($dateFilter, $gameFilter): void {
                if ($dateFilter) {
                    $query->whereDate('game_date', $dateFilter);
                }

                if ($gameFilter) {
                    $query->where('id', $gameFilter);
                }
            })
            ->when($marketFilter !== null && $marketFilter !== '', fn ($query) => $query->where('market', $marketFilter))
            ->with(['player.team', 'game.homeTeam', 'game.awayTeam'])
            ->orderByDesc('confidence_score')
            ->orderByDesc('edge_probability')
            ->orderByDesc('fetched_at')
            ->limit(max(1, min($limit, 150)))
            ->get();

        return $props
            ->map(fn (Model $prop): ?array => $this->precomputedRecommendationPayload($prop, $sport))
            ->filter()
            ->values();
    }

    /**
     * @return array<string, int>
     */
    public function precomputedRecommendationDiagnostics(
        string $sport = 'NBA',
        ?string $dateFilter = null,
        ?int $gameFilter = null,
        ?string $marketFilter = null
    ): array {
        $sportConfig = $this->getSportConfig($sport);
        $playerPropModel = $sportConfig['player_prop_model'];

        $baseQuery = $playerPropModel::query()
            ->whereHas('game', function ($query) use ($dateFilter, $gameFilter): void {
                if ($dateFilter) {
                    $query->whereDate('game_date', $dateFilter);
                }

                if ($gameFilter) {
                    $query->where('id', $gameFilter);
                }
            })
            ->when($marketFilter !== null && $marketFilter !== '', fn ($query) => $query->where('market', $marketFilter));

        $candidateQuery = fn (): Builder => (clone $baseQuery)
            ->whereNotNull('recommended_side')
            ->where('confidence_score', '>=', 60);

        return [
            'raw_prop_count' => (clone $baseQuery)->count(),
            'analyzed_prop_count' => (clone $baseQuery)->whereNotNull('recommended_side')->count(),
            'recommendation_candidate_count' => $candidateQuery()->count(),
            'missing_player_link_count' => $candidateQuery()->whereNull('player_id')->count(),
        ];
    }

    /**
     * Analyze a single prop and generate recommendation
     */
    protected function analyzeProp(Model $prop, int $minGames, array $sportConfig, string $sport, bool $attachNarratives = true): ?array
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
        $vsOpponentProfile = $this->calculateVsOpponentProfile($player->id, $opponentId, $statField, $sportConfig['player_stat_model']);
        $homeAwayProfile = $this->calculateHomeAwayProfile($player->id, $isHome, $statField, $sportConfig['player_stat_model']);
        $vsOpponentAvg = $vsOpponentProfile['avg'];
        $homeAwayAvg = $homeAwayProfile['avg'];
        $hitRate = $this->calculateHitRateVsOpponent($player->id, $opponentId, $statField, $prop->line, $sportConfig['player_stat_model']);
        $timesCoveredLast5 = $this->calculateTimesCovered($player->id, $statField, $prop->line, 5, $sportConfig['player_stat_model']);
        $timesCoveredSeason = $this->calculateTimesCovered($player->id, $statField, $prop->line, $this->seasonCoverRecordLimit($sport), $sportConfig['player_stat_model']);
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
            $matchQualityScore,
            $vsOpponentProfile['games'],
            $homeAwayProfile['games'],
            (int) ($timesCoveredSeason['games'] ?? 0)
        );

        if (! $analysis['recommendation']) {
            return null;
        }

        $coverRecord = $this->buildCoverRecord(
            playerId: $player->id,
            opponentId: (int) $opponentId,
            playerTeamId: (int) $player->team_id,
            statField: $statField,
            line: (float) $prop->line,
            recommendation: (string) $analysis['recommendation'],
            isHome: $isHome,
            playerStatModel: $sportConfig['player_stat_model'],
            sport: $sport
        );
        $analysis = $this->applyCoverRecordSignalAdjustment($analysis, $coverRecord);
        $analysis['confidence_decomposition']['cover_record'] = $coverRecord;
        $analysis['confidence_decomposition']['stat_summary'] = [
            'season_avg' => round($seasonAvg, 1),
            'recent_avg' => round($recentAvg ?? $seasonAvg, 1),
            'last5_avg' => round($last5Avg ?? $seasonAvg, 1),
            'vs_opponent_avg' => $vsOpponentAvg ? round($vsOpponentAvg, 1) : null,
            'home_away_avg' => $homeAwayAvg ? round($homeAwayAvg, 1) : null,
            'consistency' => $consistency,
        ];
        $analysis['confidence_decomposition']['schema_version'] = self::SIGNAL_MODEL_VERSION;
        $analysis['confidence_decomposition']['signal_quality'] = $this->signalQuality(
            confidence: (int) $analysis['confidence'],
            dataQualityScore: $dataQualityScore,
            matchQualityScore: $matchQualityScore,
            seasonSample: (int) ($timesCoveredSeason['games'] ?? 0),
            coverRecord: $coverRecord
        );

        $this->persistPredictionSnapshot($prop, $analysis, $dataQualityScore, $matchQualityScore, $context['combined_factor']);

        $recommendation = [
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
            'cover_record' => $coverRecord,
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

        if (! $attachNarratives) {
            $recommendation['narrative'] = is_array($prop->narrative_json ?? null) ? $prop->narrative_json : null;

            return $recommendation;
        }

        return $this->playerPropNarrativeService->attachNarrative($recommendation, $sport);
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
        int $matchQualityScore,
        int $vsOpponentSample = 0,
        int $homeAwaySample = 0,
        int $seasonSample = 0
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
            vsOpponentSample: $vsOpponentSample,
            homeAwaySample: $homeAwaySample,
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

        // Edge drives confidence non-linearly so small edges do not bunch near the top.
        $modelEdgeScore = (int) round(max(0, min(100, ($edgeProbability / 0.20) * 100)));
        $edgeConfidenceScore = (int) round(
            pow(max(0, min(1, ($edgeProbability / 0.20))), 1.30) * 100
        );
        $edgeConfidenceWeight = $this->edgeConfidenceWeight($dataQualityScore);
        $effectiveEdgeConfidence = (int) round($edgeConfidenceScore * $edgeConfidenceWeight);
        $confidence = (int) round(
            34
            + ($effectiveEdgeConfidence * 0.33)
            + ($dataQualityScore * 0.12)
            + ($matchQualityScore * 0.08)
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
        $usageLabel = str_replace('_', ' ', (string) ($context['usage_context'] ?? 'minutes_trend'));
        $reasoning[] = sprintf(
            'Context multiplier %.3f (pace %.3f, opponent %.3f, %s %.3f).',
            $context['combined_factor'] ?? 1.0,
            $context['pace_factor'] ?? 1.0,
            $context['opponent_factor'] ?? 1.0,
            $usageLabel,
            $context['usage_factor'] ?? $context['minutes_factor'] ?? 1.0
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

            if ($hitRate['games'] >= 5) {
                if ($recommendation === 'Over' && $hitRatePercent >= 60) {
                    $confidence += 3;
                } elseif ($recommendation === 'Under' && $hitRatePercent <= 40) {
                    $confidence += 3;
                } elseif ($recommendation === 'Over' && $hitRatePercent <= 40) {
                    $confidence -= 3;
                } elseif ($recommendation === 'Under' && $hitRatePercent >= 60) {
                    $confidence -= 3;
                }
            }
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

        $uncertaintyPenalty = $this->uncertaintyPenalty($consistency, $seasonSample);
        if ($uncertaintyPenalty > 0) {
            $confidence -= $uncertaintyPenalty;
            $reasoning[] = sprintf('Uncertainty penalty applied: -%d.', $uncertaintyPenalty);
        }

        if ($odds !== null && $odds > 0) {
            $confidence += 1;
            $reasoning[] = sprintf('Positive odds (+%d) improve expected value.', $odds);
        }

        if ($edgeProbability < 0.06) {
            $confidence -= 10;
        } elseif ($edgeProbability < 0.08) {
            $confidence -= 5;
        }

        $isOutlier = $edgeProbability >= 0.14
            && $dataQualityScore >= 82
            && $matchQualityScore >= 78
            && (($context['combined_factor'] ?? 1.0) >= 0.96);
        if ($isOutlier) {
            $confidence += 6;
            $reasoning[] = 'Outlier grade: edge and data quality both clear elite thresholds.';
        }

        $confidenceCap = $this->confidenceCap(
            market: (string) $prop->market,
            edgeProbability: $edgeProbability,
            dataQualityScore: $dataQualityScore,
            matchQualityScore: $matchQualityScore,
            seasonSample: $seasonSample
        );
        if ($confidence > $confidenceCap) {
            $reasoning[] = sprintf('Signal cap applied at %d for market volatility, edge, sample, and data quality.', $confidenceCap);
        }
        $confidence = min($confidence, $confidenceCap);

        return [
            'recommendation' => $recommendation,
            'odds' => $odds,
            'confidence' => round(max(32, min(96, $confidence))),
            'edge' => round($projectionDiff, 1),
            'model_over_probability' => round($modelOverProbability * 100, 1),
            'market_over_probability' => round($marketOverProbability * 100, 1),
            'edge_probability' => round($edgeProbability * 100, 1),
            'reasoning' => $reasoning,
            'confidence_decomposition' => [
                'model_edge_score' => $modelEdgeScore,
                'edge_confidence_score' => $edgeConfidenceScore,
                'effective_edge_confidence_score' => $effectiveEdgeConfidence,
                'data_quality_score' => $dataQualityScore,
                'match_quality_score' => $matchQualityScore,
                'context_factor' => round((float) ($context['combined_factor'] ?? 1.0), 3),
                'uncertainty_penalty' => $uncertaintyPenalty,
                'confidence_cap' => $confidenceCap,
            ],
        ];
    }

    protected function buildProjection(
        float $seasonAvg,
        ?float $recentAvg,
        ?float $last5Avg,
        ?float $vsOpponentAvg,
        ?float $homeAwayAvg,
        int $vsOpponentSample,
        int $homeAwaySample,
        array $profile
    ): float {
        $projection = $seasonAvg * $profile['season_weight']
            + ($recentAvg ?? $seasonAvg) * $profile['recent_weight']
            + ($last5Avg ?? $seasonAvg) * $profile['last5_weight'];

        if ($vsOpponentAvg !== null) {
            $vsOpponentShrunk = $this->shrinkToBaseline($vsOpponentAvg, $vsOpponentSample, $seasonAvg, 6.0);
            $projection += $vsOpponentShrunk * $profile['vs_opp_weight'];
        } else {
            $projection += $seasonAvg * $profile['vs_opp_weight'];
        }

        if ($homeAwayAvg !== null) {
            $homeAwayShrunk = $this->shrinkToBaseline($homeAwayAvg, $homeAwaySample, $seasonAvg, 8.0);
            $projection += $homeAwayShrunk * $profile['home_away_weight'];
        } else {
            $projection += $seasonAvg * $profile['home_away_weight'];
        }

        return $projection;
    }

    protected function shrinkToBaseline(float $value, int $sampleSize, float $baseline, float $priorStrength): float
    {
        $sample = max(0, $sampleSize);
        if ($sample === 0) {
            return $baseline;
        }

        $k = max(1.0, $priorStrength);

        return (($value * $sample) + ($baseline * $k)) / ($sample + $k);
    }

    protected function edgeConfidenceWeight(int $dataQualityScore): float
    {
        return match (true) {
            $dataQualityScore < 55 => 0.45,
            $dataQualityScore < 65 => 0.60,
            $dataQualityScore < 75 => 0.78,
            $dataQualityScore < 85 => 0.90,
            default => 1.0,
        };
    }

    protected function uncertaintyPenalty(?array $consistency, int $seasonSample): int
    {
        $stdDev = isset($consistency['std_dev']) ? (float) $consistency['std_dev'] : null;
        if ($stdDev === null) {
            return $seasonSample < 6 ? 8 : 4;
        }

        $volatilityPenalty = (int) round(max(0.0, min(8.0, ($stdDev - 2.5) * 1.2)));
        $samplePenalty = max(0, 8 - min(8, (int) floor($seasonSample / 2)));

        return max(0, min(14, $volatilityPenalty + $samplePenalty));
    }

    protected function confidenceCap(
        string $market,
        float $edgeProbability,
        int $dataQualityScore,
        int $matchQualityScore,
        int $seasonSample
    ): int {
        return min(
            $this->marketConfidenceCap($market),
            $this->edgeConfidenceCap($edgeProbability),
            $this->dataQualityConfidenceCap($dataQualityScore),
            $this->matchQualityConfidenceCap($matchQualityScore),
            $this->sampleConfidenceCap($seasonSample),
        );
    }

    protected function marketConfidenceCap(string $market): int
    {
        return match ($market) {
            'batter_home_runs' => 82,
            'batter_rbis', 'batter_runs_scored', 'batter_walks', 'batter_strikeouts' => 86,
            'batter_hits', 'pitcher_walks', 'pitcher_earned_runs' => 88,
            'pitcher_hits_allowed' => 90,
            'pitcher_strikeouts' => 92,
            'player_anytime_td', 'player_pass_tds', 'player_pass_interceptions' => 82,
            'player_rush_attempts', 'player_receptions', 'player_pass_completions', 'player_pass_attempts' => 88,
            'player_rush_yds', 'player_reception_yds' => 90,
            'player_pass_yds' => 92,
            default => 94,
        };
    }

    protected function edgeConfidenceCap(float $edgeProbability): int
    {
        return match (true) {
            $edgeProbability < 0.06 => 70,
            $edgeProbability < 0.08 => 76,
            $edgeProbability < 0.10 => 82,
            $edgeProbability < 0.14 => 88,
            $edgeProbability < 0.18 => 92,
            default => 96,
        };
    }

    protected function dataQualityConfidenceCap(int $dataQualityScore): int
    {
        return match (true) {
            $dataQualityScore < 55 => 68,
            $dataQualityScore < 65 => 74,
            $dataQualityScore < 75 => 82,
            $dataQualityScore < 85 => 88,
            default => 96,
        };
    }

    protected function matchQualityConfidenceCap(int $matchQualityScore): int
    {
        return match (true) {
            $matchQualityScore < 70 => 72,
            $matchQualityScore < 80 => 82,
            $matchQualityScore < 90 => 90,
            default => 96,
        };
    }

    protected function sampleConfidenceCap(int $seasonSample): int
    {
        return match (true) {
            $seasonSample < 6 => 72,
            $seasonSample < 10 => 80,
            $seasonSample < 15 => 86,
            $seasonSample < 25 => 92,
            default => 96,
        };
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
     * @return array{pace_factor: float, opponent_factor: float, minutes_factor: float, usage_factor: float, usage_context: string, combined_factor: float}
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
        $usageContext = $this->usageContextFactor($playerId, $market, $sportConfig);
        $minutesFactor = $usageContext['factor'];

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
            'usage_factor' => round($minutesFactor, 3),
            'usage_context' => $usageContext['label'],
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
            'batter_home_runs' => 'home_runs',
            'batter_hits' => 'hits',
            'batter_rbis' => 'rbis',
            'batter_runs_scored' => 'runs',
            'batter_walks' => 'walks',
            'batter_strikeouts' => 'strikeouts',
            'pitcher_strikeouts' => 'strikeouts_pitched',
            'pitcher_hits_allowed' => 'hits_allowed',
            'pitcher_walks' => 'walks_allowed',
            'pitcher_earned_runs' => 'earned_runs',
            'player_pass_yds' => 'passing_yards',
            'player_pass_tds' => 'passing_touchdowns',
            'player_pass_completions' => 'passing_completions',
            'player_pass_attempts' => 'passing_attempts',
            'player_pass_interceptions' => 'interceptions',
            'player_rush_yds' => 'rushing_yards',
            'player_rush_attempts' => 'rushing_attempts',
            default => null,
        };
    }

    /**
     * @return array{factor:float,label:string}
     */
    protected function usageContextFactor(int $playerId, string $market, array $sportConfig): array
    {
        $oddsSportKey = (string) ($sportConfig['odds_sport_key'] ?? '');
        if (! str_starts_with($oddsSportKey, 'americanfootball_')) {
            return [
                'factor' => $this->minutesTrendFactor($playerId, $sportConfig['player_stat_model']),
                'label' => 'minutes_trend',
            ];
        }

        return [
            'factor' => $this->footballUsageTrendFactor($playerId, $market, $sportConfig['player_stat_model']),
            'label' => $this->footballUsageContextLabel($market),
        ];
    }

    protected function footballUsageTrendFactor(int $playerId, string $market, string $playerStatModel): float
    {
        $stats = $this->finalizedPlayerStatsQuery($playerId, $playerStatModel)->take(16)->get();
        if ($stats->count() < 6) {
            return 1.0;
        }

        $recent = $stats->take(8)->map(fn (Model $stat): float => $this->footballUsageValue($stat, $market));
        $prior = $stats->skip(8)->take(8)->map(fn (Model $stat): float => $this->footballUsageValue($stat, $market));

        $recentAvg = (float) $recent->avg();
        $priorAvg = (float) $prior->avg();
        if ($recentAvg <= 0 || $priorAvg <= 0) {
            return 1.0;
        }

        $delta = ($recentAvg - $priorAvg) / max(1.0, $priorAvg);

        return max(0.90, min(1.10, 1 + ($delta * 0.35)));
    }

    protected function footballUsageValue(Model $stat, string $market): float
    {
        return match ($market) {
            'player_pass_yds', 'player_pass_tds', 'player_pass_completions', 'player_pass_interceptions' => (float) ($stat->passing_attempts ?? 0),
            'player_pass_attempts' => (float) ($stat->passing_attempts ?? 0),
            'player_rush_yds', 'player_rush_attempts' => (float) ($stat->rushing_attempts ?? 0),
            'player_receptions', 'player_reception_yds' => (float) (($stat->receiving_targets ?? 0) ?: ($stat->receptions ?? 0)),
            'player_anytime_td' => (float) ($stat->rushing_attempts ?? 0) + (float) (($stat->receiving_targets ?? 0) ?: ($stat->receptions ?? 0)),
            default => 0.0,
        };
    }

    protected function footballUsageContextLabel(string $market): string
    {
        return match ($market) {
            'player_pass_yds', 'player_pass_tds', 'player_pass_completions', 'player_pass_attempts', 'player_pass_interceptions' => 'passing_volume_trend',
            'player_rush_yds', 'player_rush_attempts' => 'rushing_volume_trend',
            'player_receptions', 'player_reception_yds' => 'target_volume_trend',
            'player_anytime_td' => 'touch_volume_trend',
            default => 'football_usage_trend',
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

    protected function clearPredictionSnapshot(Model $prop): void
    {
        $prop->forceFill([
            'recommended_side' => null,
            'confidence_score' => null,
            'predicted_over_probability' => null,
            'market_over_probability' => null,
            'edge_probability' => null,
            'data_quality_score' => null,
            'match_quality_score' => null,
            'context_adjustment_factor' => null,
            'confidence_decomposition' => null,
            'narrative_json' => null,
            'narrative_provider' => null,
            'narrative_model' => null,
            'narrative_input_hash' => null,
            'narrative_latency_ms' => null,
            'narrative_generated_at' => null,
        ])->saveQuietly();
    }

    protected function precomputedRecommendationPayload(Model $prop, string $sport): ?array
    {
        $player = $prop->player ?? null;
        $game = $prop->game ?? null;
        $side = is_string($prop->recommended_side)
            ? ucfirst(strtolower($prop->recommended_side))
            : $prop->recommended_side;

        if (! $game instanceof Model || ! in_array($side, ['Over', 'Under'], true)) {
            return null;
        }

        $modelOverProbability = $prop->predicted_over_probability !== null ? (float) $prop->predicted_over_probability : null;
        $marketOverProbability = $prop->market_over_probability !== null ? (float) $prop->market_over_probability : null;
        $edgeProbability = $prop->edge_probability !== null ? (float) $prop->edge_probability : null;
        $contextFactor = $prop->context_adjustment_factor !== null ? (float) $prop->context_adjustment_factor : null;
        $statSummary = data_get($prop->confidence_decomposition, 'stat_summary', []);
        $coverRecord = data_get($prop->confidence_decomposition, 'cover_record');
        $schemaVersion = data_get($prop->confidence_decomposition, 'schema_version');

        if (
            $schemaVersion !== self::SIGNAL_MODEL_VERSION
            || ! is_array($statSummary)
            || $statSummary === []
            || ! is_array($coverRecord)
        ) {
            return null;
        }

        return [
            'prop' => $prop,
            'player' => $player instanceof Model ? $player : [
                'id' => $prop->player_id,
                'name' => $prop->player_name,
                'display_name' => $prop->player_name,
                'full_name' => $prop->player_name,
                'position' => null,
                'team' => null,
                'headshot_url' => null,
                'headshot' => null,
            ],
            'game' => $game,
            'market' => $this->formatMarketName((string) $prop->market),
            'line' => $prop->line,
            'recommendation' => $side,
            'odds' => $side === 'Over' ? $prop->over_price : $prop->under_price,
            'confidence' => (int) $prop->confidence_score,
            'season_avg' => data_get($statSummary, 'season_avg'),
            'recent_avg' => data_get($statSummary, 'recent_avg'),
            'last5_avg' => data_get($statSummary, 'last5_avg'),
            'vs_opponent_avg' => data_get($statSummary, 'vs_opponent_avg'),
            'home_away_avg' => data_get($statSummary, 'home_away_avg'),
            'hit_rate_vs_opponent' => data_get($coverRecord, 'vs_opponent'),
            'times_covered_last5' => data_get($coverRecord, 'last_5'),
            'times_covered_season' => data_get($coverRecord, 'season'),
            'cover_record' => $coverRecord,
            'consistency' => data_get($statSummary, 'consistency'),
            'streak' => null,
            'edge' => $edgeProbability !== null ? round($edgeProbability / 10, 1) : 0,
            'model_over_probability' => $modelOverProbability,
            'market_over_probability' => $marketOverProbability,
            'edge_probability' => $edgeProbability,
            'reasoning' => $this->precomputedReasoning($prop, $side, $modelOverProbability, $marketOverProbability, $edgeProbability),
            'context' => $contextFactor !== null ? [
                'pace_factor' => 1.0,
                'opponent_factor' => 1.0,
                'minutes_factor' => 1.0,
                'usage_factor' => 1.0,
                'usage_context' => 'stored_context_factor',
                'combined_factor' => $contextFactor,
            ] : null,
            'data_quality_score' => $prop->data_quality_score,
            'match_quality_score' => $prop->match_quality_score,
            'confidence_decomposition' => $prop->confidence_decomposition,
            'narrative' => is_array($prop->narrative_json ?? null) ? $prop->narrative_json : null,
        ];
    }

    protected function precomputedReasoning(
        Model $prop,
        string $side,
        ?float $modelOverProbability,
        ?float $marketOverProbability,
        ?float $edgeProbability
    ): array {
        $reasoning = ['Precomputed recommendation from the latest player-prop analysis run.'];

        if ($modelOverProbability !== null && $marketOverProbability !== null) {
            $modelSideProbability = $side === 'Under' ? 100 - $modelOverProbability : $modelOverProbability;
            $marketSideProbability = $side === 'Under' ? 100 - $marketOverProbability : $marketOverProbability;
            $reasoning[] = sprintf(
                'Model %s probability %.1f%% vs market implied %.1f%%.',
                strtolower($side),
                $modelSideProbability,
                $marketSideProbability
            );
        }

        if ($edgeProbability !== null) {
            $reasoning[] = sprintf('Stored probability edge %.1fpp.', $edgeProbability);
        }

        if ($prop->fetched_at !== null) {
            $reasoning[] = 'Odds fetched '.$prop->fetched_at->diffForHumans().'.';
        }

        return $reasoning;
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

        return $this->averageStatValue($stats, $statField);
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

        return $this->recencyWeightedAverage($this->statValues($stats, $statField), 0.86);
    }

    /**
     * Calculate average vs specific opponent
     */
    protected function calculateVsOpponentProfile(
        int $playerId,
        int $opponentId,
        string $statField,
        string $playerStatModel
    ): array {
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
            return ['avg' => null, 'games' => 0];
        }

        return [
            'avg' => $this->recencyWeightedAverage($this->statValues($stats, $statField), 0.82),
            'games' => $stats->count(),
        ];
    }

    /**
     * Calculate home or away average
     */
    protected function calculateHomeAwayProfile(
        int $playerId,
        bool $isHome,
        string $statField,
        string $playerStatModel
    ): array {
        // Get player's team ID first
        $playerStat = $playerStatModel::where('player_id', $playerId)->first();
        if (! $playerStat || ! $playerStat->player || ! $playerStat->player->team_id) {
            return ['avg' => null, 'games' => 0];
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
            return ['avg' => null, 'games' => $stats->count()];
        }

        return [
            'avg' => $this->recencyWeightedAverage($this->statValues($stats, $statField), 0.90),
            'games' => $stats->count(),
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     */
    protected function recencyWeightedAverage(array $values, float $decay = 0.88): ?float
    {
        if ($values === []) {
            return null;
        }

        $decay = max(0.50, min(0.99, $decay));
        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($values as $index => $value) {
            $numeric = is_numeric($value) ? (float) $value : 0.0;
            $weight = pow($decay, (float) $index);
            $weightedSum += ($numeric * $weight);
            $weightTotal += $weight;
        }

        if ($weightTotal <= 0.0) {
            return null;
        }

        return $weightedSum / $weightTotal;
    }

    /**
     * @param  Collection<int, Model>  $stats
     */
    protected function averageStatValue(Collection $stats, string $statField): ?float
    {
        $values = $this->statValues($stats, $statField);

        return $values === [] ? null : array_sum($values) / count($values);
    }

    /**
     * @param  Collection<int, Model>  $stats
     * @return list<float>
     */
    protected function statValues(Collection $stats, string $statField): array
    {
        return $stats
            ->map(fn (Model $stat): float => $this->statValue($stat, $statField))
            ->values()
            ->all();
    }

    protected function statValue(Model $stat, string $statField): float
    {
        if ($statField === 'total_touchdowns') {
            return (float) ($stat->rushing_touchdowns ?? 0)
                + (float) ($stat->receiving_touchdowns ?? 0);
        }

        return is_numeric($stat->{$statField} ?? null) ? (float) $stat->{$statField} : 0.0;
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

        $hits = $stats->filter(fn ($stat) => $this->statValue($stat, $statField) > $line)->count();

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

        $hits = $stats->filter(fn ($stat) => $this->statValue($stat, $statField) > $line)->count();

        return [
            'hits' => $hits,
            'games' => $stats->count(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    protected function buildCoverRecord(
        int $playerId,
        int $opponentId,
        int $playerTeamId,
        string $statField,
        float $line,
        string $recommendation,
        bool $isHome,
        string $playerStatModel,
        string $sport
    ): array {
        $baseQuery = fn () => $this->finalizedPlayerStatsQuery($playerId, $playerStatModel);

        $homeAwayStats = $baseQuery()
            ->whereHas('game', function ($query) use ($isHome, $playerTeamId): void {
                $query->where($isHome ? 'home_team_id' : 'away_team_id', $playerTeamId);
            })
            ->take(20)
            ->get();

        $vsOpponentStats = $baseQuery()
            ->whereHas('game', function ($query) use ($opponentId): void {
                $query->where(function ($nested) use ($opponentId): void {
                    $nested->where('home_team_id', $opponentId)
                        ->orWhere('away_team_id', $opponentId);
                });
            })
            ->take(10)
            ->get();

        return [
            'season' => $this->coverRecordFromStats(
                $baseQuery()->take($this->seasonCoverRecordLimit($sport))->get(),
                $statField,
                $line,
                $recommendation
            ),
            'last_10' => $this->coverRecordFromStats(
                $baseQuery()->take(10)->get(),
                $statField,
                $line,
                $recommendation
            ),
            'last_5' => $this->coverRecordFromStats(
                $baseQuery()->take(5)->get(),
                $statField,
                $line,
                $recommendation
            ),
            'home_away' => $this->coverRecordFromStats($homeAwayStats, $statField, $line, $recommendation),
            'vs_opponent' => $this->coverRecordFromStats($vsOpponentStats, $statField, $line, $recommendation),
        ];
    }

    /**
     * @param  Collection<int, Model>  $stats
     * @return array<string, mixed>|null
     */
    protected function coverRecordFromStats(Collection $stats, string $statField, float $line, string $recommendation): ?array
    {
        if ($stats->isEmpty()) {
            return null;
        }

        $over = 0;
        $under = 0;
        $pushes = 0;

        foreach ($stats as $stat) {
            $value = $this->statValue($stat, $statField);

            if ($value > $line) {
                $over++;
            } elseif ($value < $line) {
                $under++;
            } else {
                $pushes++;
            }
        }

        $games = $stats->count();
        $wins = $recommendation === 'Under' ? $under : $over;
        $losses = $recommendation === 'Under' ? $over : $under;

        return [
            'games' => $games,
            'over' => $over,
            'under' => $under,
            'pushes' => $pushes,
            'hits' => $over,
            'recommendation' => $recommendation,
            'wins' => $wins,
            'losses' => $losses,
            'win_rate' => $games > 0 ? round(($wins / $games) * 100, 1) : null,
            'record' => "{$over}-{$under}".($pushes > 0 ? "-{$pushes}" : ''),
            'recommendation_record' => "{$wins}-{$losses}".($pushes > 0 ? "-{$pushes}" : ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, array<string, mixed>|null>  $coverRecord
     * @return array<string, mixed>
     */
    protected function applyCoverRecordSignalAdjustment(array $analysis, array $coverRecord): array
    {
        if (empty($analysis['recommendation']) || ! isset($analysis['confidence'])) {
            return $analysis;
        }

        $confidence = (int) $analysis['confidence'];
        $originalConfidence = $confidence;
        $originalCap = (int) data_get($analysis, 'confidence_decomposition.confidence_cap', 96);
        $cap = $originalCap;
        $delta = 0;
        $reasons = [];

        $season = $coverRecord['season'] ?? null;
        $last10 = $coverRecord['last_10'] ?? null;
        $last5 = $coverRecord['last_5'] ?? null;

        $seasonRate = $this->coverRecordWinRate($season, 12);
        $last10Rate = $this->coverRecordWinRate($last10, 8);
        $last5Rate = $this->coverRecordWinRate($last5, 5);

        if ($seasonRate !== null && $seasonRate < 45.0) {
            $cap = min($cap, 68);
            $reasons[] = sprintf('Season cover record is weak for the recommendation (%.1f%%); signal capped.', $seasonRate);
        } elseif ($seasonRate !== null && $seasonRate < 50.0) {
            $cap = min($cap, 74);
            $delta -= 3;
            $reasons[] = sprintf('Season cover record is below break-even for the recommendation (%.1f%%).', $seasonRate);
        }

        if ($last10Rate !== null && $last10Rate < 40.0) {
            $delta -= 8;
            $reasons[] = sprintf('Last 10 cover record is poor for the recommendation (%.1f%%).', $last10Rate);
        } elseif ($last10Rate !== null && $last10Rate < 50.0) {
            $delta -= 4;
            $reasons[] = sprintf('Last 10 cover record is below break-even for the recommendation (%.1f%%).', $last10Rate);
        }

        if ($last5Rate !== null && $last5Rate < 40.0) {
            $delta -= 4;
            $reasons[] = sprintf('Last 5 cover record does not support the recommendation (%.1f%%).', $last5Rate);
        }

        if ($seasonRate !== null && $last10Rate !== null && $seasonRate >= 60.0 && $last10Rate >= 60.0) {
            $delta += 3;
            $reasons[] = 'Season and last 10 cover records both confirm the recommendation.';

            if ($last5Rate !== null && $last5Rate >= 60.0) {
                $delta += 2;
                $reasons[] = 'Last 5 cover record also confirms the recommendation.';
            }
        }

        if ($seasonRate !== null && $last5Rate !== null && (($seasonRate >= 60.0 && $last5Rate <= 40.0) || ($seasonRate <= 40.0 && $last5Rate >= 60.0))) {
            $delta -= 5;
            $reasons[] = 'Season and recent cover records conflict; uncertainty penalty applied.';
        }

        $confidence = max(32, min($cap, $confidence + $delta));

        if ($reasons !== []) {
            $analysis['reasoning'] = array_merge($analysis['reasoning'] ?? [], $reasons);
        }

        $analysis['confidence'] = $confidence;
        $analysis['confidence_decomposition']['cover_record_adjustment'] = [
            'original_confidence' => $originalConfidence,
            'original_cap' => $originalCap,
            'applied_cap' => $cap,
            'delta' => $delta,
            'season_win_rate' => $seasonRate,
            'last_10_win_rate' => $last10Rate,
            'last_5_win_rate' => $last5Rate,
        ];

        return $analysis;
    }

    /**
     * @param  array<string, mixed>|null  $record
     */
    protected function coverRecordWinRate(?array $record, int $minimumGames): ?float
    {
        if (! is_array($record) || (int) ($record['games'] ?? 0) < $minimumGames || $record['win_rate'] === null) {
            return null;
        }

        return (float) $record['win_rate'];
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $coverRecord
     * @return array{label:string,tier:string,reason_codes:array<int,string>}
     */
    protected function signalQuality(
        int $confidence,
        int $dataQualityScore,
        int $matchQualityScore,
        int $seasonSample,
        array $coverRecord
    ): array {
        $seasonRate = $this->coverRecordWinRate($coverRecord['season'] ?? null, 12);
        $last10Rate = $this->coverRecordWinRate($coverRecord['last_10'] ?? null, 8);
        $reasonCodes = [];

        if ($seasonSample < 6) {
            $reasonCodes[] = 'thin_season_sample';
        }

        if ($dataQualityScore < 75) {
            $reasonCodes[] = 'limited_data_quality';
        }

        if ($matchQualityScore < 90) {
            $reasonCodes[] = 'player_match_not_exact';
        }

        if ($seasonRate !== null && $seasonRate < 50.0) {
            $reasonCodes[] = 'season_cover_record_not_supportive';
        }

        if ($last10Rate !== null && $last10Rate < 50.0) {
            $reasonCodes[] = 'recent_cover_record_not_supportive';
        }

        $eligibleForVeryStrong = $confidence >= 88
            && $dataQualityScore >= 85
            && $matchQualityScore >= 90
            && $seasonSample >= 15
            && ($seasonRate === null || $seasonRate >= 55.0)
            && ($last10Rate === null || $last10Rate >= 55.0);

        if ($eligibleForVeryStrong) {
            return [
                'label' => 'Very Strong',
                'tier' => 'very_strong',
                'reason_codes' => $reasonCodes,
            ];
        }

        $eligibleForStrong = $confidence >= 76
            && $dataQualityScore >= 75
            && $matchQualityScore >= 82
            && $seasonSample >= 8
            && ($seasonRate === null || $seasonRate >= 48.0);

        if ($eligibleForStrong) {
            return [
                'label' => 'Strong',
                'tier' => 'strong',
                'reason_codes' => $reasonCodes,
            ];
        }

        if ($confidence >= 60) {
            return [
                'label' => 'Lean',
                'tier' => 'lean',
                'reason_codes' => $reasonCodes,
            ];
        }

        return [
            'label' => 'Low',
            'tier' => 'low',
            'reason_codes' => $reasonCodes,
        ];
    }

    protected function seasonCoverRecordLimit(string $sport): int
    {
        return match (strtoupper($sport)) {
            'MLB' => 162,
            'NFL' => 17,
            'WNBA' => 44,
            'CBB' => 40,
            default => 82,
        };
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

        $values = $this->statValues($stats, $statField);
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
            $isOver = $this->statValue($stat, $statField) > $line;
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
        /** @var Model $statModel */
        $statModel = new $playerStatModel;
        $statTable = $statModel->getTable();
        $gameTable = $statModel->game()->getRelated()->getTable();

        return $playerStatModel::where('player_id', $playerId)
            ->whereHas('game', fn ($q) => $q->where('status', 'STATUS_FINAL'))
            ->orderByDesc(
                DB::table($gameTable)
                    ->select("{$gameTable}.game_date")
                    ->whereColumn("{$gameTable}.id", "{$statTable}.game_id")
                    ->limit(1)
            )
            ->orderByDesc("{$statTable}.id");
    }

    /**
     * Find player by fuzzy name matching
     */
    protected function findPlayerByName(string $name, string $playerModel, string $oddsSportKey, ?Model $game = null): ?array
    {
        $baseQuery = $this->playerBaseQuery($playerModel, $game);
        $mappedPlayerId = $this->oddsApiService?->mappedEspnPlayerId($oddsSportKey, $name);

        if ($mappedPlayerId) {
            $mappedPlayer = (clone $baseQuery)->whereKey($mappedPlayerId)->first();

            if ($mappedPlayer) {
                return [
                    'player' => $mappedPlayer,
                    'match_quality_score' => 99,
                ];
            }
        }

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

        if ($candidate) {
            $this->oddsApiService?->rememberUnmappedPlayer(
                $oddsSportKey,
                $name,
                $candidate['player'],
                (int) round($candidate['score'])
            );
        }

        $lastNameMatch = (clone $baseQuery)
            ->whereRaw('LOWER(last_name) = ?', [$lastName])
            ->first();

        if ($lastNameMatch) {
            $this->oddsApiService?->rememberUnmappedPlayer($oddsSportKey, $name, $lastNameMatch, 75);

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
            'batter_home_runs' => 'home_runs',
            'batter_hits' => 'hits',
            'batter_rbis' => 'rbis',
            'batter_runs_scored' => 'runs',
            'batter_walks' => 'walks',
            'batter_strikeouts' => 'strikeouts',
            'pitcher_strikeouts' => 'strikeouts_pitched',
            'pitcher_hits_allowed' => 'hits_allowed',
            'pitcher_walks' => 'walks_allowed',
            'pitcher_earned_runs' => 'earned_runs',
            'player_pass_yds' => 'passing_yards',
            'player_pass_tds' => 'passing_touchdowns',
            'player_pass_completions' => 'passing_completions',
            'player_pass_attempts' => 'passing_attempts',
            'player_pass_interceptions' => 'interceptions_thrown',
            'player_rush_yds' => 'rushing_yards',
            'player_rush_attempts' => 'rushing_attempts',
            'player_receptions' => 'receptions',
            'player_reception_yds' => 'receiving_yards',
            'player_anytime_td' => 'total_touchdowns',
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
            'batter_home_runs' => 'Home Runs',
            'batter_hits' => 'Hits',
            'batter_rbis' => 'RBIs',
            'batter_runs_scored' => 'Runs Scored',
            'batter_walks' => 'Walks',
            'batter_strikeouts' => 'Strikeouts',
            'pitcher_strikeouts' => 'Pitcher Strikeouts',
            'pitcher_hits_allowed' => 'Pitcher Hits Allowed',
            'pitcher_walks' => 'Pitcher Walks',
            'pitcher_earned_runs' => 'Pitcher Earned Runs',
            'player_pass_yds' => 'Passing Yards',
            'player_pass_tds' => 'Passing Touchdowns',
            'player_pass_completions' => 'Pass Completions',
            'player_pass_attempts' => 'Pass Attempts',
            'player_pass_interceptions' => 'Interceptions Thrown',
            'player_rush_yds' => 'Rushing Yards',
            'player_rush_attempts' => 'Rushing Attempts',
            'player_receptions' => 'Receptions',
            'player_reception_yds' => 'Receiving Yards',
            'player_anytime_td' => 'Anytime Touchdown',
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
                'team_stat_model' => 'App\\Models\\NFL\\TeamStat',
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
            'WNBA' => [
                'game_model' => 'App\\Models\\WNBA\\Game',
                'player_model' => 'App\\Models\\WNBA\\Player',
                'player_stat_model' => 'App\\Models\\WNBA\\PlayerStat',
                'team_model' => 'App\\Models\\WNBA\\Team',
                'team_metric_model' => 'App\\Models\\WNBA\\TeamMetric',
                'team_stat_model' => 'App\\Models\\WNBA\\TeamStat',
                'player_prop_model' => 'App\\Models\\WNBA\\PlayerProp',
                'odds_sport_key' => 'basketball_wnba',
                'league_pace_baseline' => 80.0,
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
                    'label' => Carbon::parse($date)->format('l, F j, Y'),
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
                $gameDate = Carbon::parse($game->game_date)->toDateString();

                // Parse the time separately
                $gameTime = Carbon::parse($game->game_time);

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
