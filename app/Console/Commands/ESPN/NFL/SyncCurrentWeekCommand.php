<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Console\Commands\ESPN\AbstractSyncCurrentRangeCommand;
use App\Jobs\ESPN\NFL\FetchGamesFromScoreboard;
use App\Jobs\ESPN\NFL\FetchTeams;
use Carbon\Carbon;

class SyncCurrentWeekCommand extends AbstractSyncCurrentRangeCommand
{
    protected const COMMAND_NAME = 'espn:sync-nfl-current';

    protected const SPORT_CODE = 'NFL';

    protected const TEAM_SYNC_JOB_CLASS = FetchTeams::class;

    protected const CURRENT_DATE_SYNC_JOB_CLASS = FetchGamesFromScoreboard::class;

    private const MAX_WINDOW_DAYS = 31;

    protected function buildSignature(): string
    {
        return parent::buildSignature()."\n {--skip-teams : Skip the all-teams refresh and only queue the current calendar window}";
    }

    public function handle(): int
    {
        $back = $this->windowDays($this->option('days-back'));
        $forward = $this->windowDays($this->option('days-forward'));
        if ($back === null || $forward === null || $back + $forward + 1 > self::MAX_WINDOW_DAYS) {
            $this->error('The NFL calendar window requires nonnegative integer day counts and at most 31 inclusive dates.');

            return self::INVALID;
        }

        // Capture one application-calendar day before any optional queued team work.
        $today = Carbon::today();
        $start = $today->copy()->subDays($back);
        $end = $today->copy()->addDays($forward);
        if ($this->option('skip-teams')) {
            $this->line('Skipping NFL team refresh; queuing the current calendar window only.');
        } else {
            $this->info('Queuing NFL team refresh...');
            $this->dispatchTeamsSync();
        }

        $count = $this->eachDateInRange($start, $end, function (Carbon $date): void {
            $this->dispatchCurrentDateSync($date->format('Ymd'));
        });
        $this->info("Requested {$count} NFL scoreboard date dispatches from {$start->format('Y-m-d')} to {$end->format('Y-m-d')}; unique locks may deduplicate and workers must complete ingestion.");

        return self::SUCCESS;
    }

    private function windowDays(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 && $value < self::MAX_WINDOW_DAYS ? $value : null;
        }
        if (! is_string($value) || preg_match('/\A(?:0|[1-9]\d?)\z/D', $value) !== 1) {
            return null;
        }
        $days = (int) $value;

        return $days < self::MAX_WINDOW_DAYS ? $days : null;
    }
}
