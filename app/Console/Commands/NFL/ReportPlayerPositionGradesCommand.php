<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Team;
use App\Services\NFL\PlayerPositionGradeService;
use Illuminate\Console\Command;

class ReportPlayerPositionGradesCommand extends Command
{
    protected $signature = 'nfl:report-player-position-grades
        {--season=2026 : Depth chart season to grade}
        {--team= : Team abbreviation, ESPN id, or local id}
        {--as-of-date= : Only use player stats before this date}
        {--players : Show graded player rows}
        {--json : Output raw JSON instead of tables}';

    protected $description = 'Review NFL player and position group grades from depth chart role plus prior player production';

    public function handle(PlayerPositionGradeService $gradeService): int
    {
        $season = (int) $this->option('season');
        $teamOption = $this->option('team');
        $asOfDate = $this->option('as-of-date') ? (string) $this->option('as-of-date') : null;

        if ($teamOption !== null && $teamOption !== '') {
            $team = $this->resolveTeam((string) $teamOption);
            if (! $team) {
                $this->error("Unable to find NFL team [{$teamOption}].");

                return self::FAILURE;
            }

            $report = $gradeService->teamReport((int) $team->id, $season, $asOfDate);
            $report['team'] = [
                'id' => $team->id,
                'abbreviation' => $team->abbreviation,
                'name' => trim((string) $team->location.' '.(string) $team->name),
            ];

            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $this->line("NFL player/position grades: {$team->abbreviation} {$season}");
            $this->table(
                ['Depth Entries', 'Graded Players', 'Coverage', 'Overall Grade'],
                [[
                    (string) data_get($report, 'summary.depth_entries'),
                    (string) data_get($report, 'summary.graded_players'),
                    number_format((float) data_get($report, 'summary.coverage_rate', 0) * 100, 1).'%',
                    $this->fmt(data_get($report, 'summary.overall_grade')),
                ]]
            );

            $this->table(
                ['Group', 'Players', 'Starters', 'Graded', 'Coverage', 'Grade', 'Top Players'],
                array_map(fn (array $row): array => $this->groupRow($row), $report['groups'] ?? [])
            );

            if ($this->option('players')) {
                $this->newLine();
                $this->table(
                    ['Group', 'Pos', 'Depth', 'Starter', 'Player', 'Grade', 'Games', 'Note'],
                    array_map(fn (array $row): array => [
                        $row['group'],
                        $row['position'],
                        (string) $row['depth_rank'],
                        $row['is_starter'] ? 'yes' : 'no',
                        $row['player'],
                        $this->fmt($row['grade']),
                        (string) $row['sample_games'],
                        $row['sample_note'],
                    ], $report['players'] ?? [])
                );
            }

            return self::SUCCESS;
        }

        $report = $gradeService->leagueReport($season, $asOfDate);
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line("NFL league player/position grade coverage {$season}");
        $this->table(
            ['Group', 'Teams', 'Avg Grade', 'Avg Coverage'],
            array_map(fn (array $row): array => [
                $row['group'],
                (string) $row['teams'],
                $this->fmt($row['avg_grade']),
                number_format((float) $row['avg_coverage'] * 100, 1).'%',
            ], $report['groups'] ?? [])
        );

        $topTeams = collect($report['teams'] ?? [])
            ->sortByDesc(fn (array $team): float => (float) data_get($team, 'summary.overall_grade', 0))
            ->take(12)
            ->map(function (array $team): array {
                $model = Team::query()->find((int) $team['team_id']);

                return [
                    (string) ($model?->abbreviation ?? $team['team_id']),
                    $this->fmt(data_get($team, 'summary.overall_grade')),
                    number_format((float) data_get($team, 'summary.coverage_rate', 0) * 100, 1).'%',
                    (string) data_get($team, 'summary.graded_players'),
                ];
            })
            ->values()
            ->all();

        $this->newLine();
        $this->line('Top overall graded rosters');
        $this->table(['Team', 'Grade', 'Coverage', 'Graded Players'], $topTeams);

        return self::SUCCESS;
    }

    private function resolveTeam(string $value): ?Team
    {
        return Team::query()
            ->where('id', ctype_digit($value) ? (int) $value : -1)
            ->orWhere('abbreviation', strtoupper($value))
            ->orWhere('espn_id', $value)
            ->first();
    }

    /**
     * @return array<int,string>
     */
    private function groupRow(array $row): array
    {
        return [
            (string) $row['group'],
            (string) $row['players'],
            (string) $row['starters'],
            (string) $row['graded_players'],
            number_format((float) $row['coverage_rate'] * 100, 1).'%',
            $this->fmt($row['grade']),
            implode(', ', $row['top_players'] ?? []),
        ];
    }

    private function fmt(mixed $value): string
    {
        return $value === null ? 'n/a' : number_format((float) $value, 1);
    }
}
