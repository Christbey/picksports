<?php

namespace App\Console\Commands\CBB;

use App\Models\CBB\TeamStat;
use Illuminate\Console\Command;

class CalculateTeamStatsPossessions extends Command
{
    protected $signature = 'cbb:calculate-possessions
                            {--force : Recalculate even if possessions already exist}';

    protected $description = 'Calculate and store possessions for CBB team stats using the formula';

    public function handle(): int
    {
        $query = TeamStat::query()->with(['game.plays', 'team']);

        if (! $this->option('force')) {
            $query->whereNull('possessions');
        }

        $teamStats = $query->get();

        if ($teamStats->isEmpty()) {
            $this->info('No team stats to update.');

            return Command::SUCCESS;
        }

        $this->info("Calculating possessions for {$teamStats->count()} team stat records...");

        $bar = $this->output->createProgressBar($teamStats->count());
        $bar->start();

        foreach ($teamStats as $teamStat) {
            $possessions = $this->calculatePossessions($teamStat);

            $teamStat->update([
                'possessions' => $possessions,
            ]);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✓ Updated {$teamStats->count()} team stat records");

        return Command::SUCCESS;
    }

    protected function calculatePossessions(TeamStat $teamStat): float
    {
        // Formula: FGA - OREB + TO + (0.4 * FTA)
        $fga = $teamStat->field_goals_attempted ?? 0;
        $oreb = $teamStat->offensive_rebounds ?? 0;
        $to = $teamStat->turnovers ?? 0;
        $fta = $teamStat->free_throws_attempted ?? 0;

        return $fga - $oreb + $to + (0.4 * $fta);
    }
}
