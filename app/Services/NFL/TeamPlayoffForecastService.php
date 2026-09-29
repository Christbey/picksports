<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Carbon\Carbon;

class TeamPlayoffForecastService
{
    protected const FALLBACK_ALIGNMENT = [
        'ARI' => ['conference' => 'NFC', 'division' => 'West'],
        'ATL' => ['conference' => 'NFC', 'division' => 'South'],
        'BAL' => ['conference' => 'AFC', 'division' => 'North'],
        'BUF' => ['conference' => 'AFC', 'division' => 'East'],
        'CAR' => ['conference' => 'NFC', 'division' => 'South'],
        'CHI' => ['conference' => 'NFC', 'division' => 'North'],
        'CIN' => ['conference' => 'AFC', 'division' => 'North'],
        'CLE' => ['conference' => 'AFC', 'division' => 'North'],
        'DAL' => ['conference' => 'NFC', 'division' => 'East'],
        'DEN' => ['conference' => 'AFC', 'division' => 'West'],
        'DET' => ['conference' => 'NFC', 'division' => 'North'],
        'GB' => ['conference' => 'NFC', 'division' => 'North'],
        'HOU' => ['conference' => 'AFC', 'division' => 'South'],
        'IND' => ['conference' => 'AFC', 'division' => 'South'],
        'JAX' => ['conference' => 'AFC', 'division' => 'South'],
        'KC' => ['conference' => 'AFC', 'division' => 'West'],
        'LAC' => ['conference' => 'AFC', 'division' => 'West'],
        'LAR' => ['conference' => 'NFC', 'division' => 'West'],
        'LV' => ['conference' => 'AFC', 'division' => 'West'],
        'MIA' => ['conference' => 'AFC', 'division' => 'East'],
        'MIN' => ['conference' => 'NFC', 'division' => 'North'],
        'NE' => ['conference' => 'AFC', 'division' => 'East'],
        'NO' => ['conference' => 'NFC', 'division' => 'South'],
        'NYG' => ['conference' => 'NFC', 'division' => 'East'],
        'NYJ' => ['conference' => 'AFC', 'division' => 'East'],
        'PHI' => ['conference' => 'NFC', 'division' => 'East'],
        'PIT' => ['conference' => 'AFC', 'division' => 'North'],
        'SEA' => ['conference' => 'NFC', 'division' => 'West'],
        'SF' => ['conference' => 'NFC', 'division' => 'West'],
        'TB' => ['conference' => 'NFC', 'division' => 'South'],
        'TEN' => ['conference' => 'AFC', 'division' => 'South'],
        'WSH' => ['conference' => 'NFC', 'division' => 'East'],
    ];

    public function __construct(
        protected TeamFuturesProjectionService $teamFuturesProjectionService
    ) {}

    public static function conferenceForAbbreviation(string $abbreviation): ?string
    {
        return self::FALLBACK_ALIGNMENT[strtoupper(trim($abbreviation))]['conference'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function forecast(
        int $season,
        ?string $asOfDate = null,
        bool $requireHistoricalMetrics = false,
        ?int $simulations = null,
        ?int $seed = null
    ): array {
        $rows = $this->teamFuturesProjectionService->projections(
            season: $season,
            market: 'season_wins',
            asOfDate: $asOfDate !== null ? Carbon::parse($asOfDate) : null,
            requireHistoricalMetrics: $requireHistoricalMetrics || $asOfDate !== null,
            onlyWithOdds: false,
            sortBy: 'projected_total',
            direction: 'desc',
            limit: 64,
        );

        $teams = $this->buildTeamInputs($rows);
        $cutoff = $asOfDate !== null ? Carbon::parse($asOfDate) : now();
        $schedule = Game::query()->where('season', $season)
            ->where('season_type', (string) config('nfl.season.types.regular', 2))
            ->orderBy('id')->get();
        $teamIds = array_column($teams, 'team_id');
        $counts = array_fill_keys($teamIds, 0);
        $games = [];
        $warnings = [];
        foreach ($schedule as $game) {
            if (! isset($counts[$game->home_team_id], $counts[$game->away_team_id]) || $game->home_team_id === $game->away_team_id) {
                $warnings[] = 'Schedule contains teams without usable ratings.';

                continue;
            }
            $counts[$game->home_team_id]++;
            $counts[$game->away_team_id]++;
            $completedAt = $game->completed_at ?? $game->game_date?->copy()->endOfDay();
            $completed = $game->status === 'STATUS_FINAL' && ($asOfDate === null || ($completedAt !== null && $completedAt->lte($cutoff)));
            if ($completed && ($game->home_score === null || $game->away_score === null)) {
                $warnings[] = 'A completed game is missing its score.';
            }
            if (in_array($game->status, ['STATUS_CANCELED', 'STATUS_CANCELLED'], true)) {
                $warnings[] = 'The schedule includes a cancelled game.';
            }
            $games[] = [
                'home' => (int) $game->home_team_id, 'away' => (int) $game->away_team_id,
                'completed' => $completed, 'home_score' => $game->home_score, 'away_score' => $game->away_score,
                'neutral' => (bool) $game->neutral_site,
            ];
        }
        $alignmentCounts = array_count_values(array_map(fn (array $team): string => $team['conference'].' '.$team['division'], $teams));
        if (count($teams) !== 32 || count($alignmentCounts) !== 8 || count(array_filter($alignmentCounts, fn (int $count): bool => $count !== 4)) > 0 || count($games) !== 272 || count(array_filter($counts, fn (int $count): bool => $count !== 17)) > 0) {
            $warnings[] = 'A complete 32-team, 272-game regular-season schedule and team ratings are required.';
        }
        if ($warnings !== []) {
            return [
                'report_type' => 'nfl_team_playoff_forecast', 'season' => $season, 'as_of_date' => $asOfDate,
                'summary' => ['teams' => 0, 'simulations' => 0], 'teams' => [],
                'division_leaders' => [], 'conference_leaders' => [], 'super_bowl_leaders' => [],
                'warnings' => array_values(array_unique($warnings)),
            ];
        }
        $games = array_map(function (array $game) use ($teams): array {
            $home = $this->findTeamById($teams, $game['home']);
            $away = $this->findTeamById($teams, $game['away']);
            $game['home_probability'] = $this->gameProbability($home, $away, ! $game['neutral']);

            return $game;
        }, $games);

        $simulationCount = max(100, $simulations ?? (int) config('nfl.team_playoff_forecast.simulations', 5000));
        $randomSeed = $seed ?? (int) config('nfl.team_playoff_forecast.random_seed', 20260402);
        mt_srand($randomSeed);

        $teamResults = [];
        foreach ($teams as $team) {
            $teamResults[$team['team_id']] = [
                'team_id' => $team['team_id'],
                'team_name' => $team['team_name'],
                'conference' => $team['conference'],
                'division' => $team['division'],
                'projected_wins' => 0.0,
                'projected_seed_avg' => 0.0,
                'division_titles' => 0,
                'playoff_berths' => 0,
                'conference_titles' => 0,
                'super_bowl_titles' => 0,
            ];
        }

        for ($i = 0; $i < $simulationCount; $i++) {
            $sampled = $this->sampleSchedule($teams, $games);
            foreach ($sampled as $team) {
                $teamResults[$team['team_id']]['projected_wins'] += $team['sampled_wins'];
            }
            $standings = $this->simulateStandings($sampled);

            foreach ($standings['teams'] as $teamId => $result) {
                $teamResults[$teamId]['projected_seed_avg'] += $result['seed'] ?? 0;
                $teamResults[$teamId]['division_titles'] += $result['division_winner'] ? 1 : 0;
                $teamResults[$teamId]['playoff_berths'] += $result['made_playoffs'] ? 1 : 0;
            }

            foreach (($standings['conference_champions'] ?? []) as $conference => $teamId) {
                if (isset($teamResults[$teamId])) {
                    $teamResults[$teamId]['conference_titles']++;
                }
            }

            $superBowlChampion = $standings['super_bowl_champion'] ?? null;
            if ($superBowlChampion !== null && isset($teamResults[$superBowlChampion])) {
                $teamResults[$superBowlChampion]['super_bowl_titles']++;
            }
        }

        $teamsOut = array_map(function (array $result) use ($simulationCount): array {
            return [
                'team_id' => $result['team_id'],
                'team_name' => $result['team_name'],
                'conference' => $result['conference'],
                'division' => $result['division'],
                'projected_wins' => round($result['projected_wins'] / $simulationCount, 3),
                'projected_seed' => $result['playoff_berths'] > 0 ? round($result['projected_seed_avg'] / $result['playoff_berths'], 2) : null,
                'division_winner_probability' => round($result['division_titles'] / $simulationCount, 4),
                'make_playoffs_probability' => round($result['playoff_berths'] / $simulationCount, 4),
                'conference_champion_probability' => round($result['conference_titles'] / $simulationCount, 4),
                'super_bowl_champion_probability' => round($result['super_bowl_titles'] / $simulationCount, 4),
            ];
        }, $teamResults);

        usort($teamsOut, fn (array $a, array $b): int => $b['super_bowl_champion_probability'] <=> $a['super_bowl_champion_probability']);

        return [
            'report_type' => 'nfl_team_playoff_forecast',
            'season' => $season,
            'as_of_date' => $asOfDate,
            'require_historical_metrics' => $requireHistoricalMetrics,
            'warnings' => ['Uncalibrated model estimates. Equal-record tiebreakers are randomized; future ties and postseason results are not modeled.'],
            'generated_at' => now()->toIso8601String(),
            'model_version' => 'schedule_v2',
            'summary' => [
                'teams' => count($teamsOut),
                'simulations' => $simulationCount,
            ],
            'teams' => $teamsOut,
            'division_leaders' => $this->divisionLeaders($teamsOut),
            'conference_leaders' => $this->conferenceLeaders($teamsOut),
            'super_bowl_leaders' => array_slice($teamsOut, 0, 12),
        ];
    }

    /**
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<int, array<string,mixed>>
     */
    protected function buildTeamInputs(array $rows): array
    {
        $teamModels = Team::query()->whereIn('id', array_map(fn (array $row) => (int) $row['team_id'], $rows))
            ->get(['id', 'abbreviation', 'location', 'name', 'conference', 'division'])
            ->keyBy('id');

        $teams = [];
        foreach ($rows as $row) {
            $teamId = (int) ($row['team_id'] ?? 0);
            $teamModel = $teamModels->get($teamId);
            if ($teamId <= 0 || ! $teamModel) {
                continue;
            }

            $alignment = $this->resolveAlignment($teamModel);
            if ($alignment === null) {
                continue;
            }

            $teamName = trim(implode(' ', array_filter([$teamModel->location, $teamModel->name])));
            $rating = (float) data_get($row, 'projection_factors.predictive_rating', 0.0);
            if (abs($rating) > 200) {
                $rating = ($rating - 1500.0) / 25.0;
            }
            $played = (int) ($row['games_played'] ?? 0);
            $reliability = $played > 0 ? $played / ($played + max(1, (int) config('nfl.team_futures.prior_games', 4))) : 1.0;

            $teams[] = [
                'team_id' => $teamId,
                'team_name' => $teamName !== '' ? $teamName : (string) ($teamModel->abbreviation ?? $teamId),
                'abbreviation' => (string) ($teamModel->abbreviation ?? ''),
                'conference' => $alignment['conference'],
                'division' => $alignment['division'],
                'projected_total' => (float) ($row['projected_total'] ?? 0.0),
                'strength_rating' => ($rating / 8.0) * $reliability,
            ];
        }

        return $teams;
    }

    protected function resolveAlignment(Team $team): ?array
    {
        $conference = $team->conference ? strtoupper((string) $team->conference) : null;
        $division = $team->division ? ucfirst(strtolower((string) $team->division)) : null;

        if ($conference !== null && $division !== null) {
            return ['conference' => $conference, 'division' => $division];
        }

        $fallback = self::FALLBACK_ALIGNMENT[(string) $team->abbreviation] ?? null;
        if ($fallback === null) {
            return null;
        }

        return $fallback;
    }

    /**
     * @param  array<string,mixed>  $team
     * @return array<string,mixed>
     */
    protected function sampleSchedule(array $teams, array $games): array
    {
        $sampled = [];
        foreach ($teams as $team) {
            $sampled[$team['team_id']] = [...$team, 'sampled_wins' => 0, 'sampled_ties' => 0, 'tie_breaker' => mt_rand() / mt_getrandmax()];
        }
        foreach ($games as $game) {
            if ($game['completed']) {
                if ($game['home_score'] === $game['away_score']) {
                    $sampled[$game['home']]['sampled_ties']++;
                    $sampled[$game['away']]['sampled_ties']++;

                    continue;
                }
                $winner = $game['home_score'] > $game['away_score'] ? $game['home'] : $game['away'];
            } else {
                $winner = mt_rand() / mt_getrandmax() < $game['home_probability'] ? $game['home'] : $game['away'];
            }
            $sampled[$winner]['sampled_wins']++;
        }

        return array_values($sampled);
    }

    /**
     * @param  array<int, array<string,mixed>>  $teams
     * @return array<string,mixed>
     */
    protected function simulateStandings(array $teams): array
    {
        $results = [];
        $conferenceFields = [];

        foreach (['AFC', 'NFC'] as $conference) {
            $conferenceTeams = array_values(array_filter($teams, fn (array $team): bool => $team['conference'] === $conference));
            $divisionWinners = [];
            $playoffTeams = [];

            foreach (['East', 'North', 'South', 'West'] as $division) {
                $divisionTeams = array_values(array_filter($conferenceTeams, fn (array $team): bool => $team['division'] === $division));
                if ($divisionTeams === []) {
                    continue;
                }

                usort($divisionTeams, fn (array $a, array $b): int => $this->rankTeams($a, $b));
                $winner = $divisionTeams[0];
                $divisionWinners[] = $winner;
            }

            usort($divisionWinners, fn (array $a, array $b): int => $this->rankTeams($a, $b));
            foreach ($divisionWinners as $index => $team) {
                $seed = $index + 1;
                $results[$team['team_id']] = [
                    'seed' => $seed,
                    'made_playoffs' => true,
                    'division_winner' => true,
                ];
                $playoffTeams[] = ['seed' => $seed, ...$team];
            }

            $wildCardPool = array_values(array_filter(
                $conferenceTeams,
                fn (array $team): bool => ! isset($results[$team['team_id']])
            ));
            usort($wildCardPool, fn (array $a, array $b): int => $this->rankTeams($a, $b));
            $wildCards = array_slice($wildCardPool, 0, min(3, count($wildCardPool)));
            foreach ($wildCards as $index => $team) {
                $seed = count($divisionWinners) + $index + 1;
                $results[$team['team_id']] = [
                    'seed' => $seed,
                    'made_playoffs' => true,
                    'division_winner' => false,
                ];
                $playoffTeams[] = ['seed' => $seed, ...$team];
            }

            foreach ($conferenceTeams as $team) {
                $results[$team['team_id']] ??= [
                    'seed' => null,
                    'made_playoffs' => false,
                    'division_winner' => false,
                ];
            }

            $conferenceFields[$conference] = $playoffTeams;
        }

        $conferenceChampions = [];
        foreach ($conferenceFields as $conference => $field) {
            $champion = $this->simulateConferenceBracket($field);
            if ($champion !== null) {
                $conferenceChampions[$conference] = $champion['team_id'];
            }
        }

        $superBowlChampion = null;
        if (isset($conferenceChampions['AFC'], $conferenceChampions['NFC'])) {
            $afc = $this->findTeamById($teams, (int) $conferenceChampions['AFC']);
            $nfc = $this->findTeamById($teams, (int) $conferenceChampions['NFC']);
            if ($afc !== null && $nfc !== null) {
                $superBowlChampion = $this->simulateGame($afc, $nfc, false)['team_id'];
            }
        }

        return [
            'teams' => $results,
            'conference_champions' => $conferenceChampions,
            'super_bowl_champion' => $superBowlChampion,
        ];
    }

    /**
     * @param  array<int, array<string,mixed>>  $field
     */
    protected function simulateConferenceBracket(array $field): ?array
    {
        if ($field === []) {
            return null;
        }

        usort($field, fn (array $a, array $b): int => ($a['seed'] <=> $b['seed']));
        $bySeed = [];
        foreach ($field as $team) {
            $bySeed[(int) $team['seed']] = $team;
        }

        $remaining = $bySeed;

        $wildCardWinners = [];
        foreach ([[2, 7], [3, 6], [4, 5]] as [$homeSeed, $awaySeed]) {
            if (! isset($remaining[$homeSeed], $remaining[$awaySeed])) {
                continue;
            }
            $wildCardWinners[] = $this->simulateGame($remaining[$homeSeed], $remaining[$awaySeed], true, $homeSeed < $awaySeed);
        }

        if (! isset($remaining[1])) {
            return $wildCardWinners !== [] ? $wildCardWinners[0] : null;
        }

        usort($wildCardWinners, fn (array $a, array $b): int => ($b['seed'] <=> $a['seed']));
        $lowestRemaining = array_shift($wildCardWinners);
        if ($lowestRemaining === null) {
            return $remaining[1];
        }

        $divisionalA = $this->simulateGame($remaining[1], $lowestRemaining, true, true);
        $divisionalB = count($wildCardWinners) >= 2
            ? $this->simulateGame($wildCardWinners[0], $wildCardWinners[1], true, $wildCardWinners[0]['seed'] < $wildCardWinners[1]['seed'])
            : ($wildCardWinners[0] ?? null);

        if ($divisionalB === null) {
            return $divisionalA;
        }

        return $this->simulateGame($divisionalA, $divisionalB, true, $divisionalA['seed'] < $divisionalB['seed']);
    }

    /**
     * @param  array<string,mixed>  $teamA
     * @param  array<string,mixed>  $teamB
     * @return array<string,mixed>
     */
    protected function simulateGame(array $teamA, array $teamB, bool $useHomeField, bool $teamAHome = true): array
    {
        $probabilityA = $this->gameProbability($teamA, $teamB, $useHomeField, $teamAHome);

        return mt_rand() / mt_getrandmax() < $probabilityA ? $teamA : $teamB;
    }

    protected function gameProbability(array $teamA, array $teamB, bool $useHomeField, bool $teamAHome = true): float
    {
        $homeField = $useHomeField ? (float) config('nfl.team_playoff_forecast.playoff_home_field_advantage', 0.35) : 0.0;
        $ratingA = (float) $teamA['strength_rating'] + ($teamAHome ? $homeField : 0.0);
        $ratingB = (float) $teamB['strength_rating'] + (! $teamAHome ? $homeField : 0.0);
        $scale = max(0.1, (float) config('nfl.team_playoff_forecast.win_probability_scale', 1.6));

        return 1.0 / (1.0 + exp(-(($ratingA - $ratingB) / $scale)));
    }

    protected function rankTeams(array $left, array $right): int
    {
        $winsComparison = (($right['sampled_wins'] + 0.5 * $right['sampled_ties']) <=> ($left['sampled_wins'] + 0.5 * $left['sampled_ties']));
        if ($winsComparison !== 0) {
            return $winsComparison;
        }

        return $right['tie_breaker'] <=> $left['tie_breaker'];
    }

    /**
     * @param  array<int, array<string,mixed>>  $teams
     */
    protected function findTeamById(array $teams, int $teamId): ?array
    {
        foreach ($teams as $team) {
            if ((int) $team['team_id'] === $teamId) {
                return $team;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string,mixed>>  $teams
     * @return array<int, array<string,mixed>>
     */
    protected function divisionLeaders(array $teams): array
    {
        $leaders = [];
        foreach ($teams as $team) {
            $key = $team['conference'].' '.$team['division'];
            if (! isset($leaders[$key]) || $team['division_winner_probability'] > $leaders[$key]['division_winner_probability']) {
                $leaders[$key] = $team;
            }
        }

        ksort($leaders);

        return array_values($leaders);
    }

    /**
     * @param  array<int, array<string,mixed>>  $teams
     * @return array<int, array<string,mixed>>
     */
    protected function conferenceLeaders(array $teams): array
    {
        $leaders = [];
        foreach ($teams as $team) {
            $conference = $team['conference'];
            if (! isset($leaders[$conference]) || $team['conference_champion_probability'] > $leaders[$conference]['conference_champion_probability']) {
                $leaders[$conference] = $team;
            }
        }

        ksort($leaders);

        return array_values($leaders);
    }
}
