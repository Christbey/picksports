<?php

namespace App\Actions\NFL;

use App\Actions\Sports\Concerns\CalculatesGridironTeamMetrics;
use App\Actions\Sports\Concerns\CalculatesTeamTrueEpaFromPlays;
use App\Concerns\FiltersTeamGames;
use App\Models\NFL\Game;
use App\Models\NFL\Play;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use App\Services\MetricValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class CalculateTeamMetrics
{
    use CalculatesGridironTeamMetrics;
    use CalculatesTeamTrueEpaFromPlays;
    use FiltersTeamGames;

    /**
     * Fallback NFL division map for cases where synced conference/division fields are missing.
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
        'WSH' => ['conference' => 'nfc', 'division' => 'east'],
    ];

    public function execute(Team $team, int $season, int|string|null $seasonType = null): ?TeamMetric
    {
        $games = $this->getCompletedGamesForTeam($team, $season, 'NFL', $seasonType);
        $resolvedSeasonType = $this->resolveMetricSeasonType($games, $seasonType);

        if ($games->isEmpty()) {
            Log::info('No completed games found for team', [
                'team_id' => $team->id,
                'team_name' => "{$team->city} {$team->name}",
                'season' => $season,
                'season_type' => $resolvedSeasonType,
                'sport' => 'nfl',
            ]);

            return null;
        }

        extract($this->gatherTeamStatsFromGames($games, $team));

        if (empty($teamStats)) {
            return null;
        }

        if (count($teamStats) !== $games->count() || count($opponentStats) !== $games->count()) {
            Log::warning('Skipping NFL team metrics because completed game stats are incomplete', [
                'team_id' => $team->id,
                'team_name' => "{$team->city} {$team->name}",
                'season' => $season,
                'season_type' => $resolvedSeasonType,
                'completed_games' => $games->count(),
                'team_stat_games' => count($teamStats),
                'opponent_stat_games' => count($opponentStats),
            ]);

            return null;
        }

        $record = $this->calculateWinLossRecord($games, $team);

        // Base points and yardage metrics.
        $pointsScored = [];
        $pointsAllowed = [];

        foreach ($games as $game) {
            $isHome = (int) $game->home_team_id === (int) $team->id;
            $pointsScored[] = $isHome ? (float) ($game->home_score ?? 0) : (float) ($game->away_score ?? 0);
            $pointsAllowed[] = $isHome ? (float) ($game->away_score ?? 0) : (float) ($game->home_score ?? 0);
        }

        $pointsPerGame = $this->calculateAverage($pointsScored);
        $pointsAllowedPerGame = $this->calculateAverage($pointsAllowed);
        $offensiveRating = $pointsPerGame;
        $defensiveRating = $pointsAllowedPerGame;
        $netRating = $offensiveRating - $defensiveRating;

        $yardsPerGame = $this->calculateAverageYards($teamStats);
        $yardsAllowedPerGame = $this->calculateAverageYards($opponentStats);
        $passingYardsPerGame = $this->calculateAveragePassingYards($teamStats);
        $rushingYardsPerGame = $this->calculateAverageRushingYards($teamStats);
        $turnoverDifferential = $this->calculateTurnoverDifferential($teamStats, $opponentStats);
        $strengthOfSchedule = $this->calculateStrengthOfSchedule($opponentElos);
        $recentFormRating = $this->calculateRecentFormRating($games, $team);
        $injuryAdjustedTeamRating = $this->calculateInjuryAdjustedTeamRating($team, 'nfl', (float) ($team->elo_rating ?? 1500));
        $injuryAdjustedTotalAdjustment = $this->calculateInjuryAdjustedTotalAdjustment($team, 'nfl');
        $restTravelFatigue = $this->calculateRestTravelFatigue($games, $team);

        $opponentRanks = $this->buildOpponentRankMap();
        $gameContexts = $this->buildGameContexts($games, $team, $opponentRanks);

        $homeRating = $this->averageFromContexts($gameContexts->where('is_home', true), 'margin');
        $awayRating = $this->averageFromContexts($gameContexts->where('is_home', false), 'margin');
        $homeAdvantage = ($homeRating ?? 0.0) - ($awayRating ?? 0.0);

        $seasonSos = $strengthOfSchedule;
        $futureSos = $this->calculateFutureStrengthOfSchedule($team, $season);
        $sosBasic = $this->calculateBasicStrengthOfSchedule($gameContexts, $season);
        $inDivSos = $this->averageFromContexts($gameContexts->where('is_division_game', true), 'opponent_elo');
        $nonDivSos = $this->averageFromContexts($gameContexts->where('is_division_game', false), 'opponent_elo');

        $last5Rating = $this->recentWindowMargin($games, $team, 5);
        $last10Rating = $this->recentWindowMargin($games, $team, 10);
        $inDivRating = $this->averageFromContexts($gameContexts->where('is_division_game', true), 'margin');
        $nonDivRating = $this->averageFromContexts($gameContexts->where('is_division_game', false), 'margin');

        $luckRating = $this->calculateLuckRating($pointsScored, $pointsAllowed, $record['wins'], $record['losses']);
        $consistencyRating = $this->calculateConsistencyRating($gameContexts->pluck('margin')->all());

        $vs1To5Rating = $this->averageMarginForRankBucket($gameContexts, 1, 5);
        $vs6To10Rating = $this->averageMarginForRankBucket($gameContexts, 6, 10);
        $vs11To16Rating = $this->averageMarginForRankBucket($gameContexts, 11, 16);
        $vs17To22Rating = $this->averageMarginForRankBucket($gameContexts, 17, 22);
        $vs23To32Rating = $this->averageMarginForRankBucket($gameContexts, 23, 32);

        $firstHalfRating = $this->averageFromContexts($gameContexts, 'first_half_margin');
        $secondHalfRating = $this->averageFromContexts($gameContexts, 'second_half_margin');
        $trueEpaMetrics = $this->calculateTeamTrueEpaMetrics(Play::class, (int) $team->id, $games, true);

        $leagueAverageElo = (float) (Team::query()->avg('elo_rating') ?? 1500.0);
        $predictiveRating = $this->calculatePredictiveRating(
            netRating: $netRating,
            recentFormRating: $recentFormRating,
            turnoverDifferential: $turnoverDifferential,
            seasonSos: $seasonSos,
            leagueAverageElo: $leagueAverageElo
        );

        Log::info('Team metrics calculated', [
            'team_id' => $team->id,
            'team_name' => "{$team->city} {$team->name}",
            'season' => $season,
            'season_type' => $resolvedSeasonType,
            'sport' => 'nfl',
            'games_count' => $games->count(),
            'offensive_rating' => round($offensiveRating, 1),
            'defensive_rating' => round($defensiveRating, 1),
            'net_rating' => round($netRating, 1),
            'predictive_rating' => round($predictiveRating, 3),
        ]);

        $validator = new MetricValidator;
        $validator->validate([
            'offensive_rating' => $offensiveRating,
            'defensive_rating' => $defensiveRating,
            'net_rating' => $netRating,
            'yards_per_game' => $yardsPerGame,
            'turnover_differential' => $turnoverDifferential,
        ], 'nfl', [
            'team_id' => $team->id,
            'team_name' => "{$team->city} {$team->name}",
            'season' => $season,
            'season_type' => $resolvedSeasonType,
        ]);

        return TeamMetric::updateOrCreate(
            [
                'team_id' => $team->id,
                'season' => $season,
                'season_type' => $resolvedSeasonType,
            ],
            [
                'season_type' => $resolvedSeasonType,
                'wins' => $record['wins'],
                'losses' => $record['losses'],
                'offensive_rating' => round($offensiveRating, 1),
                'defensive_rating' => round($defensiveRating, 1),
                'net_rating' => round($netRating, 1),
                'points_per_game' => round($pointsPerGame, 1),
                'points_allowed_per_game' => round($pointsAllowedPerGame, 1),
                'yards_per_game' => round($yardsPerGame, 1),
                'yards_allowed_per_game' => round($yardsAllowedPerGame, 1),
                'passing_yards_per_game' => round($passingYardsPerGame, 1),
                'rushing_yards_per_game' => round($rushingYardsPerGame, 1),
                'turnover_differential' => round($turnoverDifferential, 1),
                'strength_of_schedule' => $this->roundOrNull($strengthOfSchedule, 3),
                'recent_form_rating' => $this->roundOrNull($recentFormRating, 3),
                'injury_adjusted_team_rating' => $this->roundOrNull($injuryAdjustedTeamRating, 3),
                'injury_total_adjustment' => $this->roundOrNull($injuryAdjustedTotalAdjustment, 3),
                'rest_travel_fatigue' => $this->roundOrNull($restTravelFatigue, 3),
                'predictive_rating' => round($predictiveRating, 3),
                'home_rating' => $this->roundOrNull($homeRating, 3),
                'away_rating' => $this->roundOrNull($awayRating, 3),
                'home_advantage_rating' => round($homeAdvantage, 3),
                'future_strength_of_schedule' => $this->roundOrNull($futureSos, 3),
                'season_strength_of_schedule' => $this->roundOrNull($seasonSos, 3),
                'strength_of_schedule_basic' => $this->roundOrNull($sosBasic, 3),
                'in_division_strength_of_schedule' => $this->roundOrNull($inDivSos, 3),
                'non_division_strength_of_schedule' => $this->roundOrNull($nonDivSos, 3),
                'last_5_rating' => $this->roundOrNull($last5Rating, 3),
                'last_10_rating' => $this->roundOrNull($last10Rating, 3),
                'in_division_rating' => $this->roundOrNull($inDivRating, 3),
                'non_division_rating' => $this->roundOrNull($nonDivRating, 3),
                'luck_rating' => $this->roundOrNull($luckRating, 3),
                'consistency_rating' => $this->roundOrNull($consistencyRating, 3),
                'vs_1_to_5_rating' => $this->roundOrNull($vs1To5Rating, 3),
                'vs_6_to_10_rating' => $this->roundOrNull($vs6To10Rating, 3),
                'vs_11_to_16_rating' => $this->roundOrNull($vs11To16Rating, 3),
                'vs_17_to_22_rating' => $this->roundOrNull($vs17To22Rating, 3),
                'vs_23_to_32_rating' => $this->roundOrNull($vs23To32Rating, 3),
                'first_half_rating' => $this->roundOrNull($firstHalfRating, 3),
                'second_half_rating' => $this->roundOrNull($secondHalfRating, 3),
                'offensive_true_epa_per_play' => $this->roundOrNull($trueEpaMetrics['offensive_true_epa_per_play'], 3),
                'defensive_true_epa_per_play' => $this->roundOrNull($trueEpaMetrics['defensive_true_epa_per_play'], 3),
                'net_true_epa_per_play' => $this->roundOrNull($trueEpaMetrics['net_true_epa_per_play'], 3),
                'calculation_date' => now()->toDateString(),
            ]
        );
    }

    public function executeForAllTeams(int $season, int|string|null $seasonType = null): int
    {
        $teams = Team::all();
        $calculated = 0;

        foreach ($teams as $team) {
            $metric = $this->execute($team, $season, $seasonType);
            if ($metric) {
                $calculated++;
            }
        }

        return $calculated;
    }

    private function resolveMetricSeasonType(Collection $games, int|string|null $seasonType): string
    {
        if ($seasonType !== null && $seasonType !== '') {
            return (string) collect($this->resolveSeasonTypeCandidates('nfl', $seasonType))
                ->first(fn ($candidate) => is_numeric($candidate) || ctype_digit((string) $candidate), (string) $seasonType);
        }

        $resolved = $games
            ->pluck('season_type')
            ->filter(fn ($type) => $type !== null && $type !== '')
            ->map(fn ($type) => (string) $type)
            ->unique()
            ->values();

        return $resolved->count() === 1
            ? (string) $resolved->first()
            : (string) config('nfl.season.types.regular', 2);
    }

    protected function calculatePredictiveRating(
        float $netRating,
        ?float $recentFormRating,
        float $turnoverDifferential,
        ?float $seasonSos,
        float $leagueAverageElo
    ): float {
        $sosAdjustment = $seasonSos === null
            ? 0.0
            : (($seasonSos - $leagueAverageElo) / 25.0);

        return ($netRating * 0.65)
            + (($recentFormRating ?? 0.0) * 0.25)
            + ($turnoverDifferential * 0.75)
            + $sosAdjustment;
    }

    /**
     * @return array<int,int>
     */
    protected function buildOpponentRankMap(): array
    {
        $teams = Team::query()
            ->select(['id', 'elo_rating'])
            ->orderByDesc('elo_rating')
            ->orderBy('id')
            ->get();

        $ranks = [];
        foreach ($teams as $index => $rankedTeam) {
            $ranks[(int) $rankedTeam->id] = $index + 1;
        }

        return $ranks;
    }

    /**
     * @param  array<int,int>  $opponentRanks
     */
    protected function buildGameContexts(Collection $games, Team $team, array $opponentRanks): Collection
    {
        $perGameElos = $this->loadPerGameEloRatings($games, $team);
        $teamDivisionKey = $this->resolveTeamDivisionKey($team);

        return $games->map(function (Game $game) use ($team, $teamDivisionKey, $opponentRanks, $perGameElos) {
            $isHome = (int) $game->home_team_id === (int) $team->id;
            $opponent = $isHome ? $game->awayTeam : $game->homeTeam;
            $opponentId = (int) ($isHome ? $game->away_team_id : $game->home_team_id);
            $eloKey = $game->id.'-'.$opponentId;

            $teamScore = (float) ($isHome ? ($game->home_score ?? 0) : ($game->away_score ?? 0));
            $opponentScore = (float) ($isHome ? ($game->away_score ?? 0) : ($game->home_score ?? 0));
            $margin = $teamScore - $opponentScore;

            [$firstHalfMargin, $secondHalfMargin] = $this->halfMarginsForGame($game, $isHome);

            $opponentDivisionKey = $this->resolveTeamDivisionKey($opponent);
            $isDivisionGame = $teamDivisionKey !== null
                && $opponentDivisionKey !== null
                && $teamDivisionKey === $opponentDivisionKey;

            return [
                'margin' => $margin,
                'is_home' => $isHome,
                'is_division_game' => $isDivisionGame,
                'opponent_id' => $opponentId,
                'opponent_rank' => $opponentRanks[$opponentId] ?? null,
                'opponent_elo' => $perGameElos[$eloKey] ?? ($opponent?->elo_rating !== null ? (float) $opponent->elo_rating : null),
                'first_half_margin' => $firstHalfMargin,
                'second_half_margin' => $secondHalfMargin,
            ];
        });
    }

    protected function normalizeDivision(?string $division): ?string
    {
        $normalized = strtolower(trim((string) $division));

        return $normalized === '' ? null : $normalized;
    }

    protected function normalizeConference(?string $conference): ?string
    {
        $normalized = strtolower(trim((string) $conference));
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, 'afc')) {
            return 'afc';
        }
        if (str_contains($normalized, 'nfc')) {
            return 'nfc';
        }

        return $normalized;
    }

    protected function canonicalDivisionValue(?string $division): ?string
    {
        $normalized = $this->normalizeDivision($division);
        if ($normalized === null) {
            return null;
        }

        if (str_contains($normalized, 'east')) {
            return 'east';
        }
        if (str_contains($normalized, 'west')) {
            return 'west';
        }
        if (str_contains($normalized, 'north')) {
            return 'north';
        }
        if (str_contains($normalized, 'south')) {
            return 'south';
        }

        return $normalized;
    }

    protected function resolveTeamDivisionKey(?Team $team): ?string
    {
        if (! $team) {
            return null;
        }

        $conference = $this->normalizeConference($team->conference);
        $division = $this->canonicalDivisionValue($team->division);

        $abbr = strtoupper((string) ($team->abbreviation ?? ''));
        $fallback = self::NFL_DIVISION_MAP[$abbr] ?? null;
        if ($conference === null) {
            $conference = $fallback['conference'] ?? null;
        }
        if ($division === null) {
            $division = $fallback['division'] ?? null;
        }

        if ($conference === null || $division === null) {
            return null;
        }

        return $conference.'-'.$division;
    }

    protected function averageFromContexts(Collection $contexts, string $key): ?float
    {
        $values = $contexts
            ->pluck($key)
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => (float) $value)
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        return $values->avg();
    }

    protected function recentWindowMargin(Collection $games, Team $team, int $window): ?float
    {
        if ($games->isEmpty()) {
            return null;
        }

        $recent = $games
            ->sortByDesc('game_date')
            ->take(max(1, $window));

        $margins = $recent->map(function (Game $game) use ($team) {
            $isHome = (int) $game->home_team_id === (int) $team->id;
            $teamScore = (float) ($isHome ? ($game->home_score ?? 0) : ($game->away_score ?? 0));
            $oppScore = (float) ($isHome ? ($game->away_score ?? 0) : ($game->home_score ?? 0));

            return $teamScore - $oppScore;
        });

        return $margins->avg();
    }

    protected function calculateFutureStrengthOfSchedule(Team $team, int $season): ?float
    {
        $upcoming = Game::query()
            ->where('season', $season)
            ->where(function ($query) use ($team) {
                $query->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->where('status', '!=', config('nfl.statuses.final'))
            ->whereDate('game_date', '>=', now()->toDateString())
            ->with(['homeTeam:id,elo_rating', 'awayTeam:id,elo_rating'])
            ->get();

        if ($upcoming->isEmpty()) {
            return null;
        }

        $opponentElos = $upcoming->map(function (Game $game) use ($team) {
            $isHome = (int) $game->home_team_id === (int) $team->id;
            $opponent = $isHome ? $game->awayTeam : $game->homeTeam;

            return $opponent?->elo_rating !== null ? (float) $opponent->elo_rating : null;
        })->filter(fn ($elo) => $elo !== null)->values();

        if ($opponentElos->isEmpty()) {
            return null;
        }

        return $opponentElos->avg();
    }

    protected function calculateBasicStrengthOfSchedule(Collection $gameContexts, int $season): ?float
    {
        $opponentIds = $gameContexts
            ->pluck('opponent_id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($opponentIds->isEmpty()) {
            return null;
        }

        $winPctByTeam = $this->winPctForTeams($opponentIds->all(), $season);

        if ($winPctByTeam === []) {
            return null;
        }

        $values = $opponentIds
            ->map(fn (int $id) => $winPctByTeam[$id] ?? null)
            ->filter(fn ($v) => $v !== null)
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        return $values->avg();
    }

    /**
     * @param  array<int,int>  $teamIds
     * @return array<int,float>
     */
    protected function winPctForTeams(array $teamIds, int $season): array
    {
        $rows = Game::query()
            ->select(['home_team_id', 'away_team_id', 'home_score', 'away_score'])
            ->where('season', $season)
            ->where('status', config('nfl.statuses.final'))
            ->where(function ($query) use ($teamIds) {
                $query->whereIn('home_team_id', $teamIds)
                    ->orWhereIn('away_team_id', $teamIds);
            })
            ->get();

        $wins = array_fill_keys($teamIds, 0);
        $losses = array_fill_keys($teamIds, 0);

        foreach ($rows as $row) {
            $homeId = (int) $row->home_team_id;
            $awayId = (int) $row->away_team_id;
            $homeScore = (int) ($row->home_score ?? 0);
            $awayScore = (int) ($row->away_score ?? 0);

            if ($homeScore === $awayScore) {
                continue;
            }

            if ($homeScore > $awayScore) {
                if (isset($wins[$homeId])) {
                    $wins[$homeId]++;
                }
                if (isset($losses[$awayId])) {
                    $losses[$awayId]++;
                }

                continue;
            }

            if (isset($wins[$awayId])) {
                $wins[$awayId]++;
            }
            if (isset($losses[$homeId])) {
                $losses[$homeId]++;
            }
        }

        $pct = [];
        foreach ($teamIds as $teamId) {
            $games = ($wins[$teamId] ?? 0) + ($losses[$teamId] ?? 0);
            if ($games > 0) {
                $pct[$teamId] = ($wins[$teamId] ?? 0) / $games;
            }
        }

        return $pct;
    }

    protected function calculateLuckRating(array $pointsScored, array $pointsAllowed, int $wins, int $losses): ?float
    {
        $games = $wins + $losses;
        if ($games <= 0) {
            return null;
        }

        $pf = array_sum($pointsScored);
        $pa = array_sum($pointsAllowed);
        if ($pf <= 0 && $pa <= 0) {
            return 0.0;
        }

        $exponent = 2.37;
        $expectedWinPct = pow(max(1.0, (float) $pf), $exponent)
            / (pow(max(1.0, (float) $pf), $exponent) + pow(max(1.0, (float) $pa), $exponent));

        $actualWinPct = $wins / $games;

        return ($actualWinPct - $expectedWinPct) * 100.0;
    }

    /**
     * @param  array<int,float|int>  $margins
     */
    protected function calculateConsistencyRating(array $margins): ?float
    {
        if ($margins === []) {
            return null;
        }

        $count = count($margins);
        if ($count === 1) {
            return 100.0;
        }

        $avg = array_sum($margins) / $count;
        $variance = 0.0;
        foreach ($margins as $margin) {
            $variance += pow(((float) $margin) - $avg, 2);
        }

        $stdDev = sqrt($variance / $count);

        return max(0.0, min(100.0, 100.0 - ($stdDev * 4.0)));
    }

    protected function averageMarginForRankBucket(Collection $contexts, int $minRank, int $maxRank): ?float
    {
        $bucketMargins = $contexts
            ->filter(function (array $context) use ($minRank, $maxRank) {
                $rank = $context['opponent_rank'] ?? null;
                if (! is_int($rank)) {
                    return false;
                }

                return $rank >= $minRank && $rank <= $maxRank;
            })
            ->pluck('margin');

        if ($bucketMargins->isEmpty()) {
            return null;
        }

        return $bucketMargins->avg();
    }

    /**
     * @return array{0:?float,1:?float}
     */
    protected function halfMarginsForGame(Game $game, bool $isHome): array
    {
        $homeLines = is_array($game->home_linescores) ? $game->home_linescores : [];
        $awayLines = is_array($game->away_linescores) ? $game->away_linescores : [];

        if ($homeLines === [] || $awayLines === []) {
            return [null, null];
        }

        $firstHalfPeriods = [1, 2];
        $secondHalfPeriods = [3, 4, 5, 6];

        $homeFirstHalf = $this->sumLinescorePeriods($homeLines, $firstHalfPeriods);
        $awayFirstHalf = $this->sumLinescorePeriods($awayLines, $firstHalfPeriods);
        $homeSecondHalf = $this->sumLinescorePeriods($homeLines, $secondHalfPeriods);
        $awaySecondHalf = $this->sumLinescorePeriods($awayLines, $secondHalfPeriods);

        if ($homeFirstHalf === null || $awayFirstHalf === null || $homeSecondHalf === null || $awaySecondHalf === null) {
            return [null, null];
        }

        if ($isHome) {
            return [
                (float) ($homeFirstHalf - $awayFirstHalf),
                (float) ($homeSecondHalf - $awaySecondHalf),
            ];
        }

        return [
            (float) ($awayFirstHalf - $homeFirstHalf),
            (float) ($awaySecondHalf - $homeSecondHalf),
        ];
    }

    /**
     * @param  array<int,mixed>  $linescores
     * @param  array<int,int>  $periods
     */
    protected function sumLinescorePeriods(array $linescores, array $periods): ?int
    {
        $total = 0;
        $hasAny = false;

        foreach ($linescores as $index => $entry) {
            $period = null;
            $value = null;

            if (is_array($entry)) {
                $period = isset($entry['period']) ? (int) $entry['period'] : ($index + 1);
                $value = $entry['value'] ?? null;
            } elseif (is_numeric($entry)) {
                $period = $index + 1;
                $value = $entry;
            }

            if ($period === null || ! in_array($period, $periods, true)) {
                continue;
            }

            if (! is_numeric($value)) {
                continue;
            }

            $total += (int) $value;
            $hasAny = true;
        }

        return $hasAny ? $total : null;
    }
}
