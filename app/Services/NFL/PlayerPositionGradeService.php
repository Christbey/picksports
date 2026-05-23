<?php

namespace App\Services\NFL;

use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\Player;
use Carbon\CarbonInterface;
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
        $entries = $this->depthChartEntries($teamId, $season);

        $players = $entries
            ->filter(fn (DepthChartEntry $entry): bool => $entry->player_id !== null)
            ->map(fn (DepthChartEntry $entry): array => $this->gradedEntry($entry, $season, $asOf))
            ->values();

        $groups = $players
            ->groupBy(fn (array $row): string => (string) $row['group'])
            ->map(fn (Collection $rows, string $group): array => $this->groupSummary($group, $rows))
            ->sortByDesc(fn (array $row): float => (float) ($row['grade'] ?? 0))
            ->values()
            ->all();

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
                'overall_grade' => $this->weightedAverage($players),
            ],
            'groups' => $groups,
            'players' => $players->sortBy([
                ['group', 'asc'],
                ['depth_rank', 'asc'],
                ['grade', 'desc'],
            ])->values()->all(),
        ];
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
     * @return Collection<int,DepthChartEntry>
     */
    private function depthChartEntries(int $teamId, int $season): Collection
    {
        return DepthChartEntry::query()
            ->with('player')
            ->where('team_id', $teamId)
            ->where('season', $season)
            ->orderByDesc('is_starter')
            ->orderBy('position_code')
            ->orderBy('depth_rank')
            ->orderBy('slot_order')
            ->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function gradedEntry(DepthChartEntry $entry, int $season, CarbonInterface $asOf): array
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
            'components' => $grade['components'] ?? [],
        ];
    }

    /**
     * @return array{grade:float,games:int,note:string,components:array<string,float|int>}
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
     * @return array{grade:float,games:int,note:string,components:array<string,float|int>}
     */
    private function graded(float $grade, int $games, string $note, array $components): array
    {
        return [
            'grade' => max(35.0, min(95.0, $grade)),
            'games' => $games,
            'note' => $note,
            'components' => array_map(fn (float|int $value): float|int => is_float($value) ? round($value, 3) : $value, $components),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function groupSummary(string $group, Collection $rows): array
    {
        $graded = $rows->filter(fn (array $row): bool => $row['grade'] !== null)->values();

        return [
            'group' => $group,
            'players' => $rows->count(),
            'starters' => $rows->where('is_starter', true)->count(),
            'graded_players' => $graded->count(),
            'coverage_rate' => $rows->isNotEmpty() ? round($graded->count() / $rows->count(), 3) : 0.0,
            'grade' => $this->weightedAverage($rows),
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
