<?php

namespace App\Actions\NBA;

use App\Models\NBA\PlayoffForecast;
use App\Models\NBA\Team;
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

        $playoffTeamsPerConference = $this->clampInt((int) ($config['playoff_teams_per_conference'] ?? 8), 1, 15);
        $playInTeamsPerConference = $this->clampInt((int) ($config['play_in_teams_per_conference'] ?? 10), $playoffTeamsPerConference, 15);
        $divisionWinnerBonus = max(0.0, (float) ($config['division_winner_bonus'] ?? 0.2));
        $rankNoiseStd = max(0.01, (float) ($config['rank_noise_std'] ?? 0.35));
        $finalsSeedPenalty = max(0.0, (float) ($config['finals_seed_penalty'] ?? 0.06));
        $conferenceFinalsBase = max(0.05, min(0.95, (float) ($config['conference_finals_base'] ?? 0.42)));
        $simulationRuns = max(25, (int) ($config['simulations'] ?? 500));

        $byConference = $teams->groupBy(fn (array $team) => $team['conference']);
        $prepared = collect();

        foreach ($byConference as $conference => $conferenceTeams) {
            $ranked = $conferenceTeams->sortByDesc('selection_score')->values();
            $divisionLeaders = $ranked
                ->groupBy(fn (array $team): string => $team['division'])
                ->map(fn (Collection $divisionTeams): int => (int) $divisionTeams->sortByDesc('selection_score')->first()['team_id'])
                ->flip();

            $conferenceTeamsWithDivisionBonus = $ranked
                ->map(function (array $team) use ($divisionLeaders, $divisionWinnerBonus): array {
                    $isDivisionLeader = $divisionLeaders->has((int) $team['team_id']);
                    $team['is_division_leader'] = $isDivisionLeader;
                    $team['adjusted_selection_score'] = $team['selection_score'] + ($isDivisionLeader ? $divisionWinnerBonus : 0.0);

                    return $team;
                })
                ->sortByDesc('adjusted_selection_score')
                ->values();

            $simulated = $this->simulateConferenceRankings(
                $conferenceTeamsWithDivisionBonus,
                $simulationRuns,
                $rankNoiseStd,
                $playoffTeamsPerConference,
                $playInTeamsPerConference
            );

            $powerSum = max(1e-6, (float) $conferenceTeamsWithDivisionBonus->sum('power_rating'));

            foreach ($conferenceTeamsWithDivisionBonus as $team) {
                $teamId = (int) $team['team_id'];
                $sim = $simulated[$teamId] ?? null;
                if (! is_array($sim)) {
                    continue;
                }

                $rank = (int) round((float) $sim['avg_rank']);
                $playoffMake = (float) $sim['playoff_make_probability'];
                $projectedSeed = $playoffMake >= 0.5 ? $this->clampInt($rank, 1, $playoffTeamsPerConference) : null;

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
                    'selection_score' => round((float) $team['adjusted_selection_score'], 4),
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
            ['conference' => $conference, 'division' => $division] = $this->resolveTeamAlignment($metric->team);

            if (
                (string) ($metric->team->conference ?? '') !== $conference
                || (string) ($metric->team->division ?? '') !== $division
            ) {
                Team::query()
                    ->whereKey($metric->team->id)
                    ->update([
                        'conference' => $conference,
                        'division' => $division,
                    ]);
            }

            return [
                'team_id' => (int) $metric->team_id,
                'conference' => $conference,
                'division' => $division,
                'abbreviation' => strtoupper(trim((string) ($metric->team->abbreviation ?? ''))),
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

    /**
     * @param  Collection<int, array<string, mixed>>  $teams
     * @return array<int, array{avg_rank:float,playoff_make_probability:float,play_in_probability:float}>
     */
    private function simulateConferenceRankings(
        Collection $teams,
        int $runs,
        float $noiseStd,
        int $playoffSlots,
        int $playInSlots
    ): array {
        $rankSums = [];
        $playoffHits = [];
        $playInHits = [];

        for ($run = 0; $run < $runs; $run++) {
            $ranked = $teams
                ->map(function (array $team) use ($noiseStd): array {
                    $team['sim_score'] = (float) $team['adjusted_selection_score'] + $this->randomNormal(0.0, $noiseStd);
                    return $team;
                })
                ->sortByDesc('sim_score')
                ->values();

            foreach ($ranked as $index => $team) {
                $teamId = (int) $team['team_id'];
                $rank = $index + 1;

                $rankSums[$teamId] = ($rankSums[$teamId] ?? 0.0) + $rank;
                if ($rank <= $playoffSlots) {
                    $playoffHits[$teamId] = ($playoffHits[$teamId] ?? 0) + 1;
                }
                if ($rank <= $playInSlots) {
                    $playInHits[$teamId] = ($playInHits[$teamId] ?? 0) + 1;
                }
            }
        }

        $output = [];
        foreach ($teams as $team) {
            $teamId = (int) $team['team_id'];
            $output[$teamId] = [
                'avg_rank' => ($rankSums[$teamId] ?? ($playInSlots + 1)) / max(1, $runs),
                'playoff_make_probability' => ($playoffHits[$teamId] ?? 0) / max(1, $runs),
                'play_in_probability' => ($playInHits[$teamId] ?? 0) / max(1, $runs),
            ];
        }

        return $output;
    }

    private function randomNormal(float $mean = 0.0, float $std = 1.0): float
    {
        $u = max(lcg_value(), 1e-12);
        $v = max(lcg_value(), 1e-12);
        $z = sqrt(-2.0 * log($u)) * cos(2.0 * M_PI * $v);
        return $mean + ($std * $z);
    }

    /**
     * @return array{conference:string,division:string}
     */
    private function resolveTeamAlignment(Team $team): array
    {
        $rawConference = trim((string) ($team->conference ?? ''));
        $rawDivision = trim((string) ($team->division ?? ''));

        $conference = $this->normalizeConference($rawConference);
        $division = $this->normalizeDivision($rawDivision);

        if ($conference === null && $division !== null) {
            $conference = $this->conferenceFromDivision($division);
        }

        $abbr = strtoupper(trim((string) ($team->abbreviation ?? '')));
        $fallback = $this->abbreviationAlignmentMap()[$abbr] ?? null;
        if (is_array($fallback)) {
            $conference = $conference ?? $fallback['conference'];
            $division = $division ?? $fallback['division'];
        }

        return [
            'conference' => $conference ?? 'Unknown',
            'division' => $division ?? 'Unknown',
        ];
    }

    private function normalizeConference(string $conference): ?string
    {
        if ($conference === '') {
            return null;
        }

        $normalized = strtolower($conference);
        if (str_contains($normalized, 'east')) {
            return 'Eastern';
        }
        if (str_contains($normalized, 'west')) {
            return 'Western';
        }

        return null;
    }

    private function normalizeDivision(string $division): ?string
    {
        if ($division === '') {
            return null;
        }

        $normalized = strtolower(str_replace('division', '', $division));
        $normalized = trim($normalized);

        return match ($normalized) {
            'atlantic' => 'Atlantic',
            'central' => 'Central',
            'southeast' => 'Southeast',
            'northwest' => 'Northwest',
            'pacific' => 'Pacific',
            'southwest' => 'Southwest',
            default => null,
        };
    }

    private function conferenceFromDivision(string $division): ?string
    {
        return match ($division) {
            'Atlantic', 'Central', 'Southeast' => 'Eastern',
            'Northwest', 'Pacific', 'Southwest' => 'Western',
            default => null,
        };
    }

    /**
     * @return array<string, array{conference:string,division:string}>
     */
    private function abbreviationAlignmentMap(): array
    {
        return [
            'ATL' => ['conference' => 'Eastern', 'division' => 'Southeast'],
            'BOS' => ['conference' => 'Eastern', 'division' => 'Atlantic'],
            'BKN' => ['conference' => 'Eastern', 'division' => 'Atlantic'],
            'CHA' => ['conference' => 'Eastern', 'division' => 'Southeast'],
            'CHI' => ['conference' => 'Eastern', 'division' => 'Central'],
            'CLE' => ['conference' => 'Eastern', 'division' => 'Central'],
            'DET' => ['conference' => 'Eastern', 'division' => 'Central'],
            'IND' => ['conference' => 'Eastern', 'division' => 'Central'],
            'MIA' => ['conference' => 'Eastern', 'division' => 'Southeast'],
            'MIL' => ['conference' => 'Eastern', 'division' => 'Central'],
            'NY' => ['conference' => 'Eastern', 'division' => 'Atlantic'],
            'ORL' => ['conference' => 'Eastern', 'division' => 'Southeast'],
            'PHI' => ['conference' => 'Eastern', 'division' => 'Atlantic'],
            'TOR' => ['conference' => 'Eastern', 'division' => 'Atlantic'],
            'WSH' => ['conference' => 'Eastern', 'division' => 'Southeast'],
            'DAL' => ['conference' => 'Western', 'division' => 'Southwest'],
            'DEN' => ['conference' => 'Western', 'division' => 'Northwest'],
            'GS' => ['conference' => 'Western', 'division' => 'Pacific'],
            'HOU' => ['conference' => 'Western', 'division' => 'Southwest'],
            'LAC' => ['conference' => 'Western', 'division' => 'Pacific'],
            'LAL' => ['conference' => 'Western', 'division' => 'Pacific'],
            'MEM' => ['conference' => 'Western', 'division' => 'Southwest'],
            'MIN' => ['conference' => 'Western', 'division' => 'Northwest'],
            'NO' => ['conference' => 'Western', 'division' => 'Southwest'],
            'OKC' => ['conference' => 'Western', 'division' => 'Northwest'],
            'PHX' => ['conference' => 'Western', 'division' => 'Pacific'],
            'POR' => ['conference' => 'Western', 'division' => 'Northwest'],
            'SAC' => ['conference' => 'Western', 'division' => 'Pacific'],
            'SA' => ['conference' => 'Western', 'division' => 'Southwest'],
            'UTAH' => ['conference' => 'Western', 'division' => 'Northwest'],
        ];
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
