<?php

namespace App\Console\Commands\MLB;

use App\Actions\MLB\CalculateElo;
use App\Models\MLB\Player;
use App\Models\MLB\Team;
use Illuminate\Console\Command;

class ApplyOffseasonRegressionCommand extends Command
{
    protected $signature = 'mlb:apply-offseason-regression
                            {--team-factor=0.33 : Regression factor for teams (default 1/3)}
                            {--pitcher-factor=0.40 : Regression factor for pitchers (default 0.40)}';

    protected $description = 'Apply offseason regression to MLB team and pitcher Elo ratings';

    public function handle(): int
    {
        $calculateElo = new CalculateElo;
        $teamFactor = (float) $this->option('team-factor');
        $pitcherFactor = (float) $this->option('pitcher-factor');

        // Apply regression to all teams
        $this->info('Applying regression to team Elo ratings...');
        $teams = Team::query()->whereNotNull('elo_rating')->get();

        $teamChanges = [];
        foreach ($teams as $team) {
            $oldElo = $team->elo_rating;
            $newElo = $calculateElo->applyTeamRegression($team, $teamFactor);
            $change = $newElo - $oldElo;
            $teamChanges[] = [
                "{$team->location} {$team->name}",
                $oldElo,
                $newElo,
                $change > 0 ? "+{$change}" : $change,
            ];
        }

        $this->table(
            ['Team', 'Old Elo', 'New Elo', 'Change'],
            $teamChanges
        );

        // Apply regression to all pitchers
        $this->newLine();
        $this->info('Applying regression to pitcher Elo ratings...');
        $pitchers = Player::query()
            ->whereNotNull('elo_rating')
            ->with('team')
            ->get();

        $pitcherCount = $pitchers->count();
        $this->info("Processing {$pitcherCount} pitchers...");

        $bar = $this->output->createProgressBar($pitcherCount);
        $bar->start();

        foreach ($pitchers as $pitcher) {
            $calculateElo->applyPitcherRegression($pitcher, $pitcherFactor);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Regression applied successfully!');
        $this->info("Team regression factor: {$teamFactor} (1/3 toward mean)");
        $this->info("Pitcher regression factor: {$pitcherFactor} (40% toward mean)");

        return Command::SUCCESS;
    }
}
