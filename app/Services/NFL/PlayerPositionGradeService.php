<?php

namespace App\Services\NFL;

use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerInjurySnapshot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlayerPositionGradeService
{
    /**
     * @return array<string,mixed>
     */
    public function teamReport(int $teamId, int $season, ?string $asOfDate = null): array
    {
        $asOf = $asOfDate ? Carbon::parse($asOfDate)->startOfDay() : now()->startOfDay();
        if ($asOfDate && str_contains($asOfDate, ':')) {
            $asOf = Carbon::parse($asOfDate);
        }
        $depthChart = $this->depthChartState($teamId, $season, $asOf);
        $entries = $depthChart['entries'];

        $players = $entries
            ->filter(fn (Model $entry): bool => $entry->player_id !== null)
            ->map(fn (Model $entry): array => $this->gradedEntry($entry, $season, $asOf))
            ->values();

        $groups = $players
            ->groupBy(fn (array $row): string => (string) $row['group'])
            ->map(fn (Collection $rows, string $group): array => $this->groupSummary(
                $group,
                $rows,
                $teamId,
                $season,
                $asOf,
            ))
            ->sortByDesc(fn (array $row): float => (float) ($row['grade'] ?? 0))
            ->values()
            ->all();
        $overallGrade = $this->weightedGroupAverage(collect($groups));
        $availability = $this->availabilityScenarios($teamId, $players, $overallGrade, $asOf);

        return [
            'team_id' => $teamId,
            'season' => $season,
            'as_of_date' => $asOf->toDateString(),
            'summary' => [
                'depth_entries' => $entries->count(),
                'graded_players' => $players->filter(fn (array $row): bool => $row['grade'] !== null)->count(),
                'coverage_rate' => $players->isNotEmpty()
                    ? round($players->filter(fn (array $row): bool => $row['grade'] !== null)->count() / $players->count(), 3)
                    : 0.0,
                'overall_grade' => $overallGrade,
                'grade_confidence' => $this->weightedGroupConfidence(collect($groups)),
                'depth_chart_source' => $depthChart['source'],
                'depth_chart_snapshot_uuid' => $depthChart['snapshot_uuid'],
                'depth_chart_observed_at' => $depthChart['observed_at'],
                'if_in_grade' => $availability['if_in_grade'],
                'if_out_grade' => $availability['if_out_grade'],
                'expected_grade' => $availability['expected_grade'],
                'expected_grade_delta' => $availability['expected_grade_delta'],
            ],
            'groups' => $groups,
            'availability' => $availability,
            'players' => $players->sortBy([
                ['group', 'asc'],
                ['depth_rank', 'asc'],
                ['grade', 'desc'],
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $players
     * @return array<string,mixed>
     */
    private function availabilityScenarios(
        int $teamId,
        Collection $players,
        ?float $overallGrade,
        CarbonInterface $asOf,
    ): array {
        $state = $this->injuryState($teamId, $asOf);
        $groupWeights = [
            'QB' => 0.22,
            'OL' => 0.18,
            'WR_TE' => 0.14,
            'RB' => 0.08,
            'DL_EDGE' => 0.14,
            'LB' => 0.08,
            'DB' => 0.12,
            'ST' => 0.04,
        ];
        $impacts = collect($state['entries'])
            ->map(function (Model $injury) use ($players, $groupWeights): ?array {
                $player = $players->firstWhere('player_id', (int) $injury->player_id);
                if (! is_array($player) || ! is_numeric($player['grade'] ?? null)) {
                    return null;
                }

                $availabilityProbability = $this->availabilityProbability((string) ($injury->status ?? ''));
                if ($availabilityProbability >= 1.0) {
                    return null;
                }

                $replacement = $players
                    ->filter(fn (array $candidate): bool => $candidate['player_id'] !== $player['player_id']
                        && $candidate['group'] === $player['group']
                        && (int) $candidate['depth_rank'] > (int) $player['depth_rank']
                        && is_numeric($candidate['grade'] ?? null))
                    ->sortBy('depth_rank')
                    ->first();
                $replacementGrade = is_array($replacement) && is_numeric($replacement['grade'] ?? null)
                    ? (float) $replacement['grade']
                    : 50.0;
                $roleWeight = $player['is_starter'] ? 1.0 : ((int) $player['depth_rank'] <= 2 ? 0.35 : 0.10);
                $groupWeight = $groupWeights[(string) $player['group']] ?? 0.0;
                $outDelta = max(0.0, ((float) $player['grade'] - $replacementGrade) * $groupWeight * $roleWeight);

                return [
                    'player_id' => (int) $player['player_id'],
                    'player' => $player['player'],
                    'position' => $player['position'],
                    'group' => $player['group'],
                    'status' => $injury->status,
                    'availability_probability' => round($availabilityProbability, 3),
                    'player_grade' => round((float) $player['grade'], 1),
                    'replacement_player_id' => $replacement['player_id'] ?? null,
                    'replacement_player' => $replacement['player'] ?? null,
                    'replacement_grade' => round($replacementGrade, 1),
                    'if_out_grade_delta' => round(-$outDelta, 2),
                    'expected_grade_delta' => round(-$outDelta * (1.0 - $availabilityProbability), 2),
                    'usage_weight' => round($roleWeight, 2),
                    'usage_source' => 'depth_chart_role_proxy',
                    'confidence' => round(min(
                        (float) ($player['grade_confidence'] ?? 0.0),
                        is_array($replacement) ? (float) ($replacement['grade_confidence'] ?? 0.0) : 0.25,
                    ), 3),
                ];
            })
            ->filter()
            ->values();
        $ifOutDelta = (float) $impacts->sum('if_out_grade_delta');
        $expectedDelta = (float) $impacts->sum('expected_grade_delta');

        return [
            'source' => $state['source'],
            'snapshot_uuid' => $state['snapshot_uuid'],
            'observed_at' => $state['observed_at'],
            'if_in_grade' => $overallGrade,
            'if_out_grade' => $overallGrade !== null ? round(max(0.0, $overallGrade + $ifOutDelta), 1) : null,
            'expected_grade' => $overallGrade !== null ? round(max(0.0, $overallGrade + $expectedDelta), 1) : null,
            'expected_grade_delta' => round($expectedDelta, 2),
            'players' => $impacts->all(),
        ];
    }

    /**
     * @return array{entries:Collection<int,Model>,source:string,snapshot_uuid:?string,observed_at:?string}
     */
    private function injuryState(int $teamId, CarbonInterface $asOf): array
    {
        $snapshot = PlayerInjurySnapshot::query()
            ->with('entries')
            ->where('team_id', $teamId)
            ->where('observed_at', '<=', $asOf)
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('source_updated_at')
                    ->orWhere('source_updated_at', '<=', $asOf);
            })
            ->latest('observed_at')
            ->latest('id')
            ->first();

        if ($snapshot !== null) {
            return [
                'entries' => $snapshot->entries,
                'source' => 'append_only_snapshot',
                'snapshot_uuid' => $snapshot->snapshot_uuid,
                'observed_at' => $snapshot->observed_at?->toIso8601String(),
            ];
        }

        $entries = PlayerInjury::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->whereNotNull('source_updated_at')
            ->where('source_updated_at', '<=', $asOf)
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('injury_date')
                    ->orWhereDate('injury_date', '<=', $asOf->toDateString());
            })
            ->get();

        return [
            'entries' => $entries,
            'source' => $entries->isEmpty() ? 'missing_point_in_time_injury_snapshot' : 'timestamped_current_state',
            'snapshot_uuid' => null,
            'observed_at' => $entries->max('source_updated_at')?->toIso8601String(),
        ];
    }

    private function availabilityProbability(string $status): float
    {
        $normalized = strtolower(trim($status));

        return match (true) {
            $normalized === '' => 1.0,
            str_contains($normalized, 'out'),
            str_contains($normalized, 'inactive'),
            str_contains($normalized, 'injured reserve') => 0.0,
            str_contains($normalized, 'doubtful') => (float) config('nfl.predictions.player_position_grades.doubtful_availability', 0.25),
            str_contains($normalized, 'questionable') => (float) config('nfl.predictions.player_position_grades.questionable_availability', 0.60),
            str_contains($normalized, 'probable') => (float) config('nfl.predictions.player_position_grades.probable_availability', 0.90),
            default => 1.0,
        };
    }

    /**
     * @return array<string,mixed>
     */
    public function leagueReport(int $season, ?string $asOfDate = null): array
    {
        $teamIds = DepthChartEntry::query()
            ->where('season', $season)
            ->whereNotNull('team_id')
            ->distinct()
            ->orderBy('team_id')
            ->pluck('team_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $teams = collect($teamIds)
            ->map(fn (int $teamId): array => $this->teamReport($teamId, $season, $asOfDate))
            ->values();

        return [
            'season' => $season,
            'as_of_date' => $asOfDate ?: now()->toDateString(),
            'teams' => $teams->all(),
            'groups' => $this->leagueGroupSummary($teams),
        ];
    }

    /**
     * @return array{
     *     entries:Collection<int,Model>,
     *     source:string,
     *     snapshot_uuid:?string,
     *     observed_at:?string
     * }
     */
    private function depthChartState(int $teamId, int $season, CarbonInterface $asOf): array
    {
        $snapshot = DepthChartSnapshot::query()
            ->with('entries.player')
            ->where('team_id', $teamId)
            ->where('season', $season)
            ->where('observed_at', '<=', $asOf)
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('source_updated_at')
                    ->orWhere('source_updated_at', '<=', $asOf);
            })
            ->latest('observed_at')
            ->latest('id')
            ->first();

        if ($snapshot) {
            return [
                'entries' => $snapshot->entries
                    ->sortBy([
                        ['is_starter', 'desc'],
                        ['position_code', 'asc'],
                        ['depth_rank', 'asc'],
                        ['slot_order', 'asc'],
                    ])
                    ->values(),
                'source' => 'append_only_snapshot',
                'snapshot_uuid' => $snapshot->snapshot_uuid,
                'observed_at' => $snapshot->observed_at?->toIso8601String(),
            ];
        }

        $entries = DepthChartEntry::query()
            ->with('player')
            ->where('team_id', $teamId)
            ->where('season', $season)
            ->whereNotNull('source_updated_at')
            ->where('source_updated_at', '<=', $asOf)
            ->orderByDesc('is_starter')
            ->orderBy('position_code')
            ->orderBy('depth_rank')
            ->orderBy('slot_order')
            ->get();

        return [
            'entries' => $entries,
            'source' => $entries->isEmpty() ? 'missing_point_in_time_depth_chart' : 'timestamped_current_state',
            'snapshot_uuid' => null,
            'observed_at' => $entries->max('source_updated_at')?->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function gradedEntry(Model $entry, int $season, CarbonInterface $asOf): array
    {
        $player = $entry->player;
        $position = strtoupper((string) ($entry->position_code ?: $entry->position_slot_key ?: $player?->position ?: 'UNK'));
        $group = $this->positionGroup($position);
        $grade = $player ? $this->playerGrade($player, $group, $season, $asOf) : null;

        return [
            'player_id' => $entry->player_id,
            'player' => $player?->full_name,
            'position' => $position,
            'group' => $group,
            'depth_rank' => (int) ($entry->depth_rank ?? 99),
            'is_starter' => (bool) $entry->is_starter,
            'grade' => $grade !== null ? round($grade['grade'], 1) : null,
            'sample_games' => $grade['games'] ?? 0,
            'sample_note' => $grade['note'] ?? 'no_supported_player_grade',
            'grade_confidence' => $grade['confidence'] ?? 0.0,
            'components' => $grade['components'] ?? [],
        ];
    }

    /**
     * @return array{grade:float,games:int,note:string,confidence:float,components:array<string,float|int>}
     */
    private function playerGrade(Player $player, string $group, int $season, CarbonInterface $asOf): ?array
    {
        $rows = $this->playerStatRows((int) $player->id, $season, $asOf);
        $games = $rows->count();

        if ($games === 0) {
            return null;
        }

        return match ($group) {
            'QB' => $this->qbGrade($rows),
            'RB' => $this->rbGrade($rows),
            'WR_TE' => $this->receiverGrade($rows, $group),
            'DL_EDGE', 'LB', 'DB' => $this->defenseGrade($rows, $group),
            'ST' => $this->specialTeamsGrade($rows),
            default => null,
        };
    }

    private function playerStatRows(int $playerId, int $season, CarbonInterface $asOf): Collection
    {
        return DB::table('nfl_player_stats')
            ->join('nfl_games', 'nfl_games.id', '=', 'nfl_player_stats.game_id')
            ->where('nfl_player_stats.player_id', $playerId)
            ->whereBetween('nfl_games.season', [$season - 2, $season])
            ->where('nfl_games.status', 'STATUS_FINAL')
            ->whereDate('nfl_games.game_date', '<', $asOf->toDateString())
            ->orderByDesc('nfl_games.game_date')
            ->limit(32)
            ->get();
    }

    private function qbGrade(Collection $rows): ?array
    {
        $attempts = (int) $rows->sum('passing_attempts');
        if ($attempts < 30) {
            return null;
        }

        $games = $rows->count();
        $ypa = $this->rate((float) $rows->sum('passing_yards'), $attempts);
        $tdRate = $this->rate((float) $rows->sum('passing_touchdowns'), $attempts);
        $intRate = $this->rate((float) $rows->sum('interceptions_thrown'), $attempts);
        $sackRate = $this->rate((float) $rows->sum('sacks_taken'), $attempts + (float) $rows->sum('sacks_taken'));
        $rushPerGame = $this->rate((float) $rows->sum('rushing_yards'), $games);

        $grade = 70
            + (($ypa - 7.0) * 8.0)
            + (($tdRate - 0.045) * 160.0)
            - (($intRate - 0.025) * 190.0)
            - (($sackRate - 0.065) * 85.0)
            + min(6.0, max(0.0, $rushPerGame * 0.08));

        return $this->graded($grade, $games, 'passing_efficiency', compact('ypa', 'tdRate', 'intRate', 'sackRate', 'rushPerGame', 'attempts'));
    }

    private function rbGrade(Collection $rows): ?array
    {
        $carries = (int) $rows->sum('rushing_attempts');
        $touches = $carries + (int) $rows->sum('receptions');
        if ($touches < 20) {
            return null;
        }

        $games = $rows->count();
        $ypc = $this->rate((float) $rows->sum('rushing_yards'), max(1, $carries));
        $yardsPerTouch = $this->rate((float) $rows->sum('rushing_yards') + (float) $rows->sum('receiving_yards'), $touches);
        $yardsPerGame = $this->rate((float) $rows->sum('rushing_yards') + (float) $rows->sum('receiving_yards'), $games);
        $tdPerGame = $this->rate((float) $rows->sum('rushing_touchdowns') + (float) $rows->sum('receiving_touchdowns'), $games);

        $grade = 66
            + (($ypc - 4.2) * 4.0)
            + (($yardsPerTouch - 4.8) * 2.0)
            + (($yardsPerGame - 55.0) * 0.08)
            + ($tdPerGame * 4.0);

        return $this->graded($grade, $games, 'scrimmage_efficiency', compact('ypc', 'yardsPerTouch', 'yardsPerGame', 'tdPerGame', 'touches'));
    }

    private function receiverGrade(Collection $rows, string $group): ?array
    {
        $targets = (int) $rows->sum('receiving_targets');
        $receptions = (int) $rows->sum('receptions');
        if (max($targets, $receptions) < 10) {
            return null;
        }

        $games = $rows->count();
        $catchRate = $targets > 0 ? $this->rate($receptions, $targets) : 0.62;
        $yardsPerTarget = $targets > 0 ? $this->rate((float) $rows->sum('receiving_yards'), $targets) : $this->rate((float) $rows->sum('receiving_yards'), max(1, $receptions));
        $yardsPerGame = $this->rate((float) $rows->sum('receiving_yards'), $games);
        $tdPerGame = $this->rate((float) $rows->sum('receiving_touchdowns'), $games);

        $grade = 65
            + (($catchRate - 0.62) * 16.0)
            + (($yardsPerTarget - 7.8) * 2.0)
            + (($yardsPerGame - 45.0) * 0.10)
            + ($tdPerGame * 5.0);

        return $this->graded($grade, $games, 'receiving_efficiency', compact('catchRate', 'yardsPerTarget', 'yardsPerGame', 'tdPerGame', 'targets'));
    }

    private function defenseGrade(Collection $rows, string $group): ?array
    {
        $games = $rows->count();
        $defenseEvents = (float) $rows->sum('tackles_total')
            + ((float) $rows->sum('sacks') * 4)
            + ((float) $rows->sum('interceptions') * 5)
            + ((float) $rows->sum('passes_defended') * 1.5)
            + (((float) $rows->sum('fumbles_forced') + (float) $rows->sum('fumbles_recovered')) * 3);

        if ($defenseEvents <= 0) {
            return null;
        }

        $sacksPerGame = $this->rate((float) $rows->sum('sacks'), $games);
        $impactPerGame = $this->rate(
            (float) $rows->sum('interceptions') + (float) $rows->sum('passes_defended') + (float) $rows->sum('fumbles_forced') + (float) $rows->sum('fumbles_recovered'),
            $games
        );
        $tacklesPerGame = $this->rate((float) $rows->sum('tackles_total'), $games);
        $groupSackWeight = $group === 'DL_EDGE' ? 10.0 : 4.0;

        $grade = 62
            + ($sacksPerGame * $groupSackWeight)
            + ($impactPerGame * 3.0)
            + min(8.0, $tacklesPerGame * 0.55);

        return $this->graded($grade, $games, 'defensive_playmaking', compact('sacksPerGame', 'impactPerGame', 'tacklesPerGame'));
    }

    private function specialTeamsGrade(Collection $rows): ?array
    {
        $fgAttempts = (int) $rows->sum('field_goals_attempted');
        $xpAttempts = (int) $rows->sum('extra_points_attempted');
        if (($fgAttempts + $xpAttempts) < 5) {
            return null;
        }

        $games = $rows->count();
        $fgRate = $this->rate((float) $rows->sum('field_goals_made'), max(1, $fgAttempts));
        $xpRate = $this->rate((float) $rows->sum('extra_points_made'), max(1, $xpAttempts));
        $grade = 62 + (($fgRate - 0.84) * 30.0) + (($xpRate - 0.94) * 10.0);

        return $this->graded($grade, $games, 'kicking_accuracy', compact('fgRate', 'xpRate', 'fgAttempts', 'xpAttempts'));
    }

    /**
     * @param  array<string,float|int>  $components
     * @return array{grade:float,games:int,note:string,confidence:float,components:array<string,float|int>}
     */
    private function graded(float $grade, int $games, string $note, array $components): array
    {
        return [
            'grade' => max(35.0, min(95.0, $grade)),
            'games' => $games,
            'note' => $note,
            'confidence' => round(min(1.0, $games / 8), 3),
            'components' => array_map(fn (float|int $value): float|int => is_float($value) ? round($value, 3) : $value, $components),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function groupSummary(
        string $group,
        Collection $rows,
        int $teamId,
        int $season,
        CarbonInterface $asOf,
    ): array {
        $graded = $rows->filter(fn (array $row): bool => $row['grade'] !== null)->values();
        $unitGrade = $group === 'OL'
            ? $this->offensiveLineUnitGrade($teamId, $season, $asOf)
            : null;
        $grade = $unitGrade['grade'] ?? $this->weightedAverage($rows);
        $coverageRate = $unitGrade !== null
            ? 1.0
            : ($rows->isNotEmpty() ? round($graded->count() / $rows->count(), 3) : 0.0);
        $confidence = $unitGrade['confidence']
            ?? ($graded->isNotEmpty() ? round((float) $graded->avg('grade_confidence'), 3) : 0.0);

        return [
            'group' => $group,
            'players' => $rows->count(),
            'starters' => $rows->where('is_starter', true)->count(),
            'graded_players' => $graded->count(),
            'coverage_rate' => $coverageRate,
            'grade' => $grade,
            'grade_confidence' => $confidence,
            'grade_source' => $unitGrade !== null ? 'team_pass_protection_and_run_blocking' : 'player_production',
            'sample_games' => $unitGrade['games'] ?? (int) $graded->max('sample_games'),
            'components' => $unitGrade['components'] ?? [],
            'top_players' => $graded
                ->sortByDesc('grade')
                ->take(3)
                ->map(fn (array $row): string => trim((string) $row['player']).' '.number_format((float) $row['grade'], 1))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $teams
     * @return array<int,array<string,mixed>>
     */
    private function leagueGroupSummary(Collection $teams): array
    {
        return $teams
            ->flatMap(fn (array $team): array => $team['groups'])
            ->groupBy('group')
            ->map(function (Collection $rows, string $group): array {
                $graded = $rows->filter(fn (array $row): bool => $row['grade'] !== null);

                return [
                    'group' => $group,
                    'teams' => $rows->count(),
                    'avg_grade' => $graded->isNotEmpty() ? round((float) $graded->avg('grade'), 1) : null,
                    'avg_coverage' => round((float) $rows->avg('coverage_rate'), 3),
                    'avg_confidence' => round((float) $rows->avg('grade_confidence'), 3),
                ];
            })
            ->sortByDesc(fn (array $row): float => (float) ($row['avg_grade'] ?? 0))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function weightedAverage(Collection $rows): ?float
    {
        $weighted = 0.0;
        $weight = 0.0;

        foreach ($rows as $row) {
            if ($row['grade'] === null) {
                continue;
            }

            $rowWeight = $row['is_starter'] ? 1.0 : ((int) $row['depth_rank'] <= 2 ? 0.35 : 0.10);
            $weighted += (float) $row['grade'] * $rowWeight;
            $weight += $rowWeight;
        }

        return $weight > 0 ? round($weighted / $weight, 1) : null;
    }

    /**
     * @return array{grade:float,games:int,confidence:float,components:array<string,float|int>}|null
     */
    private function offensiveLineUnitGrade(int $teamId, int $season, CarbonInterface $asOf): ?array
    {
        $rows = DB::table('nfl_team_stats')
            ->join('nfl_games', 'nfl_games.id', '=', 'nfl_team_stats.game_id')
            ->where('nfl_team_stats.team_id', $teamId)
            ->whereBetween('nfl_games.season', [$season - 2, $season])
            ->where('nfl_games.status', 'STATUS_FINAL')
            ->whereDate('nfl_games.game_date', '<', $asOf->toDateString())
            ->orderByDesc('nfl_games.game_date')
            ->limit(32)
            ->get([
                'nfl_team_stats.passing_attempts',
                'nfl_team_stats.sacks_allowed',
                'nfl_team_stats.rushing_attempts',
                'nfl_team_stats.rushing_yards',
            ]);

        if ($rows->isEmpty()) {
            return null;
        }

        $dropbacks = (float) $rows->sum('passing_attempts') + (float) $rows->sum('sacks_allowed');
        $rushAttempts = (float) $rows->sum('rushing_attempts');
        if ($dropbacks < 30 && $rushAttempts < 20) {
            return null;
        }

        $sackRate = $this->rate((float) $rows->sum('sacks_allowed'), max(1.0, $dropbacks));
        $yardsPerCarry = $this->rate((float) $rows->sum('rushing_yards'), max(1.0, $rushAttempts));
        $grade = 70
            - (($sackRate - 0.065) * 165.0)
            + (($yardsPerCarry - 4.2) * 6.0);
        $games = $rows->count();

        return [
            'grade' => round(max(35.0, min(95.0, $grade)), 1),
            'games' => $games,
            'confidence' => round(min(1.0, $games / 8), 3),
            'components' => [
                'sack_rate' => round($sackRate, 4),
                'yards_per_carry' => round($yardsPerCarry, 3),
                'dropbacks' => (int) $dropbacks,
                'rushing_attempts' => (int) $rushAttempts,
            ],
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $groups
     */
    private function weightedGroupAverage(Collection $groups): ?float
    {
        $weights = [
            'QB' => 0.22,
            'OL' => 0.18,
            'WR_TE' => 0.14,
            'RB' => 0.08,
            'DL_EDGE' => 0.14,
            'LB' => 0.08,
            'DB' => 0.12,
            'ST' => 0.04,
        ];
        $weighted = 0.0;
        $weight = 0.0;

        foreach ($groups as $group) {
            if (! is_numeric($group['grade'] ?? null)) {
                continue;
            }

            $groupWeight = $weights[(string) ($group['group'] ?? '')] ?? 0.0;
            $weighted += (float) $group['grade'] * $groupWeight;
            $weight += $groupWeight;
        }

        return $weight > 0 ? round($weighted / $weight, 1) : null;
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $groups
     */
    private function weightedGroupConfidence(Collection $groups): float
    {
        $withGrades = $groups->filter(fn (array $group): bool => is_numeric($group['grade'] ?? null));

        return $withGrades->isEmpty()
            ? 0.0
            : round((float) $withGrades->avg('grade_confidence'), 3);
    }

    private function positionGroup(string $position): string
    {
        return match (true) {
            in_array($position, ['QB'], true) => 'QB',
            in_array($position, ['RB', 'FB'], true) => 'RB',
            in_array($position, ['WR', 'TE'], true) => 'WR_TE',
            in_array($position, ['LT', 'LG', 'C', 'RG', 'RT', 'G', 'OT', 'OL'], true) => 'OL',
            in_array($position, ['LDE', 'RDE', 'DE', 'DT', 'NT', 'LDT', 'RDT', 'EDGE'], true) => 'DL_EDGE',
            in_array($position, ['LB', 'MLB', 'ILB', 'LILB', 'RILB', 'OLB', 'WLB', 'SLB'], true) => 'LB',
            in_array($position, ['CB', 'LCB', 'RCB', 'NB', 'S', 'FS', 'SS'], true) => 'DB',
            in_array($position, ['PK', 'K', 'P', 'LS', 'H', 'KR', 'PR'], true) => 'ST',
            default => 'OTHER',
        };
    }

    private function rate(float $numerator, float $denominator): float
    {
        return $denominator > 0 ? $numerator / $denominator : 0.0;
    }
}
