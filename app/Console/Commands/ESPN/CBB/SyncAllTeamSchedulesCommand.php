<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Jobs\ESPN\CBB\FetchTeamSchedule;
use App\Models\CBB\Team;
use Illuminate\Console\Command;

class SyncAllTeamSchedulesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'espn:sync-cbb-all-team-schedules';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync full schedules for all CBB teams from ESPN';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $teams = Team::all();

        $this->info("Syncing schedules for {$teams->count()} teams...");

        $bar = $this->output->createProgressBar($teams->count());
        $bar->start();

        foreach ($teams as $team) {
            FetchTeamSchedule::dispatch($team->espn_id);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Dispatched {$teams->count()} team schedule sync jobs successfully.");

        return Command::SUCCESS;
    }
}
