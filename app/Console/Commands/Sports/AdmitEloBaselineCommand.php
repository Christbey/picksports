<?php

namespace App\Console\Commands\Sports;

use App\Services\Sports\Elo\EloBaselineAdmission;
use Illuminate\Console\Command;
use Throwable;

class AdmitEloBaselineCommand extends Command
{
    protected $signature = 'sports:admit-elo-baseline
        {sport : nfl, nba, mlb, cbb or wcbb}
        {--season= : Explicit source baseline season}
        {--entity-type=team : team, or MLB pitcher}
        {--ids=* : Between one and 100 explicit entity IDs}
        {--apply : Apply only with a coordinated, paused admission writer mode}
        {--fingerprint= : Exact dry-run batch fingerprint required for apply}
        {--review-reference= : Bounded operator review reference required for apply}';

    protected $description = 'Inspect exact Elo baseline evidence; dry run by default and refuse uncoordinated apply';

    public function handle(EloBaselineAdmission $admission): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        if ($season === false) {
            $this->error('An explicit valid source season is required.');

            return self::FAILURE;
        }
        try {
            $arguments = [(string) $this->argument('sport'), $season, (string) $this->option('entity-type'), (array) $this->option('ids')];
            $report = $this->option('apply')
                ? $admission->apply(...[...$arguments, (string) $this->option('fingerprint'), (string) $this->option('review-reference')])
                : $admission->inspect(...$arguments);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));

            return isset($report['items']) && collect($report['items'])->contains(fn (array $item): bool => $item['status'] !== 'admissible')
                ? self::FAILURE : self::SUCCESS;
        } catch (Throwable) {
            // Do not expose SQL, connection credentials, or arbitrary source values through CLI errors.
            $this->error('Elo baseline admission refused. Check bounded source evidence and the coordinated writer mode; no legacy projections were changed.');

            return self::FAILURE;
        }
    }
}
