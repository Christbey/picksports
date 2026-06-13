<?php

namespace App\Actions\MLB;

use App\Models\MLB\PlayoffForecast;
use App\Models\MLB\TeamMetric;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GeneratePlayoffForecast
{
    public function execute(int|string|null $season = null): Collection
    {
        $season = (int) ($season ?? config('mlb.season.default'));
        $config = (array) config('mlb.playoff_forecast', []);

        $teamPool = $this->buildTeamPool($season);
        if ($teamPool->isEmpty() && (($config['regression']['enabled'] ?? true) === true)) {
            $teamPool = $this->buildRegressedTeamPool($season, $config);
        }

        if ($teamPool->isEmpty()) {
            return collect();
        }

        $weights = (array) ($config['selection_weights'] ?? []);
        $teams = $this->attachSelectionScores($teamPool, $weights)
            ->sortByDesc('selection_score')
            ->values();

        $simulationRuns = max(1, (int) ($config['simulations'] ?? 1));
        $prepared = $this->simulatePlayoffField($teams, $season, $simulationRuns, $config);
        $payload = $prepared->map(fn (array $row): array => [
            'team_id' => $row['team_id'],
            'season' => $row['season'],
            'league' => $row['league'],
            'league_rank' => $row['league_rank'],
            'projected_seed' => $row['projected_seed'],
            'selection_score' => $row['selection_score'],
            'playoff_make_probability' => $row['playoff_make_probability'],
            'division_win_probability' => $row['division_win_probability'],
            'division_series_probability' => $row['division_series_probability'],
            'league_championship_series_probability' => $row['league_championship_series_probability'],
            'league_championship_probability' => $row['league_championship_probability'],
            'pennant_probability' => $row['pennant_probability'],
            'world_series_probability' => $row['world_series_probability'],
            'champion_probability' => $row['champion_probability'],
            'simulation_runs' => $simulationRuns,
            'created_at' => now(),
            'updated_at' => now(),
        ])->values()->all();

        PlayoffForecast::query()
            ->where('season', $season)
            ->whereNotIn('team_id', $prepared->pluck('team_id')->all())
            ->delete();

        PlayoffForecast::query()->upsert(
            $payload,
            ['team_id', 'season'],
            [
                'league',
                'league_rank',
                'projected_seed',
                'selection_score',
                'playoff_make_probability',
                'division_win_probability',
                'division_series_probability',
                'league_championship_series_probability',
                'league_championship_probability',
                'pennant_probability',
                'world_series_probability',
                'champion_probability',
                'simulation_runs',
                'updated_at',
            ]
        );

        return PlayoffForecast::query()
            ->with('team')
            ->where('season', $season)
            ->orderByDesc('champion_probability')
            ->orderByDesc('playoff_make_probability')
            ->get();
    }

    private function buildTeamPool(int $season): Collection
    {
        $metrics = TeamMetric::query()
            ->with('team')
            ->where('season', $season)
            ->where('season_type', (string) config('mlb.season.types.regular', 2))
            ->get();

        if ($metrics->isEmpty()) {
            return collect();
        }

        $records = $this->seasonRecords($season);

        $defaultElo = (float) config('mlb.elo.default_rating', 1500);

        return $metrics->map(function (TeamMetric $metric) use ($records, $defaultElo) {
            if (! $metric->team) {
                return null;
            }

            [$wins, $losses] = $this->recordForMetric($metric, $records);
            $games = $wins + $losses;

            return [
                'team_id' => (int) $metric->team_id,
                'league' => $this->leagueForMetric($metric),
                'division' => $this->divisionForMetric($metric),
                'win_pct' => $games > 0 ? $wins / $games : 0.5,
                'offensive_rating' => (float) ($metric->offensive_rating ?? 0),
                'pitching_rating' => (float) ($metric->pitching_rating ?? 0),
                'defensive_rating' => (float) ($metric->defensive_rating ?? 0),
                'strength_of_schedule' => (float) ($metric->strength_of_schedule ?? 0),
                'elo_rating' => (float) ($metric->team->elo_rating ?? $defaultElo),
                'selection_score' => 0.0,
                'power_rating' => 0.0,
            ];
        })->filter()->values();
    }

    private function buildRegressedTeamPool(int $targetSeason, array $config): Collection
    {
        $sourceSeason = TeamMetric::query()
            ->where('season', '<', $targetSeason)
            ->where('season_type', (string) config('mlb.season.types.regular', 2))
            ->max('season');

        if (! $sourceSeason) {
            return collect();
        }

        $metrics = TeamMetric::query()
            ->with('team')
            ->where('season', (int) $sourceSeason)
            ->where('season_type', (string) config('mlb.season.types.regular', 2))
            ->get();

        if ($metrics->isEmpty()) {
            return collect();
        }

        $records = $this->seasonRecords((int) $sourceSeason);

        $metricFactor = max(0.0, min(1.0, (float) ($config['regression']['metric_factor'] ?? 0.45)));
        $winPctFactor = max(0.0, min(1.0, (float) ($config['regression']['win_pct_factor'] ?? 0.45)));
        $sosFactor = max(0.0, min(1.0, (float) ($config['regression']['sos_factor'] ?? 0.35)));
        $eloFactor = max(
            0.0,
            min(1.0, (float) ($config['regression']['elo_factor'] ?? config('mlb.elo.team_regression_factor', 0.33)))
        );
        $defaultElo = (float) config('mlb.elo.default_rating', 1500);

        $avgOff = (float) $metrics->avg('offensive_rating');
        $avgPitch = (float) $metrics->avg('pitching_rating');
        $avgDef = (float) $metrics->avg('defensive_rating');
        $avgSos = (float) $metrics->avg('strength_of_schedule');

        return $metrics->map(function (TeamMetric $metric) use (
            $records,
            $defaultElo,
            $avgOff,
            $avgPitch,
            $avgDef,
            $avgSos,
            $metricFactor,
            $winPctFactor,
            $sosFactor,
            $eloFactor
        ) {
            if (! $metric->team) {
                return null;
            }

            [$wins, $losses] = $this->recordForMetric($metric, $records);
            $games = $wins + $losses;
            $prevWinPct = $games > 0 ? ($wins / $games) : 0.5;

            return [
                'team_id' => (int) $metric->team_id,
                'league' => $this->leagueForMetric($metric),
                'division' => $this->divisionForMetric($metric),
                'win_pct' => $this->regressToMean($prevWinPct, 0.5, $winPctFactor),
                'offensive_rating' => $this->regressToMean((float) ($metric->offensive_rating ?? $avgOff), $avgOff, $metricFactor),
                'pitching_rating' => $this->regressToMean((float) ($metric->pitching_rating ?? $avgPitch), $avgPitch, $metricFactor),
                'defensive_rating' => $this->regressToMean((float) ($metric->defensive_rating ?? $avgDef), $avgDef, $metricFactor),
                'strength_of_schedule' => $this->regressToMean((float) ($metric->strength_of_schedule ?? $avgSos), $avgSos, $sosFactor),
                'elo_rating' => $this->regressToMean((float) ($metric->team->elo_rating ?? $defaultElo), $defaultElo, $eloFactor),
                'selection_score' => 0.0,
                'power_rating' => 0.0,
            ];
        })->filter()->values();
    }

    private function seasonRecords(int $season): Collection
    {
        $regularSeasonType = (string) config('mlb.season.types.regular', 2);

        return collect(DB::select(
            "SELECT team_id,
                SUM(CASE WHEN team_runs > opponent_runs THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN team_runs < opponent_runs THEN 1 ELSE 0 END) AS losses
            FROM (
                SELECT stats.team_id AS team_id,
                    stats.runs AS team_runs,
                    opponent_stats.runs AS opponent_runs
                FROM mlb_team_stats stats
                INNER JOIN mlb_games games ON games.id = stats.game_id
                INNER JOIN mlb_team_stats opponent_stats
                    ON opponent_stats.game_id = stats.game_id
                    AND opponent_stats.team_id <> stats.team_id
                WHERE games.status = 'STATUS_FINAL'
                    AND games.season = ?
                    AND games.season_type = ?
                    AND stats.runs IS NOT NULL
                    AND opponent_stats.runs IS NOT NULL
            ) results
            GROUP BY team_id",
            [$season, $regularSeasonType]
        ))->keyBy('team_id');
    }

    private function recordForMetric(TeamMetric $metric, Collection $fallbackRecords): array
    {
        $metricWins = (int) ($metric->wins ?? 0);
        $metricLosses = (int) ($metric->losses ?? 0);

        if (($metricWins + $metricLosses) > 0) {
            return [$metricWins, $metricLosses];
        }

        $record = $fallbackRecords->get($metric->team_id);

        return [
            (int) ($record->wins ?? 0),
            (int) ($record->losses ?? 0),
        ];
    }

    private function leagueForMetric(TeamMetric $metric): string
    {
        $league = trim((string) ($metric->team->league ?? ''));
        if ($league !== '') {
            return $league;
        }

        $abbreviation = strtoupper(trim((string) ($metric->team->abbreviation ?? '')));
        $alignmentLeague = config("mlb.teams.alignment.{$abbreviation}.league");

        return is_string($alignmentLeague) && trim($alignmentLeague) !== ''
            ? trim($alignmentLeague)
            : 'Unknown';
    }

    private function divisionForMetric(TeamMetric $metric): string
    {
        $division = trim((string) ($metric->team->division ?? ''));
        if ($division !== '') {
            return $division;
        }

        $abbreviation = strtoupper(trim((string) ($metric->team->abbreviation ?? '')));
        $alignmentDivision = config("mlb.teams.alignment.{$abbreviation}.division");

        return is_string($alignmentDivision) && trim($alignmentDivision) !== ''
            ? trim($alignmentDivision)
            : 'Unknown';
    }

    private function simulatePlayoffField(Collection $teams, int $season, int $simulationRuns, array $config): Collection
    {
        $counts = $teams->mapWithKeys(fn (array $team): array => [
            $team['team_id'] => [
                'make' => 0,
                'division_win' => 0,
                'division_series' => 0,
                'lcs' => 0,
                'pennant' => 0,
                'champion' => 0,
                'seed_counts' => [],
            ],
        ])->all();

        $leagueRanks = [];
        foreach ($teams->groupBy(fn (array $team): string => $team['league']) as $leagueTeams) {
            foreach ($leagueTeams->sortByDesc('selection_score')->values() as $index => $team) {
                $leagueRanks[$team['team_id']] = $index + 1;
            }
        }

        $rngState = max(1, abs(crc32('mlb-playoff-forecast-'.$season)));
        $regularSeasonNoise = max(0.0, (float) ($config['simulation_regular_season_noise'] ?? 0.55));
        $matchupScale = max(0.05, (float) ($config['simulation_matchup_scale'] ?? 0.85));
        $playoffSpotsPerLeague = max(2, (int) ($config['playoff_spots_per_league'] ?? 6));

        for ($run = 0; $run < $simulationRuns; $run++) {
            $leagueChampions = [];

            foreach ($teams->groupBy(fn (array $team): string => $team['league']) as $leagueTeams) {
                $champion = $this->simulateLeagueBracket(
                    $leagueTeams->values(),
                    $counts,
                    $rngState,
                    $regularSeasonNoise,
                    $matchupScale,
                    $playoffSpotsPerLeague
                );

                if ($champion !== null) {
                    $leagueChampions[] = $champion;
                }
            }

            if (count($leagueChampions) === 1) {
                $counts[$leagueChampions[0]['team_id']]['champion']++;
            } elseif (count($leagueChampions) >= 2) {
                $winner = $this->seriesWinner($leagueChampions[0], $leagueChampions[1], $rngState, $matchupScale);
                $counts[$winner['team_id']]['champion']++;
            }
        }

        return $teams->map(function (array $team) use ($counts, $leagueRanks, $season, $simulationRuns): array {
            $teamCounts = $counts[$team['team_id']];
            $projectedSeed = $this->projectedSeed($teamCounts['seed_counts']);
            $pennantProbability = $teamCounts['pennant'] / $simulationRuns;

            return [
                'team_id' => $team['team_id'],
                'season' => $season,
                'league' => $team['league'],
                'league_rank' => (int) ($leagueRanks[$team['team_id']] ?? 0),
                'projected_seed' => $projectedSeed,
                'selection_score' => round($team['selection_score'], 4),
                'playoff_make_probability' => round($teamCounts['make'] / $simulationRuns, 5),
                'division_win_probability' => round($teamCounts['division_win'] / $simulationRuns, 5),
                'division_series_probability' => round($teamCounts['division_series'] / $simulationRuns, 5),
                'league_championship_series_probability' => round($teamCounts['lcs'] / $simulationRuns, 5),
                'league_championship_probability' => round($pennantProbability, 5),
                'pennant_probability' => round($pennantProbability, 5),
                'world_series_probability' => round($pennantProbability, 5),
                'champion_probability' => round($teamCounts['champion'] / $simulationRuns, 5),
            ];
        })->sortByDesc('champion_probability')->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $counts
     */
    private function simulateLeagueBracket(
        Collection $leagueTeams,
        array &$counts,
        int &$rngState,
        float $regularSeasonNoise,
        float $matchupScale,
        int $playoffSpotsPerLeague
    ): ?array {
        $ranked = $leagueTeams
            ->map(function (array $team) use (&$rngState, $regularSeasonNoise): array {
                $team['sim_score'] = (float) $team['selection_score'] + ($this->normal($rngState) * $regularSeasonNoise);

                return $team;
            })
            ->sortByDesc('sim_score')
            ->values();

        $divisionWinners = $ranked
            ->groupBy(fn (array $team): string => (string) $team['division'])
            ->map(fn (Collection $divisionTeams): array => $divisionTeams->sortByDesc('sim_score')->first())
            ->sortByDesc('sim_score')
            ->values()
            ->take((int) config('mlb.teams.divisions_per_league', 3));

        $divisionWinnerIds = $divisionWinners->pluck('team_id')->flip();
        $wildcards = $ranked
            ->reject(fn (array $team): bool => isset($divisionWinnerIds[$team['team_id']]))
            ->take(max(0, $playoffSpotsPerLeague - $divisionWinners->count()))
            ->values();

        $seeds = $divisionWinners->concat($wildcards)->values()->take($playoffSpotsPerLeague);

        if ($seeds->isEmpty()) {
            return null;
        }

        foreach ($seeds as $index => $team) {
            $teamId = $team['team_id'];
            $seed = $index + 1;
            $counts[$teamId]['make']++;
            $counts[$teamId]['seed_counts'][$seed] = ($counts[$teamId]['seed_counts'][$seed] ?? 0) + 1;

            if ($divisionWinnerIds->has($teamId)) {
                $counts[$teamId]['division_win']++;
            }
        }

        if ($seeds->count() < 3) {
            $champion = $seeds->first();
            $counts[$champion['team_id']]['division_series']++;
            $counts[$champion['team_id']]['lcs']++;
            $counts[$champion['team_id']]['pennant']++;

            return $champion;
        }

        $seeded = $seeds->mapWithKeys(fn (array $team, int $index): array => [$index + 1 => $team])->all();
        $divisionSeriesTeams = [];

        if (isset($seeded[1])) {
            $divisionSeriesTeams[] = $seeded[1];
            $counts[$seeded[1]['team_id']]['division_series']++;
        }
        if (isset($seeded[2])) {
            $divisionSeriesTeams[] = $seeded[2];
            $counts[$seeded[2]['team_id']]['division_series']++;
        }

        $wildCardWinnerA = isset($seeded[3], $seeded[6])
            ? $this->seriesWinner($seeded[3], $seeded[6], $rngState, $matchupScale)
            : ($seeded[3] ?? null);
        $wildCardWinnerB = isset($seeded[4], $seeded[5])
            ? $this->seriesWinner($seeded[4], $seeded[5], $rngState, $matchupScale)
            : ($seeded[4] ?? null);

        foreach ([$wildCardWinnerA, $wildCardWinnerB] as $winner) {
            if ($winner !== null) {
                $divisionSeriesTeams[] = $winner;
                $counts[$winner['team_id']]['division_series']++;
            }
        }

        $lcsTeams = [];
        if (isset($seeded[1], $wildCardWinnerB)) {
            $lcsTeams[] = $this->seriesWinner($seeded[1], $wildCardWinnerB, $rngState, $matchupScale);
        }
        if (isset($seeded[2], $wildCardWinnerA)) {
            $lcsTeams[] = $this->seriesWinner($seeded[2], $wildCardWinnerA, $rngState, $matchupScale);
        }

        if ($lcsTeams === [] && $divisionSeriesTeams !== []) {
            $lcsTeams = collect($divisionSeriesTeams)->sortByDesc('sim_score')->take(2)->values()->all();
        }

        foreach ($lcsTeams as $team) {
            $counts[$team['team_id']]['lcs']++;
        }

        $champion = count($lcsTeams) >= 2
            ? $this->seriesWinner($lcsTeams[0], $lcsTeams[1], $rngState, $matchupScale)
            : ($lcsTeams[0] ?? $seeds->first());

        $counts[$champion['team_id']]['pennant']++;

        return $champion;
    }

    private function seriesWinner(array $teamA, array $teamB, int &$rngState, float $matchupScale): array
    {
        $probabilityA = $this->logistic(((float) $teamA['selection_score'] - (float) $teamB['selection_score']) / $matchupScale);

        return $this->uniform($rngState) <= $probabilityA ? $teamA : $teamB;
    }

    /**
     * @param  array<int, int>  $seedCounts
     */
    private function projectedSeed(array $seedCounts): ?int
    {
        if ($seedCounts === []) {
            return null;
        }

        arsort($seedCounts);

        return (int) array_key_first($seedCounts);
    }

    private function uniform(int &$state): float
    {
        $state = (1103515245 * $state + 12345) & 0x7FFFFFFF;

        return max(1e-9, $state / 0x7FFFFFFF);
    }

    private function normal(int &$state): float
    {
        $u1 = $this->uniform($state);
        $u2 = $this->uniform($state);

        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }

    private function attachSelectionScores(Collection $teams, array $weights): Collection
    {
        $keys = [
            'offensive_rating',
            'pitching_rating',
            'defensive_rating',
            'elo_rating',
            'win_pct',
            'strength_of_schedule',
        ];
        $normalized = $this->normalizeWeights($weights, $keys);

        $zScores = [];
        foreach ($keys as $key) {
            $zScores[$key] = $this->zScores($teams->pluck($key, 'team_id')->all());
        }

        return $teams->map(function (array $team) use ($normalized, $zScores, $keys) {
            $teamId = $team['team_id'];
            $score = 0.0;
            foreach ($keys as $key) {
                $score += ($zScores[$key][$teamId] ?? 0.0) * ($normalized[$key] ?? 0.0);
            }

            $team['selection_score'] = $score;
            $team['power_rating'] = max(0.01, 1.0 + $score);

            return $team;
        });
    }

    /**
     * @param  array<string,float|int>  $weights
     * @param  array<int,string>  $keys
     * @return array<string,float>
     */
    private function normalizeWeights(array $weights, array $keys): array
    {
        $resolved = [];
        $sum = 0.0;
        foreach ($keys as $key) {
            $value = isset($weights[$key]) ? (float) $weights[$key] : 1.0;
            $value = max(0.0, $value);
            $resolved[$key] = $value;
            $sum += $value;
        }
        if ($sum <= 0.0) {
            $equal = 1.0 / max(1, count($keys));

            return array_fill_keys($keys, $equal);
        }
        foreach ($resolved as $key => $value) {
            $resolved[$key] = $value / $sum;
        }

        return $resolved;
    }

    /**
     * @param  array<int,float>  $valuesByTeam
     * @return array<int,float>
     */
    private function zScores(array $valuesByTeam): array
    {
        if ($valuesByTeam === []) {
            return [];
        }

        $values = array_values($valuesByTeam);
        $mean = array_sum($values) / count($values);
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $std = sqrt($variance / max(1, count($values)));
        if ($std < 1e-9) {
            return array_fill_keys(array_keys($valuesByTeam), 0.0);
        }

        $scores = [];
        foreach ($valuesByTeam as $teamId => $value) {
            $scores[(int) $teamId] = ($value - $mean) / $std;
        }

        return $scores;
    }

    private function logistic(float $x): float
    {
        return 1.0 / (1.0 + exp(-$x));
    }

    private function regressToMean(float $value, float $mean, float $factor): float
    {
        return $value + ($factor * ($mean - $value));
    }
}
