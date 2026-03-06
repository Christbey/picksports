<?php

namespace App\Actions\NBA;

use App\Actions\Sports\AbstractPredictionGenerator;
use App\Jobs\NBA\GeneratePredictionNarrative as GeneratePredictionNarrativeJob;
use App\Models\NBA\Game;
use App\Models\NBA\PlayerInjury;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;
use App\Models\NBA\TeamStat;
use Illuminate\Database\Eloquent\Model;

class GeneratePrediction extends AbstractPredictionGenerator
{
    /** @var array<string, mixed> Cached metadata for the current prediction */
    private array $metadata = [];
    /** @var array<string, mixed> True EPA rollout metadata */
    private array $trueEpaMetadata = [];

    protected const SPORT_KEY = 'nba';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;

    public function execute(Model $game): ?Model
    {
        $prediction = parent::execute($game);

        if ($prediction instanceof Prediction) {
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

        $config = config('nba.prediction');
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
        $injurySpreadAdj = $injuryContext['spread_adj'];

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
            'injury_spread_adj' => round($injurySpreadAdj, 2),
            'injury_total_adj' => round($injuryContext['total_adj'], 2),
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

        $homeOffEff = $homeMetrics?->offensive_efficiency ?? $defaultEfficiency;
        $homeDefEff = $homeMetrics?->defensive_efficiency ?? $defaultEfficiency;
        $awayOffEff = $awayMetrics?->offensive_efficiency ?? $defaultEfficiency;
        $awayDefEff = $awayMetrics?->defensive_efficiency ?? $defaultEfficiency;

        $homePredictedScore = ($homeOffEff + $awayDefEff) / 2;
        $awayPredictedScore = ($awayOffEff + $homeDefEff) / 2;

        // Blend season tempo with recent form tempo
        $seasonPace = ($homeMetrics?->tempo ?? $config['average_pace'])
            + ($awayMetrics?->tempo ?? $config['average_pace']);
        $seasonPace /= 2;

        $homeForm = $this->getRecentForm($game->homeTeam, $game->season);
        $awayForm = $this->getRecentForm($game->awayTeam, $game->season);
        $formPace = ($homeForm['tempo'] + $awayForm['tempo']) / 2;

        $pace = ($seasonPace + $formPace) / 2;

        // B2B teams tend to play slower
        $restHome = $this->metadata['rest_days_home'] ?? null;
        $restAway = $this->metadata['rest_days_away'] ?? null;
        $paceAdj = 0;
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
        $this->trueEpaMetadata = [...$this->trueEpaMetadata, ...$trueEpaTotalMeta];

        return round($blendedTotal, 1);
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

        return array_merge(
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
            $this->metadata,
            [
                'model_metadata' => [
                    'model' => 'nba_ensemble',
                    'true_epa' => $this->trueEpaMetadata,
                ],
            ]
        );
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
            ->where('status', 'STATUS_FINAL')
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
            ->where('status', 'STATUS_FINAL')
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

                // Fall back to h2h moneyline → implied spread
                if ($market['key'] === 'h2h') {
                    return $this->moneylineToSpread($market['outcomes'], $game);
                }
            }
        }

        return null;
    }

    /**
     * Check if an outcome name matches the home team.
     */
    private function isHomeTeamOutcome(string $outcomeName, Model $game): bool
    {
        $homeTeam = $game->homeTeam;
        $name = strtolower($outcomeName);
        $teamName = strtolower(trim($homeTeam->location . ' ' . $homeTeam->name));
        $mascot = strtolower($homeTeam->name ?? '');

        return str_contains($name, strtolower($homeTeam->location ?? ''))
            || str_contains($name, $mascot)
            || $name === $teamName;
    }

    /**
     * Convert moneyline odds to an approximate spread.
     */
    private function moneylineToSpread(array $outcomes, Model $game): ?float
    {
        $homeOdds = null;
        $awayOdds = null;

        foreach ($outcomes as $outcome) {
            if ($this->isHomeTeamOutcome($outcome['name'], $game)) {
                $homeOdds = (float) $outcome['price'];
            } else {
                $awayOdds = (float) $outcome['price'];
            }
        }

        if ($homeOdds === null || $awayOdds === null) {
            return null;
        }

        // Convert moneyline to implied probability then to spread
        $homeProb = $this->moneylineToProbability($homeOdds);
        $awayProb = $this->moneylineToProbability($awayOdds);

        if ($homeProb + $awayProb === 0.0) {
            return null;
        }

        // Normalize (remove vig)
        $total = $homeProb + $awayProb;
        $homeProb /= $total;

        // Convert probability to approximate spread using logistic inverse
        // spread ≈ -coefficient * ln((1/prob) - 1)
        if ($homeProb <= 0 || $homeProb >= 1) {
            return null;
        }

        $coefficient = config('nba.prediction.spread_to_probability_coefficient');

        return round(-$coefficient * log((1 / $homeProb) - 1), 2);
    }

    /**
     * Convert American moneyline odds to implied probability.
     */
    private function moneylineToProbability(float $odds): float
    {
        if ($odds > 0) {
            return 100 / ($odds + 100);
        }

        if ($odds < 0) {
            return abs($odds) / (abs($odds) + 100);
        }

        return 0.5;
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
        $homeWeighted = $this->nbaWeightedInjuryCountsForTeam((int) $game->home_team_id);
        $awayWeighted = $this->nbaWeightedInjuryCountsForTeam((int) $game->away_team_id);

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
            'spread_adj' => round($spreadAdj, 2),
            'total_adj' => round($totalAdj, 2),
        ];
    }

    /**
     * @return array{out:float, questionable:float}
     */
    private function nbaWeightedInjuryCountsForTeam(int $teamId): array
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
                $counts[$bucket] += $this->injuryImpactMultiplier('nba', (int) ($injury->player_id ?? 0));
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
        if (! config('nba.prediction.true_epa.enabled', false)) {
            return [$legacySpread, [
                'true_epa_enabled' => false,
                'true_epa_applied' => false,
                'true_epa_reason' => 'feature_disabled',
            ]];
        }

        $homeNet = $homeMetrics?->net_true_epa_per_play;
        $awayNet = $awayMetrics?->net_true_epa_per_play;
        if ($homeNet === null || $awayNet === null) {
            return [$legacySpread, [
                'true_epa_enabled' => true,
                'true_epa_applied' => false,
                'true_epa_reason' => 'missing_net_true_epa',
            ]];
        }

        $weight = $this->clamp((float) config('nba.prediction.true_epa.blend_weight', 0.30), 0.0, 1.0);
        $epaDiff = (float) $homeNet - (float) $awayNet;
        $epaSpread = $epaDiff * (float) config('nba.prediction.true_epa.spread_points_per_epa', 20.0);
        $blendedSpread = $this->blend($legacySpread, $epaSpread, $weight);

        return [$blendedSpread, [
            'true_epa_enabled' => true,
            'true_epa_applied' => true,
            'true_epa_reason' => 'applied',
            'true_epa_weight' => round($weight, 4),
            'true_epa_diff' => round($epaDiff, 6),
            'true_epa_spread_component' => round($epaSpread, 4),
        ]];
    }

    /**
     * @return array{0:float,1:array<string,mixed>}
     */
    private function applyTrueEpaTotalBlend(float $legacyTotal, ?Model $homeMetrics, ?Model $awayMetrics): array
    {
        if (! config('nba.prediction.true_epa.enabled', false)) {
            return [$legacyTotal, []];
        }

        $homeOff = $homeMetrics?->offensive_true_epa_per_play;
        $homeDef = $homeMetrics?->defensive_true_epa_per_play;
        $awayOff = $awayMetrics?->offensive_true_epa_per_play;
        $awayDef = $awayMetrics?->defensive_true_epa_per_play;

        if ($homeOff === null || $homeDef === null || $awayOff === null || $awayDef === null) {
            return [$legacyTotal, ['true_epa_total_reason' => 'missing_off_def_true_epa']];
        }

        $weight = $this->clamp((float) config('nba.prediction.true_epa.blend_weight', 0.30), 0.0, 1.0);
        $scale = (float) config('nba.prediction.true_epa.total_points_per_epa_component', 35.0);
        $homeExpectedDelta = ((float) $homeOff - (float) $awayDef) * $scale;
        $awayExpectedDelta = ((float) $awayOff - (float) $homeDef) * $scale;
        $epaTotal = $legacyTotal + $homeExpectedDelta + $awayExpectedDelta;

        $blendedTotal = $this->blend($legacyTotal, $epaTotal, $weight);
        $blendedTotal = $this->clamp(
            $blendedTotal,
            (float) config('nba.prediction.true_epa.min_predicted_total', 180.0),
            (float) config('nba.prediction.true_epa.max_predicted_total', 270.0)
        );

        return [$blendedTotal, [
            'true_epa_total_component' => round($epaTotal, 4),
            'true_epa_total_reason' => 'applied',
        ]];
    }

    private function blend(float $legacy, float $epaBased, float $weight): float
    {
        return ($legacy * (1 - $weight)) + ($epaBased * $weight);
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
