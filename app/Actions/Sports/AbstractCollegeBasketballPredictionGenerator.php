<?php

namespace App\Actions\Sports;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractCollegeBasketballPredictionGenerator extends AbstractPredictionGenerator
{
    protected const GAME_MODEL = '';

    protected const TEAM_STAT_MODEL = '';

    /** @var array<string, mixed> Cached metadata for the current prediction */
    private array $metadata = [];

    protected function getGameModel(): string
    {
        if (static::GAME_MODEL === '') {
            throw new \RuntimeException('GAME_MODEL must be defined on college basketball prediction action.');
        }

        return static::GAME_MODEL;
    }

    protected function getTeamStatModel(): string
    {
        if (static::TEAM_STAT_MODEL === '') {
            throw new \RuntimeException('TEAM_STAT_MODEL must be defined on college basketball prediction action.');
        }

        return static::TEAM_STAT_MODEL;
    }

    protected function calculatePredictedSpread(
        int $homeElo,
        int $awayElo,
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        $sport = $this->getSport();
        $config = config("{$sport}.prediction");
        $homeCourtAdvantage = config("{$sport}.elo.home_court_advantage");

        $eloSpread = ($homeElo + $homeCourtAdvantage - $awayElo) / $config['elo_to_spread_divisor'];

        $homeNetRating = $homeMetrics?->net_rating ?? 0;
        $awayNetRating = $awayMetrics?->net_rating ?? 0;
        $efficiencySpread = ($homeNetRating - $awayNetRating) / 2 + $config['home_court_points'];

        $homeAwaySplitAdj = $this->calculateHomeAwaySplitAdjustment($game, $homeMetrics, $awayMetrics);
        $efficiencySpread += $homeAwaySplitAdj * $config['home_away_split_weight'];

        $homeForm = $this->getRecentForm($game->homeTeam, (int) $game->season);
        $awayForm = $this->getRecentForm($game->awayTeam, (int) $game->season);
        $homeFormNet = $homeForm['net_rating'];
        $awayFormNet = $awayForm['net_rating'];
        $formSpread = ($homeFormNet - $awayFormNet) / 2 + $config['home_court_points'];

        $restHome = $this->getRestDays($game->homeTeam, $game);
        $restAway = $this->getRestDays($game->awayTeam, $game);
        $restAdj = $this->calculateRestAdjustment($restHome, $restAway, $config);

        $turnoverAdj = $this->calculateTurnoverAdjustment($game, $config);
        $reboundAdj = $this->calculateReboundAdjustment($game, $config);

        $modelSpread = ($config['elo_weight'] * $eloSpread)
            + ($config['efficiency_weight'] * $efficiencySpread)
            + ($config['form_weight'] * $formSpread)
            + $restAdj
            + $turnoverAdj
            + $reboundAdj;

        $vegasSpread = $this->getVegasSpread($game);
        $finalSpread = $vegasSpread !== null
            ? ($config['model_weight_with_vegas'] * $modelSpread) + ($config['vegas_weight'] * $vegasSpread)
            : $modelSpread;

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
        ];

        return round($finalSpread, 1);
    }

    protected function calculatePredictedTotal(
        ?Model $homeMetrics,
        ?Model $awayMetrics,
        Model $game
    ): float {
        $sport = $this->getSport();
        $config = config("{$sport}.prediction");
        $defaultEfficiency = $config['default_efficiency'];

        $homeOffEff = $homeMetrics?->offensive_efficiency ?? $defaultEfficiency;
        $homeDefEff = $homeMetrics?->defensive_efficiency ?? $defaultEfficiency;
        $awayOffEff = $awayMetrics?->offensive_efficiency ?? $defaultEfficiency;
        $awayDefEff = $awayMetrics?->defensive_efficiency ?? $defaultEfficiency;

        $homePredictedScore = ($homeOffEff + $awayDefEff) / 2;
        $awayPredictedScore = ($awayOffEff + $homeDefEff) / 2;

        $seasonPace = (($homeMetrics?->tempo ?? $config['average_pace']) + ($awayMetrics?->tempo ?? $config['average_pace'])) / 2;

        $homeForm = $this->getRecentForm($game->homeTeam, (int) $game->season);
        $awayForm = $this->getRecentForm($game->awayTeam, (int) $game->season);
        $formPace = ($homeForm['tempo'] + $awayForm['tempo']) / 2;

        $pace = ($seasonPace + $formPace) / 2;

        $restHome = $this->metadata['rest_days_home'] ?? null;
        $restAway = $this->metadata['rest_days_away'] ?? null;

        if ($restHome !== null && $restHome <= 1) {
            $pace -= 1.0;
        }
        if ($restAway !== null && $restAway <= 1) {
            $pace -= 1.0;
        }

        return round(($homePredictedScore + $awayPredictedScore) * ($pace / 100), 1);
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
        $defaultEfficiency = config("{$this->getSport()}.prediction.default_efficiency");

        return array_merge([
            'home_elo' => $homeElo,
            'away_elo' => $awayElo,
            'home_off_eff' => $homeMetrics?->offensive_efficiency ?? $defaultEfficiency,
            'home_def_eff' => $homeMetrics?->defensive_efficiency ?? $defaultEfficiency,
            'away_off_eff' => $awayMetrics?->offensive_efficiency ?? $defaultEfficiency,
            'away_def_eff' => $awayMetrics?->defensive_efficiency ?? $defaultEfficiency,
            'predicted_spread' => $predictedSpread,
            'predicted_total' => $predictedTotal,
            'win_probability' => $winProbability,
            'confidence_score' => $confidenceScore,
        ], $this->metadata);
    }

    /**
     * @return array{off_eff: float, def_eff: float, net_rating: float, tempo: float}
     */
    private function getRecentForm(Model $team, int $season): array
    {
        $sport = $this->getSport();
        $config = config("{$sport}.prediction");
        $numGames = $config['recent_form_games'];
        $decay = $config['recency_decay'];
        $defaultEff = $config['default_efficiency'];
        $defaultPace = $config['average_pace'];

        $gameModel = $this->getGameModel();
        $teamStatModel = $this->getTeamStatModel();
        $gameTable = (new $gameModel)->getTable();
        $teamStatTable = (new $teamStatModel)->getTable();

        $recentGames = $gameModel::query()
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

        $stats = $teamStatModel::query()
            ->where('team_id', $team->id)
            ->whereIn('game_id', $recentGames)
            ->join($gameTable, "{$teamStatTable}.game_id", '=', "{$gameTable}.id")
            ->orderByDesc("{$gameTable}.game_date")
            ->select("{$teamStatTable}.*")
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

            $opponentStat = $teamStatModel::query()
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

    private function getRestDays(Model $team, Model $game): ?int
    {
        $gameModel = $this->getGameModel();

        $lastGame = $gameModel::query()
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

    private function calculateHomeAwaySplitAdjustment(Model $game, ?Model $homeMetrics, ?Model $awayMetrics): float
    {
        $homeStats = $this->getVenueEfficiency($game->homeTeam, (int) $game->season, 'home');
        $awayStats = $this->getVenueEfficiency($game->awayTeam, (int) $game->season, 'away');

        $homeSeasonNet = $homeMetrics?->net_rating ?? 0;
        $awaySeasonNet = $awayMetrics?->net_rating ?? 0;

        $homeVenueAdj = ($homeStats['net'] ?? 0) - $homeSeasonNet;
        $awayVenueAdj = ($awayStats['net'] ?? 0) - $awaySeasonNet;

        return $homeVenueAdj - $awayVenueAdj;
    }

    /**
     * @return array{off: float, def: float, net: float}
     */
    private function getVenueEfficiency(Model $team, int $season, string $venue): array
    {
        $defaultEff = config("{$this->getSport()}.prediction.default_efficiency");

        $teamStatModel = $this->getTeamStatModel();
        $games = $this->completedSeasonGameIdsForTeam($team, $season, $venue);

        if (empty($games)) {
            return ['off' => $defaultEff, 'def' => $defaultEff, 'net' => 0];
        }

        $stats = $teamStatModel::query()
            ->where('team_id', $team->id)
            ->whereIn('game_id', $games)
            ->get();

        if ($stats->isEmpty()) {
            return ['off' => $defaultEff, 'def' => $defaultEff, 'net' => 0];
        }

        $totalPoints = $stats->sum('points');
        $totalPoss = $stats->sum('possessions');

        $offEff = $totalPoss > 0 ? ($totalPoints / $totalPoss) * 100 : $defaultEff;

        $opponentPoints = $teamStatModel::query()
            ->where('team_id', '!=', $team->id)
            ->whereIn('game_id', $games)
            ->sum('points');

        $opponentPoss = $teamStatModel::query()
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

    private function calculateRestAdjustment(?int $restHome, ?int $restAway, array $config): float
    {
        $adj = 0;

        if ($restHome !== null && $restHome <= 1) {
            $adj += $config['back_to_back_penalty'];
        }
        if ($restAway !== null && $restAway <= 1) {
            $adj -= $config['back_to_back_penalty'];
        }

        if ($restHome !== null && $restAway !== null) {
            $restDiff = min(3, max(-3, $restHome - $restAway));
            $adj += $restDiff * $config['rest_day_adjustment'];
        }

        return $adj;
    }

    private function calculateTurnoverAdjustment(Model $game, array $config): float
    {
        $homeDiff = $this->getTurnoverDifferential($game->homeTeam, (int) $game->season);
        $awayDiff = $this->getTurnoverDifferential($game->awayTeam, (int) $game->season);

        return ($homeDiff - $awayDiff) * $config['turnover_diff_weight'];
    }

    private function getTurnoverDifferential(Model $team, int $season): float
    {
        $teamStatModel = $this->getTeamStatModel();
        $games = $this->completedSeasonGameIdsForTeam($team, $season);

        if (empty($games)) {
            return 0;
        }

        $avgCommitted = $teamStatModel::query()
            ->where('team_id', $team->id)
            ->whereIn('game_id', $games)
            ->avg('turnovers') ?? 0;

        $avgForced = $teamStatModel::query()
            ->where('team_id', '!=', $team->id)
            ->whereIn('game_id', $games)
            ->avg('turnovers') ?? 0;

        return round($avgForced - $avgCommitted, 2);
    }

    private function calculateReboundAdjustment(Model $game, array $config): float
    {
        $homeMargin = $this->getReboundMargin($game->homeTeam, (int) $game->season);
        $awayMargin = $this->getReboundMargin($game->awayTeam, (int) $game->season);

        return ($homeMargin - $awayMargin) * $config['rebound_margin_weight'];
    }

    private function getReboundMargin(Model $team, int $season): float
    {
        $teamStatModel = $this->getTeamStatModel();
        $games = $this->completedSeasonGameIdsForTeam($team, $season);

        if (empty($games)) {
            return 0;
        }

        $avgTeamRebounds = $teamStatModel::query()
            ->where('team_id', $team->id)
            ->whereIn('game_id', $games)
            ->avg('rebounds') ?? 0;

        $avgOpponentRebounds = $teamStatModel::query()
            ->where('team_id', '!=', $team->id)
            ->whereIn('game_id', $games)
            ->avg('rebounds') ?? 0;

        return round($avgTeamRebounds - $avgOpponentRebounds, 2);
    }

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
                if ($market['key'] === 'spreads') {
                    foreach ($market['outcomes'] as $outcome) {
                        if ($this->isHomeTeamOutcome($outcome['name'], $game)) {
                            return (float) $outcome['point'];
                        }
                    }
                }

                if ($market['key'] === 'h2h') {
                    return $this->moneylineToSpread($market['outcomes'], $game);
                }
            }
        }

        return null;
    }

    private function isHomeTeamOutcome(string $outcomeName, Model $game): bool
    {
        $homeTeam = $game->homeTeam;
        $name = strtolower($outcomeName);
        $school = strtolower((string) ($homeTeam->school ?? ''));
        $mascot = strtolower((string) ($homeTeam->mascot ?? ''));

        return str_contains($name, $school)
            || str_contains($name, $mascot)
            || $name === strtolower(trim($school.' '.$mascot));
    }

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

        $homeProb = $this->moneylineToProbability($homeOdds);
        $awayProb = $this->moneylineToProbability($awayOdds);

        if ($homeProb + $awayProb === 0.0) {
            return null;
        }

        $homeProb /= ($homeProb + $awayProb);

        if ($homeProb <= 0 || $homeProb >= 1) {
            return null;
        }

        $coefficient = config("{$this->getSport()}.prediction.spread_to_probability_coefficient");

        return round(-$coefficient * log((1 / $homeProb) - 1), 2);
    }

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
    private function completedSeasonGameIdsForTeam(Model $team, int $season, ?string $venue = null): array
    {
        $gameModel = $this->getGameModel();
        $query = $gameModel::query()
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

}
