<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\NflSignalGradeReportService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ReportSignalGradesCommand extends Command
{
    protected $signature = 'nfl:report-signal-grades
        {--season= : Report one NFL season}
        {--from-season= : First NFL season to report}
        {--to-season= : Last NFL season to report}
        {--signal-type= : Restrict report to one signal type}
        {--signal-key= : Restrict report to one signal key}
        {--include-unsafe : Include observations not verified as pregame-safe}
        {--limit=100 : Maximum signal rows}
        {--json : Emit the full report as JSON}';

    protected $description = 'Report settlement-backed NFL signal performance and season stability';

    public function handle(NflSignalGradeReportService $reportService): int
    {
        try {
            [$fromSeason, $toSeason] = $this->seasonScope();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $report = $reportService->report([
            'from_season' => $fromSeason,
            'to_season' => $toSeason,
            'signal_type' => $this->option('signal-type'),
            'signal_key' => $this->option('signal-key'),
            'pregame_safe' => ! (bool) $this->option('include-unsafe'),
            'limit' => (int) $this->option('limit'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($report['signals'] === []) {
            $this->warn('No NFL signal observations matched the requested report scope.');

            return self::SUCCESS;
        }

        $this->table(
            [
                'Type',
                'Signal',
                'N',
                'Winner',
                'ATS',
                'Total',
                'ROI',
                'CLV',
                'Brier Lift',
                'Spread Lift',
                'Total Lift',
                'Windows',
                'Acc Range',
            ],
            collect($report['signals'])->map(fn (array $row): array => [
                $row['signal_type'],
                $row['signal_key'],
                $row['observation_count'],
                $this->rate($row['winner_accuracy'], $row['winner_sample']),
                $this->rate($row['ats_hit_rate'], $row['ats_sample']),
                $this->rate($row['total_hit_rate'], $row['total_sample']),
                $this->percentage($row['roi']),
                $this->number($row['avg_clv']),
                $this->number($row['avg_calibration_lift']),
                $this->number($row['avg_spread_error_lift']),
                $this->number($row['avg_total_error_lift']),
                $row['window_count'],
                $this->percentage($row['winner_accuracy_range']),
            ])->all()
        );

        $this->newLine();
        $this->info('Season windows');
        $this->table(
            ['Type', 'Signal', 'Season', 'N', 'Winner', 'ATS', 'Total', 'ROI', 'CLV'],
            collect($report['windows'])->map(fn (array $row): array => [
                $row['signal_type'],
                $row['signal_key'],
                $row['season'],
                $row['observation_count'],
                $this->rate($row['winner_accuracy'], $row['winner_sample']),
                $this->rate($row['ats_hit_rate'], $row['ats_sample']),
                $this->rate($row['total_hit_rate'], $row['total_sample']),
                $this->percentage($row['roi']),
                $this->number($row['avg_clv']),
            ])->all()
        );

        return self::SUCCESS;
    }

    /**
     * @return array{0:?int,1:?int}
     */
    private function seasonScope(): array
    {
        $season = $this->option('season');
        $fromSeason = $this->option('from-season');
        $toSeason = $this->option('to-season');

        if ($season !== null && ($fromSeason !== null || $toSeason !== null)) {
            throw new InvalidArgumentException('Use either --season or --from-season/--to-season, not both.');
        }

        if ($season !== null) {
            return [(int) $season, (int) $season];
        }

        if ($fromSeason === null && $toSeason === null) {
            return [null, null];
        }

        $from = (int) ($fromSeason ?? $toSeason);
        $to = (int) ($toSeason ?? $fromSeason);
        if ($from > $to) {
            throw new InvalidArgumentException('--from-season must be less than or equal to --to-season.');
        }

        return [$from, $to];
    }

    private function rate(?float $value, int $sample): string
    {
        return $value === null ? 'n/a' : round($value * 100, 1)."% ({$sample})";
    }

    private function percentage(?float $value): string
    {
        return $value === null ? 'n/a' : round($value * 100, 2).'%';
    }

    private function number(?float $value): string
    {
        return $value === null ? 'n/a' : (string) round($value, 4);
    }
}
