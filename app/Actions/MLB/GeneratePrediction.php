<?php

namespace App\Actions\MLB;

use App\Actions\Sports\AbstractPredictionGenerator;
use App\Models\MLB\EloRating;
use App\Models\MLB\Game;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerInjury;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use App\Services\MLB\HistoricalPredictionContextService;
use App\Services\MLB\SituationalPredictionContextService;
use App\Services\Sports\DepthChartImpactService;
use App\Support\MlbRegularSeasonWindow;
use Illuminate\Database\Eloquent\Model;

class GeneratePrediction extends AbstractPredictionGenerator
{
    /** @var array<string, mixed> */
    private array $metadata = [];

    private bool $allowHistoricalGames = false;

    protected const SPORT_KEY = 'mlb';

    protected const FEATURE_VERSION = 'core-v3';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;

    public function executeHistorical(Game $game, bool $dispatchNarratives = false): ?Model
    {
        $previous = $this->allowHistoricalGames;
        $this->allowHistoricalGames = true;

        try {
            return parent::execute($game, $dispatchNarratives);
        } finally {
            $this->allowHistoricalGames = $previous;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function makePredictionData(Model $game): ?array
    {
        if (! $this->allowHistoricalGames && $game->status === 'STATUS_FINAL') {
            return null;
        }

        $homeTeam = $game->homeTeam;
        $awayTeam = $game->awayTeam;

        if (! $homeTeam || ! $awayTeam) {
            return null;
        }

        $homeTeamElo = $this->getTeamElo($game, $homeTeam);
        $awayTeamElo = $this->getTeamElo($game, $awayTeam);

        $homePitcherResult = $this->getPitcherElo($game, $homeTeam, 'home');
        $awayPitcherResult = $this->getPitcherElo($game, $awayTeam, 'away');

        $homePitcherElo = $homePitcherResult['elo'];
        $awayPitcherElo = $awayPitcherResult['elo'];

        [$homeMetrics, $awayMetrics] = $this->teamMetricsForGame($game, $homeTeam->id, $awayTeam->id);

        $seasonProgressScale = $this->seasonProgressScale($game, $homeMetrics, $awayMetrics);
        [$teamWeight, $pitcherWeight] = $this->dynamicEloWeights($seasonProgressScale);
        $contextWeightScale = $this->contextWeightScale($seasonProgressScale);

        $homeCombinedElo = ($homeTeamElo * $teamWeight) + ($homePitcherElo * $pitcherWeight);
        $awayCombinedElo = ($awayTeamElo * $teamWeight) + ($awayPitcherElo * $pitcherWeight);

        $adjustedHomeElo = $homeCombinedElo + config('mlb.elo.home_field_advantage');

        $eloDiff = $adjustedHomeElo - $awayCombinedElo;
        $predictedSpread = $this->calculateSpread($eloDiff);
        $predictedTotal = $this->calculateTotal($homeCombinedElo, $awayCombinedElo);

        [$contextSpreadAdj, $contextTotalAdj] = $this->applyContextMetricAdjustments(
            $homeMetrics,
            $awayMetrics,
            (int) round($homeTeamElo),
            (int) round($awayTeamElo)
        );
        $contextSpreadAdj = round($contextSpreadAdj * $contextWeightScale, 2);
        $contextTotalAdj = round($contextTotalAdj * $contextWeightScale, 2);
        $predictedSpread = round($predictedSpread + $contextSpreadAdj, 1);
        $predictedTotal = round($predictedTotal + $contextTotalAdj, 1);

        $historicalContext = app(HistoricalPredictionContextService::class)
            ->forGame($game, $homeTeam->id, $awayTeam->id);
        $historicalWeight = $this->historicalContextWeight($seasonProgressScale, $historicalContext);
        $historicalSpreadAdj = round(((float) ($historicalContext['spread_adjustment'] ?? 0.0)) * $historicalWeight, 2);
        $historicalTotalAdj = round(((float) ($historicalContext['total_adjustment'] ?? 0.0)) * $historicalWeight, 2);
        $predictedSpread = round($predictedSpread + $historicalSpreadAdj, 1);
        $predictedTotal = round($predictedTotal + $historicalTotalAdj, 1);

        $situationalContext = app(SituationalPredictionContextService::class)
            ->forGame($game, $homeTeam->id, $awayTeam->id);
        $situationalSpreadAdj = round((float) ($situationalContext['spread_adjustment'] ?? 0.0), 2);
        $situationalTotalAdj = round((float) ($situationalContext['total_adjustment'] ?? 0.0), 2);
        $predictedSpread = round($predictedSpread + $situationalSpreadAdj, 1);
        $predictedTotal = round($predictedTotal + $situationalTotalAdj, 1);

        $usePersistedSpreadInjuryContext = $this->hasPersistedInjuryAdjustedRating($homeMetrics, $awayMetrics);
        $usePersistedTotalInjuryContext = $this->hasPersistedInjuryAdjustedTotal($homeMetrics, $awayMetrics);
        $injurySpreadModelSource = $usePersistedSpreadInjuryContext ? 'persisted_team_rating' : 'raw_player_status';
        $injuryTotalModelSource = $usePersistedTotalInjuryContext ? 'persisted_team_rating' : 'raw_player_status';
        $injuryTotalAdjustment = 0.0;

        if (! $usePersistedSpreadInjuryContext || ! $usePersistedTotalInjuryContext) {
            [$rawInjuryAdjustedSpread, $rawInjuryAdjustedTotal] = $this->applyInjuryAdjustments(
                $game,
                $predictedSpread,
                $predictedTotal
            );

            if (! $usePersistedSpreadInjuryContext) {
                $predictedSpread = $rawInjuryAdjustedSpread;
            }

            if (! $usePersistedTotalInjuryContext) {
                $injuryTotalAdjustment = round($rawInjuryAdjustedTotal - $predictedTotal, 2);
                $predictedTotal = $rawInjuryAdjustedTotal;
            }
        }

        if ($usePersistedTotalInjuryContext) {
            $injuryTotalAdjustment = $this->persistedInjuryTotalAdjustment($homeMetrics, $awayMetrics);
            $predictedTotal = round(
                $predictedTotal + $injuryTotalAdjustment,
                1
            );
        }

        [$predictedSpread, $predictedTotal, $probablePitcherInjuryMetadata] = $this->applyProbablePitcherInjuryAdjustments(
            $game,
            $homeTeam,
            $awayTeam,
            $predictedSpread,
            $predictedTotal
        );

        // Convert the final model spread, not raw Elo points, into win probability.
        $winProbability = $this->calculateWinProbability($predictedSpread);
        $confidenceScore = $this->calculateConfidence($winProbability);
        $vegasSpread = $this->getVegasSpread($game);
        $oddsMarketAvailability = $this->oddsMarketAvailability($game);

        $this->metadata = [
            'home_pitcher_confidence' => $homePitcherResult['confidence'],
            'away_pitcher_confidence' => $awayPitcherResult['confidence'],
            'home_pitcher_source' => $homePitcherResult['source'],
            'away_pitcher_source' => $awayPitcherResult['source'],
            'home_probable_pitcher_espn_id' => $homePitcherResult['probable_pitcher_espn_id'] ?? null,
            'away_probable_pitcher_espn_id' => $awayPitcherResult['probable_pitcher_espn_id'] ?? null,
            'season_sample_games' => $this->seasonSampleGames($game, $homeMetrics, $awayMetrics),
            'season_progress_scale' => round($seasonProgressScale, 3),
            'team_weight' => round($teamWeight, 3),
            'pitcher_weight' => round($pitcherWeight, 3),
            'context_weight_scale' => round($contextWeightScale, 3),
            'context_spread_adjustment' => $contextSpreadAdj,
            'context_total_adjustment' => $contextTotalAdj,
            'historical_context_available' => (bool) ($historicalContext['available'] ?? false),
            'historical_context_weight' => round($historicalWeight, 3),
            'historical_context_spread_adjustment' => $historicalSpreadAdj,
            'historical_context_total_adjustment' => $historicalTotalAdj,
            'historical_context_home' => $historicalContext['home'] ?? [],
            'historical_context_away' => $historicalContext['away'] ?? [],
            'situational_context' => $situationalContext,
            'situational_context_spread_adjustment' => $situationalSpreadAdj,
            'situational_context_total_adjustment' => $situationalTotalAdj,
            'injury_model_source' => $usePersistedSpreadInjuryContext === $usePersistedTotalInjuryContext
                ? ($usePersistedSpreadInjuryContext ? 'persisted_team_rating' : 'raw_player_status')
                : 'mixed',
            'injury_spread_model_source' => $injurySpreadModelSource,
            'injury_total_model_source' => $injuryTotalModelSource,
            'injury_total_adjustment' => $injuryTotalAdjustment,
            ...$probablePitcherInjuryMetadata,
            'baseline_model_spread' => round($predictedSpread, 2),
            'baseline_model_total' => round($predictedTotal, 2),
            'vegas_spread' => $vegasSpread !== null ? round($vegasSpread, 2) : null,
            'market_total' => ($marketTotal = $this->getMarketTotal($game)) !== null ? round($marketTotal, 2) : null,
            'odds_market_availability' => $oddsMarketAvailability,
        ];

        return $this->buildMlbPredictionData(
            $game,
            (int) round($homeTeamElo),
            (int) round($awayTeamElo),
            round($predictedSpread, 1),
            round($predictedTotal, 1),
            round($winProbability, 3),
            round($confidenceScore, 2),
            round($homePitcherElo, 1),
            round($awayPitcherElo, 1),
            round($homeCombinedElo, 1),
            round($awayCombinedElo, 1),
            $vegasSpread !== null ? round($vegasSpread, 2) : null
        );
    }

    /**
     * Get pitcher Elo with three-tier fallback logic:
     * 1. Use known probable pitcher Elo (future enhancement - confidence 1.0)
     * 2. Use team's average pitcher Elo from last 10 starts (confidence 0.75)
     * 3. Use league average 1500 (confidence 0.5)
     */
    protected function getPitcherElo(Game $game, Team $team, string $side): array
    {
        $probablePitcherEspnId = $side === 'home'
            ? $game->probable_home_pitcher_espn_id
            : $game->probable_away_pitcher_espn_id;

        if ($probablePitcherEspnId) {
            $probablePitcher = Player::query()
                ->where('espn_id', $probablePitcherEspnId)
                ->first();

            if ($probablePitcher) {
                $probablePitcherElo = PitcherEloRating::query()
                    ->where('player_id', $probablePitcher->id)
                    ->tap(fn ($query) => MlbRegularSeasonWindow::applyCarryoverFilter(
                        $query,
                        (int) $game->season,
                        'season',
                        'date',
                        (string) $game->game_date
                    ))
                    ->orderByDesc('season')
                    ->orderByDesc('date')
                    ->orderByDesc('id')
                    ->value('elo_rating');

                if ($probablePitcherElo !== null) {
                    return [
                        'elo' => (float) $probablePitcherElo,
                        'confidence' => 1.0,
                        'source' => 'probable_starter',
                        'probable_pitcher_espn_id' => $probablePitcherEspnId,
                    ];
                }
            }
        }

        $depthChartPitcherId = app(DepthChartImpactService::class)
            ->mlbLikelyStarterPitcherId($team->id, (int) $game->season);

        if ($depthChartPitcherId) {
            $depthChartPitcherElo = PitcherEloRating::query()
                ->where('player_id', $depthChartPitcherId)
                ->tap(fn ($query) => MlbRegularSeasonWindow::applyCarryoverFilter(
                    $query,
                    (int) $game->season,
                    'season',
                    'date',
                    (string) $game->game_date
                ))
                ->orderByDesc('season')
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->value('elo_rating');

            if ($depthChartPitcherElo !== null) {
                return [
                    'elo' => (float) $depthChartPitcherElo,
                    'confidence' => 0.9,
                    'source' => $probablePitcherEspnId ? 'depth_chart_starter_missing_probable_rating' : 'depth_chart_starter',
                    'probable_pitcher_espn_id' => $probablePitcherEspnId,
                ];
            }
        }

        // Get recent pitcher Elo ratings for this team (uses team_id on history row,
        // so traded pitchers are attributed to the team they pitched for)
        $recentPitcherElos = PitcherEloRating::query()
            ->where('team_id', $team->id)
            ->tap(fn ($query) => MlbRegularSeasonWindow::applyCarryoverFilter(
                $query,
                (int) $game->season,
                'season',
                'date',
                (string) $game->game_date
            ))
            ->orderByDesc('season')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(config('mlb.elo.recent_starts_limit'))
            ->pluck('elo_rating');

        if ($recentPitcherElos->isNotEmpty()) {
            // Tier 2: Use team's average pitcher Elo from recent starts
            return [
                'elo' => $recentPitcherElos->avg(),
                'confidence' => 0.75,
                'source' => $probablePitcherEspnId ? 'team_recent_average_missing_probable_rating' : 'team_recent_average',
                'probable_pitcher_espn_id' => $probablePitcherEspnId,
            ];
        }

        // Tier 3: Use league average (no pitcher data available)
        return [
            'elo' => config('mlb.elo.default_rating'),
            'confidence' => 0.5,
            'source' => $probablePitcherEspnId ? 'league_average_missing_probable_rating' : 'league_average',
            'probable_pitcher_espn_id' => $probablePitcherEspnId,
        ];
    }

    protected function teamMetricsForGame(Model $game, int $homeTeamId, int $awayTeamId): array
    {
        if ($game instanceof Game && ! MlbRegularSeasonWindow::hasCompletedGamesBefore($game)) {
            return [
                $this->latestPriorSeasonMetric(TeamMetric::class, $homeTeamId, (int) $game->season, $game),
                $this->latestPriorSeasonMetric(TeamMetric::class, $awayTeamId, (int) $game->season, $game),
            ];
        }

        return parent::teamMetricsForGame($game, $homeTeamId, $awayTeamId);
    }

    protected function getTeamElo(Game $game, Team $team): float
    {
        $defaultElo = (float) config('mlb.elo.default_rating');

        $elo = EloRating::query()
            ->where('team_id', $team->id)
            ->tap(fn ($query) => MlbRegularSeasonWindow::applyCarryoverFilter(
                $query,
                (int) $game->season,
                'season',
                'date',
                (string) $game->game_date
            ))
            ->orderByDesc('season')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->value('elo_rating');

        if ($elo !== null) {
            return (float) $elo;
        }

        return (float) ($team->elo_rating ?? $defaultElo);
    }

    protected function calculatePredictedSpread(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        // Not used in MLB - we override execute() instead
        return 0.0;
    }

    protected function calculatePredictedTotal(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        // Not used in MLB - we override execute() instead
        return 0.0;
    }

    protected function calculateSpread(float $eloDiff): float
    {
        // Convert Elo difference to runs (approximately 25 Elo points = 0.5 run)
        // Positive spread means home team is favored
        $divisor = (float) config('mlb.prediction.elo_diff_to_spread_divisor', 50.0);
        $divisor = $divisor === 0.0 ? 50.0 : $divisor;

        return $eloDiff / $divisor;
    }

    protected function calculateTotal(float $homeElo, float $awayElo): float
    {
        // Base total on average runs per game, adjusted by combined team strength
        // Higher Elo teams tend to score more runs
        $avgElo = ($homeElo + $awayElo) / 2;
        $eloBaseline = (float) config('mlb.prediction.total_model.average_elo_baseline', config('mlb.elo.default_rating'));
        $eloDivisor = (float) config('mlb.prediction.total_model.average_elo_divisor', 100.0);
        $eloDivisor = $eloDivisor === 0.0 ? 100.0 : $eloDivisor;
        $eloAdjustment = ($avgElo - $eloBaseline) / $eloDivisor;

        return (float) config('mlb.prediction.total_model.base_runs', config('mlb.elo.average_runs_per_game')) + $eloAdjustment;
    }

    public function executeForAllScheduledGames(int $season): int
    {
        $games = Game::query()
            ->where('season', $season)
            ->where('status', 'STATUS_SCHEDULED')
            ->when(
                config('mlb.season.analytics_types'),
                fn ($query, $types) => $query->whereIn('season_type', $types)
            )
            ->with(['homeTeam', 'awayTeam'])
            ->get();

        $generated = 0;

        foreach ($games as $game) {
            $prediction = $this->execute($game);
            if ($prediction) {
                $generated++;
            }
        }

        return $generated;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildMlbPredictionData(
        Game $game,
        int $homeElo,
        int $awayElo,
        float $predictedSpread,
        float $predictedTotal,
        float $winProbability,
        float $confidenceScore,
        float $homePitcherElo,
        float $awayPitcherElo,
        float $homeCombinedElo,
        float $awayCombinedElo,
        ?float $vegasSpread
    ): array {
        return [
            'season' => $game->season,
            'season_type' => (string) $game->season_type,
            'home_team_elo' => $homeElo,
            'away_team_elo' => $awayElo,
            'home_pitcher_elo' => $homePitcherElo,
            'away_pitcher_elo' => $awayPitcherElo,
            'home_combined_elo' => $homeCombinedElo,
            'away_combined_elo' => $awayCombinedElo,
            'predicted_spread' => $predictedSpread,
            'predicted_total' => $predictedTotal,
            'win_probability' => $winProbability,
            'confidence_score' => $confidenceScore,
            'vegas_spread' => $vegasSpread,
            'model_version' => $this->modelVersion(),
            'feature_version' => $this->featureVersion(),
            'blend_version' => $this->blendVersion(),
            'model_metadata' => $this->buildModelMetadata(),
            '_snapshot' => [
                'model_version' => $this->modelVersion(),
                'feature_version' => $this->featureVersion(),
                'blend_version' => $this->blendVersion(),
                'features' => [
                    'home_team_elo' => $homeElo,
                    'away_team_elo' => $awayElo,
                    'home_pitcher_elo' => $homePitcherElo,
                    'away_pitcher_elo' => $awayPitcherElo,
                    'home_combined_elo' => $homeCombinedElo,
                    'away_combined_elo' => $awayCombinedElo,
                    ...$this->metadata,
                ],
                'outputs' => [
                    'baseline_predicted_spread' => $this->metadata['baseline_model_spread'] ?? $predictedSpread,
                    'baseline_predicted_total' => $this->metadata['baseline_model_total'] ?? $predictedTotal,
                    'market_spread' => $vegasSpread,
                    'market_total' => $this->metadata['market_total'] ?? null,
                    'blended_predicted_spread' => $predictedSpread,
                    'blended_predicted_total' => $predictedTotal,
                    'predicted_spread' => $predictedSpread,
                    'predicted_total' => $predictedTotal,
                    'win_probability' => $winProbability,
                    'confidence_score' => $confidenceScore,
                ],
                'market_context' => [
                    'vegas_spread' => $vegasSpread,
                    'market_total' => $this->metadata['market_total'] ?? null,
                    ...($this->metadata['odds_market_availability'] ?? []),
                ],
                'model_metadata' => $this->buildModelMetadata(),
            ],
        ];
    }

    private function getVegasSpread(Model $game): ?float
    {
        $oddsData = $game->odds_data;

        if (empty($oddsData) || ! isset($oddsData['bookmakers'])) {
            return null;
        }

        foreach ($oddsData['bookmakers'] as $bookmaker) {
            if (! isset($bookmaker['markets']) || ! is_array($bookmaker['markets'])) {
                continue;
            }

            foreach ($bookmaker['markets'] as $market) {
                if (($market['key'] ?? null) === 'spreads' && isset($market['outcomes'])) {
                    foreach ($market['outcomes'] as $outcome) {
                        if ($this->isHomeTeamOutcome((string) ($outcome['name'] ?? ''), $game) && is_numeric($outcome['point'] ?? null)) {
                            return (float) $outcome['point'];
                        }
                    }
                }

            }
        }

        return null;
    }

    private function getMarketTotal(Model $game): ?float
    {
        $oddsData = $game->odds_data;

        if (empty($oddsData) || ! isset($oddsData['bookmakers'])) {
            return null;
        }

        foreach ($oddsData['bookmakers'] as $bookmaker) {
            if (! isset($bookmaker['markets']) || ! is_array($bookmaker['markets'])) {
                continue;
            }

            foreach ($bookmaker['markets'] as $market) {
                if (($market['key'] ?? null) !== 'totals' || ! isset($market['outcomes']) || ! is_array($market['outcomes'])) {
                    continue;
                }

                foreach ($market['outcomes'] as $outcome) {
                    if (($outcome['name'] ?? null) === 'Over' && is_numeric($outcome['point'] ?? null)) {
                        return (float) $outcome['point'];
                    }
                }
            }
        }

        return null;
    }

    private function isHomeTeamOutcome(string $outcomeName, Model $game): bool
    {
        $homeTeam = $game->homeTeam;
        $name = strtolower(trim($outcomeName));

        return $name !== ''
            && (
                str_contains($name, strtolower((string) ($homeTeam->location ?? '')))
                || str_contains($name, strtolower((string) ($homeTeam->name ?? '')))
                || $name === strtolower(trim(((string) ($homeTeam->location ?? '')).' '.((string) ($homeTeam->name ?? ''))))
                || $name === strtolower((string) ($game->odds_data['home_team'] ?? ''))
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildModelMetadata(): array
    {
        return [
            'model' => 'mlb_elo_pitcher_blend',
            'season_context' => [
                'sample_games' => $this->metadata['season_sample_games'] ?? null,
                'progress_scale' => $this->metadata['season_progress_scale'] ?? null,
                'team_weight' => $this->metadata['team_weight'] ?? null,
                'pitcher_weight' => $this->metadata['pitcher_weight'] ?? null,
                'context_weight_scale' => $this->metadata['context_weight_scale'] ?? null,
                'historical_context_weight' => $this->metadata['historical_context_weight'] ?? null,
            ],
            'historical_context' => [
                'available' => $this->metadata['historical_context_available'] ?? false,
                'spread_adjustment' => $this->metadata['historical_context_spread_adjustment'] ?? 0.0,
                'total_adjustment' => $this->metadata['historical_context_total_adjustment'] ?? 0.0,
                'home' => $this->metadata['historical_context_home'] ?? [],
                'away' => $this->metadata['historical_context_away'] ?? [],
            ],
            'situational_context' => [
                'spread_adjustment' => $this->metadata['situational_context_spread_adjustment'] ?? 0.0,
                'total_adjustment' => $this->metadata['situational_context_total_adjustment'] ?? 0.0,
                'bullpen' => data_get($this->metadata, 'situational_context.bullpen', []),
                'bullpen_quality' => data_get($this->metadata, 'situational_context.bullpen_quality', []),
                'handedness' => data_get($this->metadata, 'situational_context.handedness', []),
                'advanced_ratings' => data_get($this->metadata, 'situational_context.advanced_ratings', []),
                'starter_form' => data_get($this->metadata, 'situational_context.starter_form', []),
            ],
            'pitcher_inputs' => [
                'home_confidence' => $this->metadata['home_pitcher_confidence'] ?? null,
                'away_confidence' => $this->metadata['away_pitcher_confidence'] ?? null,
                'home_source' => $this->metadata['home_pitcher_source'] ?? null,
                'away_source' => $this->metadata['away_pitcher_source'] ?? null,
                'home_probable_pitcher_espn_id' => $this->metadata['home_probable_pitcher_espn_id'] ?? null,
                'away_probable_pitcher_espn_id' => $this->metadata['away_probable_pitcher_espn_id'] ?? null,
                'home_probable_pitcher_injury_status' => $this->metadata['home_probable_pitcher_injury_status'] ?? null,
                'away_probable_pitcher_injury_status' => $this->metadata['away_probable_pitcher_injury_status'] ?? null,
                'probable_pitcher_spread_adjustment' => $this->metadata['probable_pitcher_spread_adjustment'] ?? 0.0,
                'probable_pitcher_total_adjustment' => $this->metadata['probable_pitcher_total_adjustment'] ?? 0.0,
            ],
            'depth_chart_context' => [
                'home_pitcher_source' => $this->metadata['home_pitcher_source'] ?? null,
                'away_pitcher_source' => $this->metadata['away_pitcher_source'] ?? null,
                'home_depth_chart_fallback_used' => str_contains((string) ($this->metadata['home_pitcher_source'] ?? ''), 'depth_chart'),
                'away_depth_chart_fallback_used' => str_contains((string) ($this->metadata['away_pitcher_source'] ?? ''), 'depth_chart'),
                'probable_pitcher_injury_applied' => (($this->metadata['probable_pitcher_spread_adjustment'] ?? 0.0) != 0.0)
                    || (($this->metadata['probable_pitcher_total_adjustment'] ?? 0.0) != 0.0),
            ],
            'injury_model_source' => $this->metadata['injury_model_source'] ?? null,
            'injury_spread_model_source' => $this->metadata['injury_spread_model_source'] ?? null,
            'injury_total_model_source' => $this->metadata['injury_total_model_source'] ?? null,
            'depth_chart_injuries' => [
                'applied' => ((float) ($this->metadata['injury_total_adjustment'] ?? 0.0)) !== 0.0,
                'total_adjustment' => $this->metadata['injury_total_adjustment'] ?? 0.0,
            ],
            'market_context' => [
                'vegas_spread' => $this->metadata['vegas_spread'] ?? null,
                'market_total' => $this->metadata['market_total'] ?? null,
                ...($this->metadata['odds_market_availability'] ?? []),
            ],
        ];
    }

    /**
     * @return array{0:float,1:float,2:array<string, mixed>}
     */
    private function applyProbablePitcherInjuryAdjustments(
        Game $game,
        Team $homeTeam,
        Team $awayTeam,
        float $predictedSpread,
        float $predictedTotal
    ): array {
        $homeStatus = $this->probablePitcherInjuryStatus($game, $game->probable_home_pitcher_espn_id, $homeTeam);
        $awayStatus = $this->probablePitcherInjuryStatus($game, $game->probable_away_pitcher_espn_id, $awayTeam);

        $homeSpreadPenalty = $this->probablePitcherSpreadPenalty($homeStatus);
        $awaySpreadPenalty = $this->probablePitcherSpreadPenalty($awayStatus);
        $homeTotalBoost = $this->probablePitcherTotalBoost($homeStatus);
        $awayTotalBoost = $this->probablePitcherTotalBoost($awayStatus);

        $spreadAdjustment = round($awaySpreadPenalty - $homeSpreadPenalty, 2);
        $totalAdjustment = round($homeTotalBoost + $awayTotalBoost, 2);

        return [
            round($predictedSpread + $spreadAdjustment, 1),
            round($predictedTotal + $totalAdjustment, 1),
            [
                'home_probable_pitcher_injury_status' => $homeStatus,
                'away_probable_pitcher_injury_status' => $awayStatus,
                'probable_pitcher_spread_adjustment' => $spreadAdjustment,
                'probable_pitcher_total_adjustment' => $totalAdjustment,
            ],
        ];
    }

    private function seasonSampleGames(Game $game, ?Model $homeMetrics, ?Model $awayMetrics): int
    {
        $samples = [];

        foreach ([$homeMetrics, $awayMetrics] as $metrics) {
            if (! $metrics || (int) ($metrics->season ?? 0) !== (int) $game->season) {
                continue;
            }

            $wins = (int) ($metrics->wins ?? 0);
            $losses = (int) ($metrics->losses ?? 0);
            $samples[] = max(0, $wins + $losses);
        }

        if ($samples === []) {
            return 0;
        }

        return min($samples);
    }

    private function seasonProgressScale(Game $game, ?Model $homeMetrics, ?Model $awayMetrics): float
    {
        $rampGames = max(1, (int) config('mlb.prediction.early_season.ramp_games', 20));
        $sampleGames = $this->seasonSampleGames($game, $homeMetrics, $awayMetrics);

        return min(1.0, $sampleGames / $rampGames);
    }

    /**
     * @return array{0:float,1:float}
     */
    private function dynamicEloWeights(float $seasonProgressScale): array
    {
        $baseTeamWeight = (float) config('mlb.elo.team_weight');
        $startTeamWeight = (float) config('mlb.prediction.early_season.team_weight_start', 0.45);
        $startTeamWeight = max(0.0, min(1.0, $startTeamWeight));
        $baseTeamWeight = max(0.0, min(1.0, $baseTeamWeight));

        $teamWeight = $startTeamWeight + (($baseTeamWeight - $startTeamWeight) * $seasonProgressScale);
        $pitcherWeight = 1.0 - $teamWeight;

        return [$teamWeight, $pitcherWeight];
    }

    private function contextWeightScale(float $seasonProgressScale): float
    {
        $minimumScale = (float) config('mlb.prediction.early_season.context_scale_min', 0.35);
        $minimumScale = max(0.0, min(1.0, $minimumScale));

        return $minimumScale + ((1.0 - $minimumScale) * $seasonProgressScale);
    }

    /**
     * @param  array<string, mixed>  $historicalContext
     */
    private function historicalContextWeight(float $seasonProgressScale, array $historicalContext): float
    {
        if (($historicalContext['available'] ?? false) !== true) {
            return 0.0;
        }

        $maxWeight = (float) config('mlb.prediction.historical_priors.max_weight', 0.35);
        $maxWeight = max(0.0, min(1.0, $maxWeight));

        return round($maxWeight * (1.0 - max(0.0, min(1.0, $seasonProgressScale))), 3);
    }

    private function probablePitcherInjuryStatus(Game $game, ?string $probablePitcherEspnId, Team $team): ?string
    {
        if (! $probablePitcherEspnId) {
            return null;
        }

        $player = Player::query()
            ->where('espn_id', $probablePitcherEspnId)
            ->first();

        if (! $player) {
            return null;
        }

        return PlayerInjury::query()
            ->where('player_id', $player->id)
            ->where('team_id', $team->id)
            ->where('is_active', true)
            ->where(function ($query) use ($game) {
                $query->whereNull('injury_date')
                    ->orWhereDate('injury_date', '<=', $game->game_date);
            })
            ->where(function ($query) use ($game) {
                $query->whereNull('return_date')
                    ->orWhereDate('return_date', '>=', $game->game_date);
            })
            ->where(function ($query) use ($game) {
                $query->whereNull('source_updated_at')
                    ->orWhereDate('source_updated_at', '<=', $game->game_date);
            })
            ->latest('source_updated_at')
            ->value('status');
    }

    private function probablePitcherSpreadPenalty(?string $status): float
    {
        return match ($this->injuryStatusBucket((string) $status)) {
            'out' => (float) config('mlb.prediction.probable_pitcher_out_spread_penalty', 1.1),
            'questionable' => (float) config('mlb.prediction.probable_pitcher_questionable_spread_penalty', 0.45),
            default => 0.0,
        };
    }

    private function probablePitcherTotalBoost(?string $status): float
    {
        return match ($this->injuryStatusBucket((string) $status)) {
            'out' => (float) config('mlb.prediction.probable_pitcher_out_total_boost', 0.7),
            'questionable' => (float) config('mlb.prediction.probable_pitcher_questionable_total_boost', 0.25),
            default => 0.0,
        };
    }
}
