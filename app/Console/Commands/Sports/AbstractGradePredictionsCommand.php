<?php

namespace App\Console\Commands\Sports;

use App\Console\Commands\Concerns\ResolvesRequiredConfig;
use Illuminate\Console\Command;

abstract class AbstractGradePredictionsCommand extends Command
{
    use ResolvesRequiredConfig;

    protected const COMMAND_NAME = '';

    protected const COMMAND_DESCRIPTION = '';

    protected const SEASON_OPTION_DESCRIPTION = 'Grade predictions for a specific season (defaults to current year)';

    protected const GRADE_ACTION_CLASS = '';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = $this->commandDescription();

        parent::__construct();
    }

    public function handle(): int
    {
        $gradePredictions = app($this->gradeActionClass());

        $season = $this->option('season') ?? date('Y');

        $this->info("Grading predictions for season {$season}...");
        $this->newLine();

        $results = $gradePredictions->execute($season);

        if ($results['graded'] === 0) {
            $this->warn('No ungraded predictions found for completed games.');

            return self::SUCCESS;
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

        return self::SUCCESS;
    }

    /**
     * @return class-string
     */
    protected function gradeActionClass(): string
    {
        return $this->requiredString(static::GRADE_ACTION_CLASS, 'GRADE_ACTION_CLASS must be defined on grade-predictions command.');
    }

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {--season= : %s}",
            $this->commandName(),
            static::SEASON_OPTION_DESCRIPTION
        );
    }

    protected function commandName(): string
    {
        return $this->requiredString(static::COMMAND_NAME, 'COMMAND_NAME must be defined.');
    }

    protected function commandDescription(): string
    {
        return $this->requiredString(static::COMMAND_DESCRIPTION, 'COMMAND_DESCRIPTION must be defined.');
    }
}
