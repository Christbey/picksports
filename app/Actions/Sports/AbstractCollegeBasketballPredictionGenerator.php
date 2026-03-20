<?php

namespace App\Actions\Sports;

use App\Actions\Sports\Concerns\AppliesTrueEpaPredictionBlend;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractCollegeBasketballPredictionGenerator extends AbstractPredictionGenerator
{
    use AppliesTrueEpaPredictionBlend;

    protected const GAME_MODEL = '';

    protected const TEAM_STAT_MODEL = '';

    /** @var array<string, mixed> Cached metadata for the current prediction */
    private array $metadata = [];
    /** @var array<string, mixed> True EPA rollout metadata */
    private array $trueEpaMetadata = [];
    /** @var array<string, mixed> Total model metadata */
    private array $totalMetadata = [];

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
        $this->metadata = [];
        $this->trueEpaMetadata = [];
        $this->totalMetadata = [];

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
        [$finalSpread, $trueEpaSpreadMeta] = $this->applyTrueEpaSpreadBlend(
            (float) $finalSpread,
            $homeMetrics,
            $awayMetrics
        );

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
        $this->trueEpaMetadata = [...$this->trueEpaMetadata, ...$trueEpaSpreadMeta];

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

        $homeForm = $this->getRecentForm($game->homeTeam, (int) $game->season);
        $awayForm = $this->getRecentForm($game->awayTeam, (int) $game->season);
        $recentWeight = (float) ($config['total_recent_efficiency_weight'] ?? 0.35);
        $venueWeight = (float) ($config['total_venue_efficiency_weight'] ?? 0.15);

        $homeSeasonScore = ($homeOffEff + $awayDefEff) / 2;
        $awaySeasonScore = ($awayOffEff + $homeDefEff) / 2;
        $homeRecentScore = (
            (float) ($homeMetrics?->rolling_offensive_efficiency ?? $homeForm['off_eff'])
            + (float) ($awayMetrics?->rolling_defensive_efficiency ?? $awayForm['def_eff'])
        ) / 2;
        $awayRecentScore = (
            (float) ($awayMetrics?->rolling_offensive_efficiency ?? $awayForm['off_eff'])
            + (float) ($homeMetrics?->rolling_defensive_efficiency ?? $homeForm['def_eff'])
        ) / 2;
        $homeVenueScore = $this->pairedVenueScore(
            $homeMetrics?->home_offensive_efficiency,
            $awayMetrics?->away_defensive_efficiency
        );
        $awayVenueScore = $this->pairedVenueScore(
            $awayMetrics?->away_offensive_efficiency,
            $homeMetrics?->home_defensive_efficiency
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

        $seasonPace = (($homeMetrics?->tempo ?? $config['average_pace']) + ($awayMetrics?->tempo ?? $config['average_pace'])) / 2;
        $recentPace = (
            (float) ($homeMetrics?->rolling_tempo ?? $homeForm['tempo'])
            + (float) ($awayMetrics?->rolling_tempo ?? $awayForm['tempo'])
        ) / 2;
        $calibration = (array) ($config['total_calibration'] ?? []);
        $maxRecentPaceDrop = (float) ($calibration['max_recent_pace_drop'] ?? 8.0);
        $recentPaceFloor = max(0.0, $seasonPace - $maxRecentPaceDrop);
        $recentPace = max($recentPace, $recentPaceFloor);
        $pace = $this->blendWeightedValues([
            ['value' => $seasonPace, 'weight' => 1.0],
            ['value' => $recentPace, 'weight' => $recentWeight],
        ]);
        $paceFloor = (float) ($calibration['pace_floor'] ?? 62.0);
        $paceFloorBlend = (float) ($calibration['pace_floor_blend'] ?? 0.5);
        if ($pace < $paceFloor) {
            $pace += ($paceFloor - $pace) * $paceFloorBlend;
        }

        $restHome = $this->metadata['rest_days_home'] ?? null;
        $restAway = $this->metadata['rest_days_away'] ?? null;
        $restPaceAdjustment = 0.0;

        if ($restHome !== null && $restHome <= 1) {
            $restPaceAdjustment -= 1.0;
        }
        if ($restAway !== null && $restAway <= 1) {
            $restPaceAdjustment -= 1.0;
        }
        $pace += $restPaceAdjustment;

        $factorAdjustments = $this->recentPossessionFactorAdjustments($game, (int) $game->season, $config);
        $factorAdjustmentCap = (float) ($calibration['factor_adjustment_cap'] ?? 5.0);
        $homeFactorAdjustment = $this->clamp($factorAdjustments['home_adjustment'], -$factorAdjustmentCap, $factorAdjustmentCap);
        $awayFactorAdjustment = $this->clamp($factorAdjustments['away_adjustment'], -$factorAdjustmentCap, $factorAdjustmentCap);
        $homePredictedScore += $homeFactorAdjustment;
        $awayPredictedScore += $awayFactorAdjustment;

        $legacyTotal = ($homePredictedScore + $awayPredictedScore) * ($pace / 100);
        [$blendedTotal, $trueEpaTotalMeta] = $this->applyTrueEpaTotalBlend(
            (float) $legacyTotal,
            $homeMetrics,
            $awayMetrics
        );
        $baseAdjustment = (float) ($calibration['base_adjustment'] ?? 4.0);
        $tournamentAdjustment = $this->roundOf64TotalAdjustment($game, $calibration);
        $highTotalThreshold = (float) ($calibration['high_total_threshold'] ?? 135.0);
        $highTotalSlope = (float) ($calibration['high_total_slope'] ?? 1.2);
        $highTotalBoost = max(0.0, $blendedTotal - $highTotalThreshold) * $highTotalSlope;
        $calibratedTotal = $blendedTotal + $baseAdjustment + $tournamentAdjustment + $highTotalBoost;
        $this->trueEpaMetadata = [...$this->trueEpaMetadata, ...$trueEpaTotalMeta];
        $this->totalMetadata = [
            'season_home_score_component' => round($homeSeasonScore, 3),
            'season_away_score_component' => round($awaySeasonScore, 3),
            'recent_home_score_component' => round($homeRecentScore, 3),
            'recent_away_score_component' => round($awayRecentScore, 3),
            'venue_home_score_component' => $homeVenueScore !== null ? round($homeVenueScore, 3) : null,
            'venue_away_score_component' => $awayVenueScore !== null ? round($awayVenueScore, 3) : null,
            'home_total_factor_adjustment_raw' => round($factorAdjustments['home_adjustment'], 3),
            'away_total_factor_adjustment_raw' => round($factorAdjustments['away_adjustment'], 3),
            'home_total_factor_adjustment' => round($homeFactorAdjustment, 3),
            'away_total_factor_adjustment' => round($awayFactorAdjustment, 3),
            'season_pace' => round($seasonPace, 3),
            'recent_pace' => round($recentPace, 3),
            'recent_pace_floor' => round($recentPaceFloor, 3),
            'max_recent_pace_drop' => round($maxRecentPaceDrop, 3),
            'rest_pace_adjustment' => round($restPaceAdjustment, 3),
            'pace_floor' => round($paceFloor, 3),
            'pace_floor_blend' => round($paceFloorBlend, 3),
            'blended_pace' => round($pace, 3),
            'legacy_total' => round($legacyTotal, 3),
            'post_epa_total' => round($blendedTotal, 3),
            'total_base_adjustment' => round($baseAdjustment, 3),
            'tournament_adjustment' => round($tournamentAdjustment, 3),
            'high_total_boost' => round($highTotalBoost, 3),
            'calibrated_total' => round($calibratedTotal, 3),
            'recent_factor_profile' => $factorAdjustments['metadata'],
        ];

        return round($calibratedTotal, 1);
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
            'model_metadata' => [
                'model' => "{$this->getSport()}_ensemble",
                'true_epa' => $this->trueEpaMetadata,
                'total_model' => $this->totalMetadata,
            ],
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

    private function pairedVenueScore(mixed $offense, mixed $defense): ?float
    {
        if (! is_numeric($offense) || ! is_numeric($defense)) {
            return null;
        }

        return (((float) $offense) + ((float) $defense)) / 2;
    }

    /**
     * @param  array<int, array{value:float|null, weight:float}>  $components
     */
    private function blendWeightedValues(array $components): float
    {
        $weightedTotal = 0.0;
        $weightTotal = 0.0;

        foreach ($components as $component) {
            if ($component['value'] === null || $component['weight'] <= 0) {
                continue;
            }

            $weightedTotal += $component['value'] * $component['weight'];
            $weightTotal += $component['weight'];
        }

        return $weightTotal > 0 ? ($weightedTotal / $weightTotal) : 0.0;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{
     *   home_adjustment: float,
     *   away_adjustment: float,
     *   metadata: array<string, mixed>
     * }
     */
    private function recentPossessionFactorAdjustments(Model $game, int $season, array $config): array
    {
        $homeProfile = $this->recentStatProfile($game->homeTeam, $season);
        $awayProfile = $this->recentStatProfile($game->awayTeam, $season);
        $weights = (array) ($config['total_factor_weights'] ?? []);

        return [
            'home_adjustment' => round($this->computeProfileAdjustment($homeProfile, $awayProfile, $weights), 4),
            'away_adjustment' => round($this->computeProfileAdjustment($awayProfile, $homeProfile, $weights), 4),
            'metadata' => [
                'home' => $homeProfile,
                'away' => $awayProfile,
            ],
        ];
    }

    /**
     * @param  array<string, float|null>  $offense
     * @param  array<string, float|null>  $defense
     * @param  array<string, mixed>  $weights
     */
    private function computeProfileAdjustment(array $offense, array $defense, array $weights): float
    {
        $adjustment = 0.0;
        $adjustment += $this->scaledProfileDifference(
            $offense['effective_fg_pct'],
            $defense['effective_fg_pct_allowed'],
            (float) ($weights['effective_fg_pct'] ?? 40.0)
        );
        $adjustment += $this->scaledProfileDifference(
            $offense['free_throw_rate'],
            $defense['free_throw_rate_allowed'],
            (float) ($weights['free_throw_rate'] ?? 18.0)
        );
        $adjustment -= $this->scaledProfileDifference(
            $offense['turnover_rate'],
            $defense['turnover_force_rate'],
            (float) ($weights['turnover_rate'] ?? 18.0)
        );
        $adjustment += $this->scaledProfileDifference(
            $offense['offensive_rebound_rate'],
            $defense['offensive_rebound_rate_allowed'],
            (float) ($weights['offensive_rebound_rate'] ?? 10.0)
        );

        return $adjustment;
    }

    private function scaledProfileDifference(?float $offenseValue, ?float $defenseValue, float $weight): float
    {
        if ($offenseValue === null || $defenseValue === null) {
            return 0.0;
        }

        return ($offenseValue - $defenseValue) * $weight;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /**
     * Adds a modest round-of-64 lift for real seed-gap games, which tend to run
     * faster and score later than the raw regular-season inputs imply.
     *
     * @param  array<string, mixed>  $calibration
     */
    private function roundOf64TotalAdjustment(Model $game, array $calibration): float
    {
        if (($game->tournament_round ?? null) !== 'round_of_64') {
            return 0.0;
        }

        $homeSeed = (int) ($game->home_seed ?? 0);
        $awaySeed = (int) ($game->away_seed ?? 0);
        if ($homeSeed <= 0 || $awaySeed <= 0) {
            return 0.0;
        }

        $seedGap = abs($homeSeed - $awaySeed);
        $threshold = (int) ($calibration['round_of_64_seed_gap_threshold'] ?? 6);
        if ($seedGap < $threshold) {
            return 0.0;
        }

        $base = (float) ($calibration['round_of_64_base_adjustment'] ?? 3.5);
        $perSeed = (float) ($calibration['round_of_64_seed_gap_points'] ?? 0.8);

        return $base + (($seedGap - $threshold) * $perSeed);
    }

    /**
     * @return array<string, float|null>
     */
    private function recentStatProfile(Model $team, int $season): array
    {
        $sport = $this->getSport();
        $config = config("{$sport}.prediction");
        $numGames = (int) ($config['recent_form_games'] ?? 10);
        $decay = (float) ($config['recency_decay'] ?? 0.9);
        $defaultPace = (float) ($config['average_pace'] ?? 70.0);

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
            return $this->emptyRecentStatProfile();
        }

        $stats = $teamStatModel::query()
            ->where('team_id', $team->id)
            ->whereIn('game_id', $recentGames)
            ->join($gameTable, "{$teamStatTable}.game_id", '=', "{$gameTable}.id")
            ->orderByDesc("{$gameTable}.game_date")
            ->select("{$teamStatTable}.*")
            ->get();

        if ($stats->isEmpty()) {
            return $this->emptyRecentStatProfile();
        }

        $accumulator = [
            'effective_fg_pct' => 0.0,
            'free_throw_rate' => 0.0,
            'turnover_rate' => 0.0,
            'offensive_rebound_rate' => 0.0,
            'effective_fg_pct_allowed' => 0.0,
            'free_throw_rate_allowed' => 0.0,
            'turnover_force_rate' => 0.0,
            'offensive_rebound_rate_allowed' => 0.0,
        ];
        $totalWeight = 0.0;

        foreach ($stats as $index => $stat) {
            $opponentStat = $teamStatModel::query()
                ->where('game_id', $stat->game_id)
                ->where('team_id', '!=', $team->id)
                ->first();

            if (! $opponentStat) {
                continue;
            }

            $weight = pow($decay, $index);
            $possessions = $this->statPossessions($stat, $defaultPace);
            $opponentPossessions = $this->statPossessions($opponentStat, $defaultPace);

            $accumulator['effective_fg_pct'] += $this->effectiveFieldGoalPct($stat) * $weight;
            $accumulator['free_throw_rate'] += $this->freeThrowRate($stat) * $weight;
            $accumulator['turnover_rate'] += $this->turnoverRate($stat, $possessions) * $weight;
            $accumulator['offensive_rebound_rate'] += $this->offensiveReboundRate($stat, $opponentStat) * $weight;
            $accumulator['effective_fg_pct_allowed'] += $this->effectiveFieldGoalPct($opponentStat) * $weight;
            $accumulator['free_throw_rate_allowed'] += $this->freeThrowRate($opponentStat) * $weight;
            $accumulator['turnover_force_rate'] += $this->turnoverRate($opponentStat, $opponentPossessions) * $weight;
            $accumulator['offensive_rebound_rate_allowed'] += $this->offensiveReboundRate($opponentStat, $stat) * $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0) {
            return $this->emptyRecentStatProfile();
        }

        foreach ($accumulator as $key => $value) {
            $accumulator[$key] = round($value / $totalWeight, 4);
        }

        return $accumulator;
    }

    /**
     * @return array<string, float|null>
     */
    private function emptyRecentStatProfile(): array
    {
        return [
            'effective_fg_pct' => null,
            'free_throw_rate' => null,
            'turnover_rate' => null,
            'offensive_rebound_rate' => null,
            'effective_fg_pct_allowed' => null,
            'free_throw_rate_allowed' => null,
            'turnover_force_rate' => null,
            'offensive_rebound_rate_allowed' => null,
        ];
    }

    private function statPossessions(Model $stat, float $defaultPace): float
    {
        if (is_numeric($stat->possessions) && (float) $stat->possessions > 0) {
            return (float) $stat->possessions;
        }

        $coefficient = (float) (config("{$this->getSport()}.metrics.possession_coefficient") ?? 0.4);
        $estimated = (float) ($stat->field_goals_attempted ?? 0)
            - (float) ($stat->offensive_rebounds ?? 0)
            + (float) ($stat->turnovers ?? 0)
            + ($coefficient * (float) ($stat->free_throws_attempted ?? 0));

        return $estimated > 0 ? $estimated : $defaultPace;
    }

    private function effectiveFieldGoalPct(Model $stat): float
    {
        $attempts = max(1.0, (float) ($stat->field_goals_attempted ?? 0));

        return (((float) ($stat->field_goals_made ?? 0)) + (0.5 * (float) ($stat->three_point_made ?? 0))) / $attempts;
    }

    private function freeThrowRate(Model $stat): float
    {
        $attempts = max(1.0, (float) ($stat->field_goals_attempted ?? 0));

        return (float) ($stat->free_throws_made ?? 0) / $attempts;
    }

    private function turnoverRate(Model $stat, float $possessions): float
    {
        return (float) ($stat->turnovers ?? 0) / max(1.0, $possessions);
    }

    private function offensiveReboundRate(Model $stat, Model $opponentStat): float
    {
        $offensiveRebounds = (float) ($stat->offensive_rebounds ?? 0);
        $opponentDefensiveRebounds = (float) ($opponentStat->defensive_rebounds ?? 0);
        $opportunities = $offensiveRebounds + $opponentDefensiveRebounds;

        return $opportunities > 0 ? ($offensiveRebounds / $opportunities) : 0.0;
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

    /**
     * @return array{0:float,1:array<string,mixed>}
     */
    private function applyTrueEpaSpreadBlend(float $legacySpread, ?Model $homeMetrics, ?Model $awayMetrics): array
    {
        return $this->applyTrueEpaSpreadBlendForSport(
            $this->getSport(),
            $legacySpread,
            $homeMetrics,
            $awayMetrics,
            15.0
        );
    }

    /**
     * @return array{0:float,1:array<string,mixed>}
     */
    private function applyTrueEpaTotalBlend(float $legacyTotal, ?Model $homeMetrics, ?Model $awayMetrics): array
    {
        return $this->applyTrueEpaTotalBlendForSport(
            $this->getSport(),
            $legacyTotal,
            $homeMetrics,
            $awayMetrics,
            25.0,
            110.0,
            190.0
        );
    }

}
