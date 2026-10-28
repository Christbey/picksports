<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\NflEvidenceLedgerAudit;
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
        {--sample-unit=game : game for independent outcomes; observation for run diagnostics}
        {--limit=100 : Maximum signal rows}
        {--reconcile : Explain per-game grade coverage and pipeline freshness}
        {--leaderboard : Rank prospective evidence using the existing outcome grades}
        {--model-version= : Exact model version for the evidence leaderboard}
        {--cohort=all : all, divisional, conference_non_divisional, interconference or unknown}
        {--validation-from-week=5 : Descriptive later-week window starts here; not an untouched holdout}
        {--json : Emit the full report as JSON}';

    protected $description = 'Report settlement-backed NFL signal performance and season stability';

    public function handle(NflSignalGradeReportService $reportService): int
    {
        try {
            [$fromSeason, $toSeason] = $this->seasonScope();
            if (! in_array($this->option('sample-unit'), ['game', 'observation'], true)) {
                throw new InvalidArgumentException('sample-unit must be game or observation.');
            }
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('reconcile')) {
            if ($fromSeason === null || $fromSeason !== $toSeason || $fromSeason < 2000 || $fromSeason > 2100) {
                $this->error('Reconciliation requires one valid --season.');

                return self::FAILURE;
            }
            $this->line(json_encode(app(NflEvidenceLedgerAudit::class)->report($fromSeason, $this->option('model-version')), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        if ((bool) $this->option('leaderboard')) {
            if ($this->option('include-unsafe') || $this->option('sample-unit') !== 'game') {
                $this->error('Evidence leaderboards require safe pregame observations and independent game samples.');

                return self::FAILURE;
            }
            foreach (['season', 'from-season', 'to-season'] as $option) {
                $value = $this->option($option);
                if ($value !== null && (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 2000 || (int) $value > 2100)) {
                    $this->error('Season scopes must be integers between 2000 and 2100.');

                    return self::FAILURE;
                }
            }
            $cohort = (string) $this->option('cohort');
            $week = filter_var($this->option('validation-from-week'), FILTER_VALIDATE_INT);
            if (! in_array($cohort, ['all', 'divisional', 'conference_non_divisional', 'interconference', 'unknown'], true)
                || $week === false || $week < 2 || $week > 22) {
                $this->error('Supply a valid cohort and validation-from-week between 2 and 22.');

                return self::FAILURE;
            }
            $report = $reportService->leaderboard([
                'from_season' => $fromSeason, 'to_season' => $toSeason,
                'signal_type' => $this->option('signal-type'), 'signal_key' => $this->option('signal-key'),
                'model_version' => $this->option('model-version'), 'cohort' => $cohort,
                'validation_from_week' => $week,
            ]);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $report = $reportService->report([
            'from_season' => $fromSeason,
            'to_season' => $toSeason,
            'signal_type' => $this->option('signal-type'),
            'signal_key' => $this->option('signal-key'),
            'pregame_safe' => ! (bool) $this->option('include-unsafe'),
            'limit' => (int) $this->option('limit'),
            'sample_unit' => $this->option('sample-unit'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($report['signals'] === []) {
            $this->warn('No NFL signal observations matched the requested report scope.');

            return self::SUCCESS;
        }

        $this->info('Outcome sample unit: '.$report['sample_unit'].'. All exact decision settlements are retained.');
        $this->table(
            [
                'Type',
                'Signal',
                'N',
                'Winner',
                'ATS',
                'Total',
                'Tracked W-L-P',
                'Model ROI',
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
                $this->record($row),
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
            ['Type', 'Signal', 'Season', 'N', 'Winner', 'ATS', 'Total', 'Tracked W-L-P', 'Model ROI', 'CLV'],
            collect($report['windows'])->map(fn (array $row): array => [
                $row['signal_type'],
                $row['signal_key'],
                $row['season'],
                $row['observation_count'],
                $this->rate($row['winner_accuracy'], $row['winner_sample']),
                $this->rate($row['ats_hit_rate'], $row['ats_sample']),
                $this->rate($row['total_hit_rate'], $row['total_sample']),
                $this->record($row),
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

    /** @param array<string,mixed> $row */
    private function record(array $row): string
    {
        return sprintf(
            '%d-%d-%d',
            (int) ($row['settlement_wins'] ?? 0),
            (int) ($row['settlement_losses'] ?? 0),
            (int) ($row['settlement_pushes'] ?? 0),
        );
    }
}
