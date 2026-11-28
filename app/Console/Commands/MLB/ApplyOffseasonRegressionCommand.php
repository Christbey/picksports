<?php

namespace App\Console\Commands\MLB;

use App\Actions\MLB\CalculateElo;
use App\Models\MLB\Player;
use App\Models\MLB\Team;
use App\Services\Sports\Elo\EloAdmissionMode;
use App\Services\Sports\Elo\EloSeasonRollover;
use Illuminate\Console\Command;

class ApplyOffseasonRegressionCommand extends Command
{
    protected $signature = 'mlb:apply-offseason-regression
                            {--team-factor=0.33 : Regression factor for teams (default 1/3)}
                            {--pitcher-factor=0.40 : Regression factor for pitchers (default 0.40)}
                            {--season= : Explicit target season required by the coordinated writer}';

    protected $description = 'Apply offseason regression to MLB team and pitcher Elo ratings';

    public function handle(): int
    {
        $teamFactor = filter_var($this->option('team-factor'), FILTER_VALIDATE_FLOAT);
        $pitcherFactor = filter_var($this->option('pitcher-factor'), FILTER_VALIDATE_FLOAT);
        if ($teamFactor === false || $pitcherFactor === false || ! is_finite($teamFactor) || ! is_finite($pitcherFactor)
            || $teamFactor < 0 || $teamFactor > 1 || $pitcherFactor < 0 || $pitcherFactor > 1) {
            $this->error('Regression factors must be finite numbers between zero and one.');

            return self::FAILURE;
        }
        try {
            $coordinated = app(EloAdmissionMode::class)->managed('mlb', (new Team)->getConnection());
        } catch (\RuntimeException) {
            $this->error('Durably admitted MLB Elo cannot fall back to disabled legacy regression.');

            return self::FAILURE;
        }
        if ($coordinated) {
            $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
            if ($season === false) {
                $this->error('The coordinated MLB rollover requires an explicit target --season.');

                return self::FAILURE;
            }
            try {
                $result = app(EloSeasonRollover::class)->execute('mlb', $season,
                    ['team' => $this->option('team-factor'), 'pitcher' => $this->option('pitcher-factor')]);
            } catch (\RuntimeException|\InvalidArgumentException|\DomainException) {
                $this->error('MLB rollover was refused; inspect baseline, version, target and season evidence.');

                return self::FAILURE;
            }
            $this->info('Coordinated MLB rollover completed: '.$result['changed_entities'].' changed; '.$result['reused_entities'].' reused.');

            return self::SUCCESS;
        }
        $calculateElo = new CalculateElo;

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
