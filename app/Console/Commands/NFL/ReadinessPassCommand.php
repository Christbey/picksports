<?php

namespace App\Console\Commands\NFL;

use Illuminate\Console\Command;

class ReadinessPassCommand extends Command
{
    protected $signature = 'nfl:readiness-pass
        {--from-season=2009 : First NFL season to include}
        {--to-season= : Last NFL season to include, defaults to current config season}
        {--season-type=2 : Season type for historical backfill}
        {--profile=full-historical : Historical prediction profile}
        {--backfill-limit=0 : Limit historical backfill rows, 0 means all matching games}
        {--current-season= : Current/upcoming season for sentinel validation}
        {--reason-code-min-games=25 : Minimum sample for reason-code analysis}
        {--reason-code-top=60 : Number of reason-code rows to print}
        {--reason-code-max-size=1 : Max reason-code combo size; keep 1 for fast scheduled audits}
        {--spread-backtest-limit=0 : Spread backtest limit, 0 means all available rows}
        {--skip-backfill : Skip historical prediction replay/regrade}
        {--skip-analysis : Skip prediction accuracy analysis}
        {--skip-reason-codes : Skip reason-code analysis}
        {--skip-point-audit : Skip point projection audit}
        {--skip-moneyline : Skip moneyline backtest}
        {--skip-spreads : Skip spread backtest}
        {--skip-totals : Skip totals backtest}
        {--skip-sentinel : Skip operations sentinel validation}
        {--continue-on-failure : Keep running later steps if one command fails}';

    protected $description = 'Run the NFL model readiness workflow: replay, grade, analyze, backtest, and validate sentinel health';

    public function handle(): int
    {
        $fromSeason = (int) $this->option('from-season');
        $toSeason = (int) ($this->option('to-season') ?: config('nfl.season.default'));
        $currentSeason = (int) ($this->option('current-season') ?: $toSeason);

        if ($fromSeason > $toSeason) {
            $this->error('--from-season must be less than or equal to --to-season.');

            return self::FAILURE;
        }

        $this->info("NFL readiness pass for seasons {$fromSeason}-{$toSeason}");
        $this->newLine();

        $steps = $this->steps($fromSeason, $toSeason, $currentSeason);
        $results = [];
        $failed = false;

        foreach ($steps as $step) {
            if ($step['skip']) {
                $results[] = [$step['label'], 'skipped'];

                continue;
            }

            $this->line('Running: '.$step['label']);
            $exitCode = $this->call($step['command'], $step['arguments']);
            $status = $exitCode === self::SUCCESS ? 'ok' : 'failed '.$exitCode;
            $results[] = [$step['label'], $status];

            if ($exitCode !== self::SUCCESS) {
                $failed = true;
                if (! (bool) $this->option('continue-on-failure')) {
                    break;
                }
            }

            $this->newLine();
        }

        $this->newLine();
        $this->table(['Step', 'Status'], $results);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<array{label:string,command:string,arguments:array<string,mixed>,skip:bool}>
     */
    private function steps(int $fromSeason, int $toSeason, int $currentSeason): array
    {
        $backfillArguments = [
            '--from-season' => $fromSeason,
            '--to-season' => $toSeason,
            '--season-type' => (string) $this->option('season-type'),
            '--profile' => (string) $this->option('profile'),
            '--regrade' => true,
        ];
        $backfillLimit = max(0, (int) $this->option('backfill-limit'));
        if ($backfillLimit > 0) {
            $backfillArguments['--limit'] = $backfillLimit;
        }

        return [
            [
                'label' => 'Backfill and regrade historical predictions',
                'command' => 'nfl:backfill-historical-predictions',
                'arguments' => $backfillArguments,
                'skip' => (bool) $this->option('skip-backfill'),
            ],
            [
                'label' => 'Analyze prediction accuracy',
                'command' => 'nfl:analyze-predictions',
                'arguments' => [
                    '--from-season' => $fromSeason,
                    '--to-season' => $toSeason,
                ],
                'skip' => (bool) $this->option('skip-analysis'),
            ],
            [
                'label' => 'Analyze reason codes',
                'command' => 'nfl:analyze-reason-codes',
                'arguments' => [
                    '--from-season' => $fromSeason,
                    '--to-season' => $toSeason,
                    '--min-games' => (int) $this->option('reason-code-min-games'),
                    '--top' => (int) $this->option('reason-code-top'),
                    '--max-size' => (int) $this->option('reason-code-max-size'),
                ],
                'skip' => (bool) $this->option('skip-reason-codes'),
            ],
            [
                'label' => 'Audit point projections',
                'command' => 'nfl:analyze-point-projections',
                'arguments' => [
                    '--from-season' => $fromSeason,
                    '--to-season' => $toSeason,
                ],
                'skip' => (bool) $this->option('skip-point-audit'),
            ],
            [
                'label' => 'Backtest winner-priced moneyline EV',
                'command' => 'nfl:backtest-moneylines',
                'arguments' => [
                    '--from-season' => $fromSeason,
                    '--to-season' => $toSeason,
                ],
                'skip' => (bool) $this->option('skip-moneyline'),
            ],
            [
                'label' => 'Backtest spreads',
                'command' => 'nfl:backtest-spreads',
                'arguments' => [
                    '--limit' => (int) $this->option('spread-backtest-limit'),
                ],
                'skip' => (bool) $this->option('skip-spreads'),
            ],
            [
                'label' => 'Backtest totals',
                'command' => 'nfl:backtest-totals',
                'arguments' => [
                    '--from-season' => $fromSeason,
                    '--to-season' => $toSeason,
                ],
                'skip' => (bool) $this->option('skip-totals'),
            ],
            [
                'label' => 'Run NFL sentinel validation',
                'command' => 'sports:operations-sentinel',
                'arguments' => [
                    '--sport' => 'nfl',
                    '--season' => $currentSeason,
                    '--validate-only' => true,
                    '--skip-ai-review' => true,
                ],
                'skip' => (bool) $this->option('skip-sentinel'),
            ],
        ];
    }
}
