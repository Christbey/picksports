<?php

namespace App\Actions\NBA;

use App\Models\NBA\PlayoffForecast;
use App\Models\NBA\TeamMetric;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GeneratePlayoffForecast
{
    public function execute(int|string|null $season = null): Collection
    {
        $season = (int) ($season ?? config('nba.season.default'));
        $config = (array) config('nba.playoff_forecast', []);

        $teamPool = $this->buildTeamPool($season);
        if ($teamPool->isEmpty()) {
            return collect();
        }

        $weights = (array) ($config['selection_weights'] ?? []);
        $teams = $this->attachSelectionScores($teamPool, $weights)
            ->sortByDesc('selection_score')
            ->values();

        $steepness = max(0.2, (float) ($config['bubble_steepness'] ?? 1.2));
        $finalsSeedPenalty = max(0.0, (float) ($config['finals_seed_penalty'] ?? 0.06));
        $conferenceFinalsBase = max(0.05, min(0.95, (float) ($config['conference_finals_base'] ?? 0.42)));
        $simulationRuns = max(1, (int) ($config['simulations'] ?? 1));

        $byConference = $teams->groupBy(fn (array $team) => $team['conference']);
        $prepared = collect();

        foreach ($byConference as $conference => $conferenceTeams) {
            $ranked = $conferenceTeams->sortByDesc('selection_score')->values();
            $powerSum = max(1e-6, (float) $ranked->sum('power_rating'));

            foreach ($ranked as $index => $team) {
                $rank = $index + 1;
                $playoffMake = $this->logistic((8.5 - $rank) / $steepness);
                $projectedSeed = $playoffMake >= 0.5 ? $this->clampInt($rank, 1, 8) : null;

                $powerShare = max(0.0, (float) $team['power_rating']) / $powerSum;
                $conferenceFinals = $playoffMake * max(0.02, ($conferenceFinalsBase - (($rank - 1) * $finalsSeedPenalty)));
                $conferenceChamp = $playoffMake * $powerShare;
                $nbaFinals = $conferenceChamp;

                $prepared->push([
                    'team_id' => $team['team_id'],
                    'season' => $season,
                    'conference' => $conference,
                    'conference_rank' => $rank,
                    'projected_seed' => $projectedSeed,
                    'selection_score' => round($team['selection_score'], 4),
                    'playoff_make_probability' => round($playoffMake, 5),
                    'conference_finals_probability' => round($conferenceFinals, 5),
                    'nba_finals_probability' => round($nbaFinals, 5),
                    'champion_raw' => max(0.0, $nbaFinals * (0.65 + (0.35 * $powerShare))),
                    'simulation_runs' => $simulationRuns,
                ]);
            }
        }

        $champRawSum = max(1e-9, (float) $prepared->sum('champion_raw'));
        $payload = $prepared->map(function (array $row) use ($champRawSum) {
            return [
                'team_id' => $row['team_id'],
                'season' => $row['season'],
                'conference' => $row['conference'],
                'conference_rank' => $row['conference_rank'],
                'projected_seed' => $row['projected_seed'],
                'selection_score' => $row['selection_score'],
                'playoff_make_probability' => $row['playoff_make_probability'],
                'conference_finals_probability' => $row['conference_finals_probability'],
                'nba_finals_probability' => $row['nba_finals_probability'],
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
                'conference',
                'conference_rank',
                'projected_seed',
                'selection_score',
                'playoff_make_probability',
                'conference_finals_probability',
                'nba_finals_probability',
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
            ->get();

        if ($metrics->isEmpty()) {
            return collect();
        }

        $records = collect(DB::select(
            "SELECT team_id,
                SUM(CASE WHEN won = 1 THEN 1 ELSE 0 END) AS wins,
                SUM(CASE WHEN won = 0 THEN 1 ELSE 0 END) AS losses
            FROM (
                SELECT home_team_id AS team_id, CASE WHEN home_score > away_score THEN 1 ELSE 0 END AS won
                FROM nba_games
                WHERE status = 'STATUS_FINAL' AND season = ?
                UNION ALL
                SELECT away_team_id AS team_id, CASE WHEN away_score > home_score THEN 1 ELSE 0 END AS won
                FROM nba_games
                WHERE status = 'STATUS_FINAL' AND season = ?
            ) results
            GROUP BY team_id",
            [$season, $season]
        ))->keyBy('team_id');

        $defaultElo = (float) config('nba.elo.default', 1500);

        return $metrics->map(function (TeamMetric $metric) use ($records, $defaultElo) {
            if (! $metric->team) {
                return null;
            }

            $record = $records->get($metric->team_id);
            $wins = (int) ($record->wins ?? 0);
            $losses = (int) ($record->losses ?? 0);
            $games = $wins + $losses;

            return [
                'team_id' => (int) $metric->team_id,
                'conference' => trim((string) ($metric->team->conference ?? '')) ?: 'Unknown',
                'win_pct' => $games > 0 ? $wins / $games : 0.5,
                'net_rating' => (float) ($metric->net_rating ?? 0),
                'strength_of_schedule' => (float) ($metric->strength_of_schedule ?? 0),
                'elo_rating' => (float) ($metric->team->elo_rating ?? $defaultElo),
                'selection_score' => 0.0,
                'power_rating' => 0.0,
            ];
        })->filter()->values();
    }

    private function attachSelectionScores(Collection $teams, array $weights): Collection
    {
        $keys = ['net_rating', 'strength_of_schedule', 'elo_rating', 'win_pct'];
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
        $values = array_values($valuesByTeam);
        $count = count($values);
        if ($count === 0) {
            return [];
        }
        $mean = array_sum($values) / $count;
        $variance = array_sum(array_map(fn (float $value): float => ($value - $mean) ** 2, $values)) / max(1, $count);
        $std = sqrt(max($variance, 1e-9));

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
}

