<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\PickCandidate;
use App\Services\MLB\Picks\MlbPickGradingService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BacktestPickCandidatesCommand extends Command
{
    protected $signature = 'mlb:backtest-pick-candidates
        {--season= : MLB season}
        {--from= : Start date}
        {--to= : End date}
        {--market= : Market type}
        {--min-score= : Minimum candidate score}
        {--json : Output JSON}';

    protected $description = 'Grade and report MLB daily pick candidate performance';

    public function handle(MlbPickGradingService $grading): int
    {
        $season = $this->option('season') ? (int) $this->option('season') : null;
        $gradingReport = $grading->gradeWithReport($season);
        $allRows = $this->allRows($season);
        $rows = $allRows
            ->filter(fn (PickCandidate $candidate): bool => $candidate->isPregamePerformanceEligible())
            ->values();
        $excludedRows = $allRows
            ->reject(fn (PickCandidate $candidate): bool => $candidate->isPregamePerformanceEligible())
            ->values();
        $reportExclusions = $this->exclusionReport($excludedRows);

        $summary = [
            'graded_now' => $gradingReport['graded'],
            'excluded_from_grading_now' => $gradingReport['excluded'],
            'grading_exclusion_reasons' => $gradingReport['exclusion_reasons'],
            'rows' => $rows->count(),
            'excluded_from_report' => $excludedRows->count(),
            'report_exclusion_reasons' => $reportExclusions,
            'by_market' => $this->groupReport($rows, fn (PickCandidate $row): string => $row->market_type),
            'by_score_bucket' => $this->groupReport($rows, fn (PickCandidate $row): string => $this->scoreBucket((int) $row->score)),
            'by_risk_flag' => $this->riskReport($rows),
            'by_reason_code' => $this->reasonReport($rows),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('MLB Pick Candidate Backtest');
        $this->line('Rows: '.$summary['rows']);
        $this->line('Graded this run: '.$summary['graded_now']);
        $this->line('Excluded from grading this run: '.$summary['excluded_from_grading_now']);
        $this->line('Excluded from report: '.$summary['excluded_from_report']);
        if ($summary['grading_exclusion_reasons'] !== [] || $summary['report_exclusion_reasons'] !== []) {
            $this->newLine();
            $this->table(['Scope', 'Reason', 'Rows'], [
                ...$this->exclusionRows('grading', $summary['grading_exclusion_reasons']),
                ...$this->exclusionRows('report', $summary['report_exclusion_reasons']),
            ]);
        }
        $this->newLine();
        $this->table(['Market', 'Rows', 'Wins', 'Losses', 'Pushes', 'Hit %', 'Units', 'ROI', 'Avg Score', 'Avg CLV'], $summary['by_market']);
        $this->newLine();
        $this->table(['Score Bucket', 'Rows', 'Wins', 'Losses', 'Pushes', 'Hit %', 'Units', 'ROI', 'Avg Score', 'Avg CLV'], $summary['by_score_bucket']);
        $this->newLine();
        $this->table(['Risk Flag', 'Rows', 'Wins', 'Losses', 'Pushes', 'Hit %', 'Units', 'ROI', 'Avg Score', 'Avg CLV'], array_slice($summary['by_risk_flag'], 0, 20));
        $this->newLine();
        $this->table(['Reason Code', 'Rows', 'Wins', 'Losses', 'Pushes', 'Hit %', 'Units', 'ROI', 'Avg Score', 'Avg CLV'], array_slice($summary['by_reason_code'], 0, 20));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int,PickCandidate>
     */
    private function allRows(?int $season): Collection
    {
        $query = PickCandidate::query()->whereNotNull('graded_at');

        if ($season !== null) {
            $query->where('season', $season);
        }
        if ($this->option('from')) {
            $query->whereDate('generated_at', '>=', (string) $this->option('from'));
        }
        if ($this->option('to')) {
            $query->whereDate('generated_at', '<=', (string) $this->option('to'));
        }
        if ($this->option('market')) {
            $query->where('market_type', (string) $this->option('market'));
        }
        if ($this->option('min-score')) {
            $query->where('score', '>=', (int) $this->option('min-score'));
        }

        return $query->with('game')->get()->toBase();
    }

    /**
     * @param  callable(PickCandidate): string  $key
     * @return list<list<string|int|float>>
     */
    private function groupReport(Collection $rows, callable $key): array
    {
        return $rows->groupBy($key)->map(fn (Collection $group, string $label): array => $this->row($label, $group))->values()->all();
    }

    /**
     * @return list<list<string|int|float>>
     */
    private function riskReport(Collection $rows): array
    {
        return $this->expandedReport($rows, 'risk_flags');
    }

    /**
     * @return list<list<string|int|float>>
     */
    private function reasonReport(Collection $rows): array
    {
        return $this->expandedReport($rows, 'reason_codes');
    }

    /**
     * @return list<list<string|int|float>>
     */
    private function expandedReport(Collection $rows, string $column): array
    {
        $expanded = collect();
        foreach ($rows as $row) {
            foreach (($row->{$column} ?? []) as $code) {
                $expanded->push([$code, $row]);
            }
        }

        return $expanded
            ->groupBy(fn (array $item): string => (string) $item[0])
            ->map(fn (Collection $group, string $label): array => $this->row($label, $group->pluck(1)))
            ->sortByDesc(fn (array $row): int => (int) $row[1])
            ->values()
            ->all();
    }

    /**
     * @return list<string|int|float>
     */
    private function row(string $label, Collection $group): array
    {
        $wins = $group->where('result_status', 'win')->count();
        $losses = $group->where('result_status', 'loss')->count();
        $pushes = $group->where('result_status', 'push')->count();
        $decisions = $wins + $losses;
        $units = round((float) $group->sum('result_profit_units'), 3);

        return [
            $label,
            $group->count(),
            $wins,
            $losses,
            $pushes,
            $decisions > 0 ? number_format($wins / $decisions * 100, 1).'%' : 'n/a',
            $units,
            $group->count() > 0 ? number_format($units / $group->count() * 100, 1).'%' : 'n/a',
            number_format((float) $group->avg('score'), 1),
            $group->whereNotNull('clv')->isNotEmpty() ? number_format((float) $group->whereNotNull('clv')->avg('clv'), 3) : 'n/a',
        ];
    }

    /**
     * @return array<string,int>
     */
    private function exclusionReport(Collection $rows): array
    {
        $report = [];
        foreach ($rows as $row) {
            if (! $row instanceof PickCandidate) {
                continue;
            }

            foreach ($row->performanceExclusionReasons() as $reason) {
                $report[$reason] = ($report[$reason] ?? 0) + 1;
            }
        }

        ksort($report);

        return $report;
    }

    /**
     * @param  array<string,int>  $reasons
     * @return list<list<string|int>>
     */
    private function exclusionRows(string $scope, array $reasons): array
    {
        return collect($reasons)
            ->map(fn (int $count, string $reason): array => [$scope, $reason, $count])
            ->values()
            ->all();
    }

    private function scoreBucket(int $score): string
    {
        return match (true) {
            $score >= 80 => '80+',
            $score >= 68 => '68-79',
            $score >= 58 => '58-67',
            default => 'below_58',
        };
    }
}
