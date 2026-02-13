<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\GradePredictions;
use Illuminate\Console\Command;

class GradePredictionsCommand extends Command
{
    protected $signature = 'cbb:grade-predictions
                            {--season= : Grade predictions for a specific season (defaults to current year)}';

    protected $description = 'Grade CBB predictions against actual game outcomes and display accuracy metrics';

    public function handle(): int
    {
        $gradePredictions = new GradePredictions;

        $season = $this->option('season') ?? date('Y');

        $this->info("Grading predictions for season {$season}...");
        $this->newLine();

        $results = $gradePredictions->execute($season);

        if ($results['graded'] === 0) {
            $this->warn('No ungraded predictions found for completed games.');

            return Command::SUCCESS;
        }

        $this->info("Successfully graded {$results['graded']} predictions!");
        $this->newLine();

        $this->info('Overall Accuracy Metrics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Games Graded', $results['graded']],
                ['Winner Accuracy', $results['winner_accuracy'].'%'],
                ['Avg Spread Error (MAE)', $results['avg_spread_error'].' points'],
                ['Avg Total Error (MAE)', $results['avg_total_error'].' points'],
            ]
        );

        $this->newLine();
        $this->info('Accuracy by Confidence Level:');

        $statsByConfidence = $gradePredictions->getStatsByConfidence($season);

        if ($statsByConfidence->isNotEmpty()) {
            $this->table(
                ['Confidence', 'Games', 'Winner %', 'Spread MAE', 'Total MAE'],
                $statsByConfidence->map(fn ($stat) => [
                    $stat['confidence'],
                    $stat['total_games'],
                    $stat['winner_accuracy'].'%',
                    $stat['avg_spread_error'],
                    $stat['avg_total_error'],
                ])
            );
        } else {
            $this->warn('No graded predictions available to show confidence breakdown.');
        }

        return Command::SUCCESS;
    }
}
