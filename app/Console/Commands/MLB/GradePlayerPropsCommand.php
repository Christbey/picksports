<?php

namespace App\Console\Commands\MLB;

use App\Actions\GradePlayerProps;
use Illuminate\Console\Command;

class GradePlayerPropsCommand extends Command
{
    protected $signature = 'mlb:grade-player-props
                            {--season= : Grade player props for a specific season (defaults to current year)}';

    protected $description = 'Grade MLB player props against actual player statistics';

    public function handle(): int
    {
        $gradePlayerProps = new GradePlayerProps;

        $season = $this->option('season') ?? date('Y');

        $this->info("Grading MLB player props for season {$season}...");
        $this->newLine();

        $results = $gradePlayerProps->execute('baseball_mlb', $season);

        if ($results['graded'] === 0) {
            $this->warn('No ungraded player props found for completed games.');

            return Command::SUCCESS;
        }

        $this->info("Successfully graded {$results['graded']} player props!");
        $this->newLine();

        $this->info('Overall Accuracy Metrics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Props Graded', $results['graded']],
                ['Hit Over Rate', $results['hit_rate'].'%'],
                ['Avg Line Error', $results['avg_error'].' units'],
            ]
        );

        $this->newLine();
        $this->info('Accuracy by Market:');

        $statsByMarket = $gradePlayerProps->getStatsByMarket('baseball_mlb', $season);

        if ($statsByMarket->isNotEmpty()) {
            $this->table(
                ['Market', 'Total Props', 'Hit Over Count', 'Hit Over Rate', 'Avg Error'],
                $statsByMarket->map(fn ($stat) => [
                    $stat['market'],
                    $stat['total_props'],
                    $stat['hit_over_count'],
                    $stat['hit_over_rate'].'%',
                    $stat['avg_error'],
                ])
            );
        } else {
            $this->warn('No graded props available to show market breakdown.');
        }

        return Command::SUCCESS;
    }
}
