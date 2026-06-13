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

        $steepness = max(0.2, (float) ($config['bubble_steepness'] ?? 1.1));
        $playoffSpotsPerLeague = max(1, (int) ($config['playoff_spots_per_league'] ?? 6));
        $seedPenalty = max(0.0, (float) ($config['league_champ_seed_penalty'] ?? 0.07));
        $leagueChampBase = max(0.05, min(0.95, (float) ($config['league_championship_base'] ?? 0.44)));
        $simulationRuns = max(1, (int) ($config['simulations'] ?? 1));

        $byLeague = $teams->groupBy(fn (array $team) => $team['league']);
        $prepared = collect();

        foreach ($byLeague as $league => $leagueTeams) {
            $ranked = $leagueTeams->sortByDesc('selection_score')->values();
            $powerSum = max(1e-6, (float) $ranked->sum('power_rating'));

            foreach ($ranked as $index => $team) {
                $rank = $index + 1;
                $playoffMake = $this->logistic((($playoffSpotsPerLeague + 0.5) - $rank) / $steepness);
                $projectedSeed = $playoffMake >= 0.5 ? $this->clampInt($rank, 1, $playoffSpotsPerLeague) : null;

                $powerShare = max(0.0, (float) $team['power_rating']) / $powerSum;
                $leagueChampionship = $playoffMake * max(0.02, ($leagueChampBase - (($rank - 1) * $seedPenalty)));
                $worldSeries = $playoffMake * $powerShare;

                $prepared->push([
                    'team_id' => $team['team_id'],
                    'season' => $season,
                    'league' => $league,
                    'league_rank' => $rank,
                    'projected_seed' => $projectedSeed,
                    'selection_score' => round($team['selection_score'], 4),
                    'playoff_make_probability' => round($playoffMake, 5),
                    'league_championship_probability' => round($leagueChampionship, 5),
                    'world_series_probability' => round($worldSeries, 5),
                    'champion_raw' => max(0.0, $worldSeries * (0.65 + (0.35 * $powerShare))),
                    'simulation_runs' => $simulationRuns,
                ]);
            }
        }

        $champRawSum = max(1e-9, (float) $prepared->sum('champion_raw'));
        $payload = $prepared->map(function (array $row) use ($champRawSum) {
            return [
                'team_id' => $row['team_id'],
                'season' => $row['season'],
                'league' => $row['league'],
                'league_rank' => $row['league_rank'],
                'projected_seed' => $row['projected_seed'],
                'selection_score' => $row['selection_score'],
                'playoff_make_probability' => $row['playoff_make_probability'],
                'league_championship_probability' => $row['league_championship_probability'],
                'world_series_probability' => $row['world_series_probability'],
                'champion_probability' => round($row['champion_raw'] / $champRawSum, 5),
                'simulation_runs' => $row['simulation_runs'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->values()->all();

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
                'league_championship_probability',
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
                'league' => trim((string) ($metric->team->league ?? '')) ?: 'Unknown',
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
                'league' => trim((string) ($metric->team->league ?? '')) ?: 'Unknown',
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

    private function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private function regressToMean(float $value, float $mean, float $factor): float
    {
        return $value + ($factor * ($mean - $value));
    }
}
