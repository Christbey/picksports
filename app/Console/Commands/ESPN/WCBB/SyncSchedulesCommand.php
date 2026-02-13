<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Actions\ESPN\WCBB\SyncGamesFromSchedule;
use App\Models\WCBB\Team;
use App\Services\ESPN\WCBB\EspnService;
use Illuminate\Console\Command;

class SyncSchedulesCommand extends Command
{
    protected $signature = 'espn:sync-wcbb-schedules
                            {--season=2026 : The season year}
                            {--team= : Specific team ESPN ID (optional, syncs all teams if not provided)}';

    protected $description = 'Sync WCBB team schedules from ESPN API';

    public function handle(): int
    {
        $season = (int) $this->option('season');
        $teamEspnId = $this->option('team');

        if ($teamEspnId) {
            // Sync single team schedule
            return $this->syncTeamSchedule($teamEspnId, $season);
        }

        // Sync all teams
        $teams = Team::all();
        $totalTeams = $teams->count();
        $totalGames = 0;

        $this->info("Syncing schedules for {$totalTeams} WCBB teams (Season {$season})...");

        $progressBar = $this->output->createProgressBar($totalTeams);
        $progressBar->start();

        foreach ($teams as $team) {
            $service = new EspnService;
            $action = new SyncGamesFromSchedule($service);

            $count = $action->execute($team->espn_id, $season);
            $totalGames += $count;

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Successfully synced {$totalGames} games from {$totalTeams} team schedules.");

        return Command::SUCCESS;
    }

    protected function syncTeamSchedule(string $teamEspnId, int $season): int
    {
        $this->info("Syncing schedule for team {$teamEspnId} (Season {$season})...");

        $service = new EspnService;
        $action = new SyncGamesFromSchedule($service);

        $count = $action->execute($teamEspnId, $season);

        $this->info("Successfully synced {$count} games for team {$teamEspnId}.");

        return Command::SUCCESS;
    }
}
