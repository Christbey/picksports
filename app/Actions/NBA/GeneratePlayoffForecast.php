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
        $remainingSosWeight = max(0.0, (float) ($config['remaining_sos_weight'] ?? 0.12));
        $teams = $this->attachSelectionScores($teamPool, $weights, $remainingSosWeight)
            ->sortByDesc('selection_score')
            ->values();

        $playoffTeamsPerConference = $this->clampInt((int) ($config['playoff_teams_per_conference'] ?? 8), 1, 15);
        $playInTeamsPerConference = $this->clampInt((int) ($config['play_in_teams_per_conference'] ?? 10), $playoffTeamsPerConference, 15);
        $directPlayoffTeamsPerConference = max(1, $playoffTeamsPerConference - 2);
        $divisionWinnerBonus = max(0.0, (float) ($config['division_winner_bonus'] ?? 0.2));
        $rankNoiseStd = max(0.01, (float) ($config['rank_noise_std'] ?? 0.35));
        $finalsSeedPenalty = max(0.0, (float) ($config['finals_seed_penalty'] ?? 0.06));
        $conferenceFinalsBase = max(0.05, min(0.95, (float) ($config['conference_finals_base'] ?? 0.42)));
        $simulationRuns = max(25, (int) ($config['simulations'] ?? 500));

        $headToHead = $this->buildHeadToHeadMatrix($season);
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
                $directPlayoffTeamsPerConference,
                $playoffTeamsPerConference,
                $playInTeamsPerConference,
                $headToHead
            );

            $powerSum = max(1e-6, (float) $conferenceTeamsWithDivisionBonus->sum('power_rating'));

            foreach ($conferenceTeamsWithDivisionBonus as $team) {
                $teamId = (int) $team['team_id'];
                $sim = $simulated[$teamId] ?? null;
                if (! is_array($sim)) {
                    continue;
                }

                $rank = (int) $sim['projected_rank'];
                $playoffMake = (float) $sim['playoff_make_probability'];
                $projectedSeed = $sim['projected_seed'];
                $seedForPenalty = $projectedSeed ?? $rank;

                $powerShare = max(0.0, (float) $team['power_rating']) / $powerSum;
                $conferenceFinals = $playoffMake * max(0.02, ($conferenceFinalsBase - (($seedForPenalty - 1) * $finalsSeedPenalty)));
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
                    'direct_playoff_probability' => round((float) $sim['direct_playoff_probability'], 5),
                    'play_in_tournament_probability' => round((float) $sim['play_in_tournament_probability'], 5),
                    'division_win_probability' => round((float) $sim['division_win_probability'], 5),
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
                'direct_playoff_probability' => $row['direct_playoff_probability'],
                'play_in_tournament_probability' => $row['play_in_tournament_probability'],
                'division_win_probability' => $row['division_win_probability'],
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
                'direct_playoff_probability',
                'play_in_tournament_probability',
                'division_win_probability',
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

        $defaultElo = (float) config('nba.elo.default', 1500);
        $alignmentByTeamId = [];
        foreach ($metrics as $metric) {
            if (! $metric->team) {
                continue;
            }
            $alignmentByTeamId[(int) $metric->team_id] = $this->resolveTeamAlignment($metric->team);
        }

        $finalGames = DB::table('nba_games')
            ->select(['home_team_id', 'away_team_id', 'home_score', 'away_score'])
            ->where('season', $season)
            ->where('status', 'STATUS_FINAL')
            ->get();

        [$overallRecords, $conferenceRecords, $divisionRecords] = $this->buildRecordSplits($finalGames, $alignmentByTeamId);
        $remainingScheduleStrength = $this->buildRemainingScheduleStrength($season, $metrics, $defaultElo);

        return $metrics->map(function (TeamMetric $metric) use (
            $overallRecords,
            $conferenceRecords,
            $divisionRecords,
            $remainingScheduleStrength,
            $defaultElo
        ) {
            if (! $metric->team) {
                return null;
            }

            $teamId = (int) $metric->team_id;
            $record = $overallRecords[$teamId] ?? ['wins' => 0, 'losses' => 0];
            $wins = (int) ($record['wins'] ?? 0);
            $losses = (int) ($record['losses'] ?? 0);
            $games = $wins + $losses;
            ['conference' => $conference, 'division' => $division] = $this->resolveTeamAlignment($metric->team);
            $conferenceRecord = $conferenceRecords[$teamId] ?? ['wins' => 0, 'losses' => 0];
            $conferenceGames = ((int) $conferenceRecord['wins']) + ((int) $conferenceRecord['losses']);
            $divisionRecord = $divisionRecords[$teamId] ?? ['wins' => 0, 'losses' => 0];
            $divisionGames = ((int) $divisionRecord['wins']) + ((int) $divisionRecord['losses']);
            $remainingSos = (float) ($remainingScheduleStrength[$teamId] ?? $defaultElo);
            $currentSos = (float) ($metric->strength_of_schedule ?? $defaultElo);

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
                'team_id' => $teamId,
                'conference' => $conference,
                'division' => $division,
                'abbreviation' => strtoupper(trim((string) ($metric->team->abbreviation ?? ''))),
                'win_pct' => $games > 0 ? $wins / $games : 0.5,
                'conference_win_pct' => $conferenceGames > 0 ? ((int) $conferenceRecord['wins'] / $conferenceGames) : 0.5,
                'division_win_pct' => $divisionGames > 0 ? ((int) $divisionRecord['wins'] / $divisionGames) : 0.5,
                'net_rating' => (float) ($metric->net_rating ?? 0),
                'strength_of_schedule' => $currentSos,
                'remaining_strength_of_schedule' => $remainingSos,
                'elo_rating' => (float) ($metric->team->elo_rating ?? $defaultElo),
                'selection_score' => 0.0,
                'power_rating' => 0.0,
            ];
        })->filter()->values();
    }

    private function attachSelectionScores(Collection $teams, array $weights, float $remainingSosWeight): Collection
    {
        $keys = ['net_rating', 'strength_of_schedule', 'elo_rating', 'win_pct'];
        $normalized = $this->normalizeWeights($weights, $keys);

        $zScores = [];
        foreach ($keys as $key) {
            $zScores[$key] = $this->zScores($teams->pluck($key, 'team_id')->all());
        }

        $remainingSosZScores = $this->zScores($teams->pluck('remaining_strength_of_schedule', 'team_id')->all());

        return $teams->map(function (array $team) use ($normalized, $zScores, $keys, $remainingSosWeight, $remainingSosZScores) {
            $teamId = $team['team_id'];
            $score = 0.0;
            foreach ($keys as $key) {
                $score += ($zScores[$key][$teamId] ?? 0.0) * ($normalized[$key] ?? 0.0);
            }
            $score -= ($remainingSosZScores[$teamId] ?? 0.0) * $remainingSosWeight;

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
     * @return array<int, array{
     *  avg_rank:float,
     *  projected_rank:int,
     *  projected_seed:int|null,
     *  direct_playoff_probability:float,
     *  play_in_tournament_probability:float,
     *  playoff_make_probability:float,
     *  play_in_probability:float,
     *  division_win_probability:float
     * }>
     */
    private function simulateConferenceRankings(
        Collection $teams,
        int $runs,
        float $noiseStd,
        int $directPlayoffSlots,
        int $playoffSlots,
        int $playInSlots,
        array $headToHead
    ): array {
        $rankSums = [];
        $rankHits = [];
        $playoffHits = [];
        $directPlayoffHits = [];
        $playInTournamentHits = [];
        $playInHits = [];
        $divisionWins = [];
        $playoffSeedHits = [];

        for ($run = 0; $run < $runs; $run++) {
            $ranked = $this->sortSimulatedStandings($teams
                ->map(function (array $team) use ($noiseStd): array {
                    $team['sim_score'] = (float) $team['adjusted_selection_score'] + $this->randomNormal(0.0, $noiseStd);
                    return $team;
                })
                ->values(), $headToHead);

            $winningTeamByDivision = [];

            foreach ($ranked as $index => $team) {
                $teamId = (int) $team['team_id'];
                $rank = $index + 1;
                $division = (string) ($team['division'] ?? 'Unknown');

                $rankSums[$teamId] = ($rankSums[$teamId] ?? 0.0) + $rank;
                $rankHits[$teamId][$rank] = ($rankHits[$teamId][$rank] ?? 0) + 1;
                if ($rank <= $directPlayoffSlots) {
                    $playoffHits[$teamId] = ($playoffHits[$teamId] ?? 0) + 1;
                    $directPlayoffHits[$teamId] = ($directPlayoffHits[$teamId] ?? 0) + 1;
                    $playoffSeedHits[$teamId][$rank] = ($playoffSeedHits[$teamId][$rank] ?? 0) + 1;
                }
                if ($rank <= $playInSlots) {
                    $playInHits[$teamId] = ($playInHits[$teamId] ?? 0) + 1;
                }
                if ($rank > $directPlayoffSlots && $rank <= $playInSlots) {
                    $playInTournamentHits[$teamId] = ($playInTournamentHits[$teamId] ?? 0) + 1;
                }

                if (! isset($winningTeamByDivision[$division])) {
                    $winningTeamByDivision[$division] = $teamId;
                }
            }

            foreach ($winningTeamByDivision as $teamId) {
                $divisionWins[$teamId] = ($divisionWins[$teamId] ?? 0) + 1;
            }

            $playInParticipants = $playInSlots - $directPlayoffSlots;
            if ($playInParticipants >= 4 && $ranked->count() >= ($directPlayoffSlots + 4) && $playoffSlots >= ($directPlayoffSlots + 2)) {
                $seed7 = $ranked[$directPlayoffSlots];
                $seed8 = $ranked[$directPlayoffSlots + 1];
                $seed9 = $ranked[$directPlayoffSlots + 2];
                $seed10 = $ranked[$directPlayoffSlots + 3];

                [$winner78, $loser78] = $this->simulatePlayInGame($seed7, $seed8);
                [$winner910] = $this->simulatePlayInGame($seed9, $seed10);
                [$winnerForEighthSeed] = $this->simulatePlayInGame($loser78, $winner910);

                $winner7Id = (int) $winner78['team_id'];
                $winner8Id = (int) $winnerForEighthSeed['team_id'];

                $playoffHits[$winner7Id] = ($playoffHits[$winner7Id] ?? 0) + 1;
                $playoffHits[$winner8Id] = ($playoffHits[$winner8Id] ?? 0) + 1;

                $playoffSeedHits[$winner7Id][$directPlayoffSlots + 1] = ($playoffSeedHits[$winner7Id][$directPlayoffSlots + 1] ?? 0) + 1;
                $playoffSeedHits[$winner8Id][$directPlayoffSlots + 2] = ($playoffSeedHits[$winner8Id][$directPlayoffSlots + 2] ?? 0) + 1;
            }
        }

        $output = [];
        foreach ($teams as $team) {
            $teamId = (int) $team['team_id'];
            $projectedRank = $this->mostFrequentBucket($rankHits[$teamId] ?? []) ?? ($playInSlots + 1);
            $projectedSeed = $this->mostFrequentBucket($playoffSeedHits[$teamId] ?? []);
            $output[$teamId] = [
                'avg_rank' => ($rankSums[$teamId] ?? ($playInSlots + 1)) / max(1, $runs),
                'projected_rank' => $projectedRank,
                'projected_seed' => $projectedSeed !== null ? $this->clampInt($projectedSeed, 1, $playoffSlots) : null,
                'direct_playoff_probability' => ($directPlayoffHits[$teamId] ?? 0) / max(1, $runs),
                'play_in_tournament_probability' => ($playInTournamentHits[$teamId] ?? 0) / max(1, $runs),
                'playoff_make_probability' => ($playoffHits[$teamId] ?? 0) / max(1, $runs),
                'play_in_probability' => ($playInHits[$teamId] ?? 0) / max(1, $runs),
                'division_win_probability' => ($divisionWins[$teamId] ?? 0) / max(1, $runs),
            ];
        }

        return $output;
    }

    private function sortSimulatedStandings(Collection $teams, array $headToHead): Collection
    {
        $rows = $teams->all();

        usort($rows, function (array $a, array $b) use ($headToHead): int {
            $scoreDiff = (float) $b['sim_score'] - (float) $a['sim_score'];
            if (abs($scoreDiff) > 1e-9) {
                return $scoreDiff > 0 ? 1 : -1;
            }

            $aId = (int) $a['team_id'];
            $bId = (int) $b['team_id'];
            $aVsB = $headToHead[$aId][$bId] ?? ['wins' => 0, 'games' => 0];
            $bVsA = $headToHead[$bId][$aId] ?? ['wins' => 0, 'games' => 0];
            if (($aVsB['games'] ?? 0) > 0 && ($bVsA['games'] ?? 0) > 0) {
                $aH2h = ($aVsB['wins'] ?? 0) / max(1, (int) ($aVsB['games'] ?? 0));
                $bH2h = ($bVsA['wins'] ?? 0) / max(1, (int) ($bVsA['games'] ?? 0));
                if (abs($aH2h - $bH2h) > 1e-9) {
                    return $aH2h > $bH2h ? -1 : 1;
                }
            }

            foreach ([
                'win_pct',
                'conference_win_pct',
                'division_win_pct',
                'selection_score',
                'net_rating',
                'elo_rating',
            ] as $metric) {
                $aVal = (float) ($a[$metric] ?? 0);
                $bVal = (float) ($b[$metric] ?? 0);
                if (abs($aVal - $bVal) > 1e-9) {
                    return $aVal > $bVal ? -1 : 1;
                }
            }

            $aRemainingSos = (float) ($a['remaining_strength_of_schedule'] ?? 0);
            $bRemainingSos = (float) ($b['remaining_strength_of_schedule'] ?? 0);
            if (abs($aRemainingSos - $bRemainingSos) > 1e-9) {
                return $aRemainingSos < $bRemainingSos ? -1 : 1;
            }

            return ((int) $a['team_id']) <=> ((int) $b['team_id']);
        });

        return collect($rows)->values();
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function simulatePlayInGame(array $homeTeam, array $awayTeam): array
    {
        $homeWinProb = $this->matchupWinProbability($homeTeam, $awayTeam);
        $homeWins = lcg_value() < $homeWinProb;

        return $homeWins
            ? [$homeTeam, $awayTeam]
            : [$awayTeam, $homeTeam];
    }

    private function matchupWinProbability(array $homeTeam, array $awayTeam): float
    {
        $homeCourtAdvantage = (float) config('nba.elo.home_court_advantage', 100);
        $homeStrength = (float) ($homeTeam['elo_rating'] ?? 1500) + ((float) ($homeTeam['sim_score'] ?? 0) * 40) + $homeCourtAdvantage;
        $awayStrength = (float) ($awayTeam['elo_rating'] ?? 1500) + ((float) ($awayTeam['sim_score'] ?? 0) * 40);
        $eloDiff = $homeStrength - $awayStrength;
        $prob = 1 / (1 + pow(10, (-$eloDiff / 400)));

        return max(0.05, min(0.95, $prob));
    }

    /**
     * @param  array<int,int>  $hits
     */
    private function mostFrequentBucket(array $hits): ?int
    {
        if ($hits === []) {
            return null;
        }

        $bestKey = null;
        $bestValue = -1;
        foreach ($hits as $key => $value) {
            if ($value > $bestValue || ($value === $bestValue && ($bestKey === null || $key < $bestKey))) {
                $bestKey = (int) $key;
                $bestValue = (int) $value;
            }
        }

        return $bestKey;
    }

    /**
     * @return array<int, array<int, array{wins:int,games:int}>>
     */
    private function buildHeadToHeadMatrix(int $season): array
    {
        $games = DB::table('nba_games')
            ->select(['home_team_id', 'away_team_id', 'home_score', 'away_score'])
            ->where('season', $season)
            ->where('status', 'STATUS_FINAL')
            ->get();

        $headToHead = [];
        foreach ($games as $game) {
            $homeTeamId = (int) $game->home_team_id;
            $awayTeamId = (int) $game->away_team_id;
            $homeScore = (int) ($game->home_score ?? 0);
            $awayScore = (int) ($game->away_score ?? 0);

            if ($homeScore === $awayScore) {
                continue;
            }

            $homeWon = $homeScore > $awayScore;
            $headToHead[$homeTeamId][$awayTeamId]['games'] = ($headToHead[$homeTeamId][$awayTeamId]['games'] ?? 0) + 1;
            $headToHead[$awayTeamId][$homeTeamId]['games'] = ($headToHead[$awayTeamId][$homeTeamId]['games'] ?? 0) + 1;
            $headToHead[$homeTeamId][$awayTeamId]['wins'] = ($headToHead[$homeTeamId][$awayTeamId]['wins'] ?? 0) + ($homeWon ? 1 : 0);
            $headToHead[$awayTeamId][$homeTeamId]['wins'] = ($headToHead[$awayTeamId][$homeTeamId]['wins'] ?? 0) + ($homeWon ? 0 : 1);
        }

        return $headToHead;
    }

    /**
     * @param  array<int,array{conference:string,division:string}>  $alignmentByTeamId
     * @return array{
     *  0:array<int,array{wins:int,losses:int}>,
     *  1:array<int,array{wins:int,losses:int}>,
     *  2:array<int,array{wins:int,losses:int}>
     * }
     */
    private function buildRecordSplits($games, array $alignmentByTeamId): array
    {
        $overall = [];
        $conference = [];
        $division = [];

        $applyResult = function (int $teamId, int $oppId, bool $won) use (&$overall, &$conference, &$division, $alignmentByTeamId): void {
            $overall[$teamId]['wins'] = ($overall[$teamId]['wins'] ?? 0) + ($won ? 1 : 0);
            $overall[$teamId]['losses'] = ($overall[$teamId]['losses'] ?? 0) + ($won ? 0 : 1);

            $teamAlignment = $alignmentByTeamId[$teamId] ?? null;
            $oppAlignment = $alignmentByTeamId[$oppId] ?? null;
            if (! is_array($teamAlignment) || ! is_array($oppAlignment)) {
                return;
            }

            if (($teamAlignment['conference'] ?? null) === ($oppAlignment['conference'] ?? null)) {
                $conference[$teamId]['wins'] = ($conference[$teamId]['wins'] ?? 0) + ($won ? 1 : 0);
                $conference[$teamId]['losses'] = ($conference[$teamId]['losses'] ?? 0) + ($won ? 0 : 1);
            }

            if (($teamAlignment['division'] ?? null) === ($oppAlignment['division'] ?? null)) {
                $division[$teamId]['wins'] = ($division[$teamId]['wins'] ?? 0) + ($won ? 1 : 0);
                $division[$teamId]['losses'] = ($division[$teamId]['losses'] ?? 0) + ($won ? 0 : 1);
            }
        };

        foreach ($games as $game) {
            $homeTeamId = (int) $game->home_team_id;
            $awayTeamId = (int) $game->away_team_id;
            $homeScore = (int) ($game->home_score ?? 0);
            $awayScore = (int) ($game->away_score ?? 0);

            if ($homeScore === $awayScore) {
                continue;
            }

            $homeWon = $homeScore > $awayScore;
            $applyResult($homeTeamId, $awayTeamId, $homeWon);
            $applyResult($awayTeamId, $homeTeamId, ! $homeWon);
        }

        return [$overall, $conference, $division];
    }

    /**
     * @return array<int,float>
     */
    private function buildRemainingScheduleStrength(int $season, Collection $metrics, float $defaultElo): array
    {
        $teamElo = $metrics
            ->filter(fn (TeamMetric $metric) => $metric->team !== null)
            ->mapWithKeys(fn (TeamMetric $metric) => [
                (int) $metric->team_id => (float) ($metric->team->elo_rating ?? $defaultElo),
            ])
            ->all();

        $games = DB::table('nba_games')
            ->select(['home_team_id', 'away_team_id'])
            ->where('season', $season)
            ->where('status', '!=', 'STATUS_FINAL')
            ->where(function ($query): void {
                $query
                    ->whereNull('home_score')
                    ->orWhereNull('away_score');
            })
            ->get();

        $remainingElos = [];
        foreach ($games as $game) {
            $homeTeamId = (int) $game->home_team_id;
            $awayTeamId = (int) $game->away_team_id;
            $homeOppElo = (float) ($teamElo[$awayTeamId] ?? $defaultElo);
            $awayOppElo = (float) ($teamElo[$homeTeamId] ?? $defaultElo);

            $remainingElos[$homeTeamId][] = $homeOppElo;
            $remainingElos[$awayTeamId][] = $awayOppElo;
        }

        $output = [];
        foreach ($remainingElos as $teamId => $elos) {
            if ($elos === []) {
                continue;
            }
            $output[(int) $teamId] = array_sum($elos) / count($elos);
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
