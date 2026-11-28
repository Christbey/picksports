<?php

namespace App\Console\Commands;

use App\Services\AlertService;
use Illuminate\Console\Command;

class CheckBettingAlertsCommand extends Command
{
    protected $signature = 'alerts:check {sport? : The sport to check (nfl, nba, cbb, wcbb, mlb, cfb, wnba)}';

    protected $description = 'Check for high-value betting opportunities and dispatch alerts to users';

    public function __construct(
        protected AlertService $alertService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sport = $this->argument('sport');

        if ($sport !== null) {
            if (! is_string($sport) || ! in_array(strtolower(trim($sport)), ['nfl', 'nba', 'cbb', 'wcbb', 'mlb', 'cfb', 'wnba'], true)) {
                $this->error('Sport must be one of nfl, nba, cbb, wcbb, mlb, cfb, wnba; omit it to check all sports.');

                return Command::FAILURE;
            }
            $sport = strtolower(trim($sport));
            $this->info("Checking {$sport} for betting value opportunities...");

            $alertsSent = $this->alertService->checkForValueOpportunities($sport);

            if ($alertsSent > 0) {
                $this->info("✓ Dispatched {$alertsSent} alert(s) for {$sport}");
            } else {
                $this->line("  No value opportunities found for {$sport}");
            }

            return Command::SUCCESS;
        }

        $this->info('Checking all sports for betting value opportunities...');
        $this->newLine();

        $results = $this->alertService->checkAllSports();
        $totalAlerts = 0;

        foreach ($results as $sportName => $count) {
            if ($count > 0) {
                $this->line("  ✓ {$sportName}: {$count} alert(s) dispatched");
                $totalAlerts += $count;
            } else {
                $this->line("  - {$sportName}: No opportunities");
            }
        }

        $this->newLine();

        if ($totalAlerts > 0) {
            $this->info("✓ Dispatched {$totalAlerts} total alert(s) across all sports!");
        } else {
            $this->info('No value opportunities found');
        }

        return Command::SUCCESS;
    }
}
