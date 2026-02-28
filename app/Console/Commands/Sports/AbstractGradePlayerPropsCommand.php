<?php

namespace App\Console\Commands\Sports;

use App\Actions\GradePlayerProps;
use App\Console\Commands\Concerns\ResolvesRequiredConfig;
use Illuminate\Console\Command;

abstract class AbstractGradePlayerPropsCommand extends Command
{
    use ResolvesRequiredConfig;

    protected const COMMAND_NAME = '';

    protected const COMMAND_DESCRIPTION = '';

    protected const SEASON_OPTION_DESCRIPTION = 'Grade player props for a specific season (defaults to current year)';

    protected const SPORT_KEY = '';

    protected const SPORT_LABEL = '';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = $this->commandDescription();

        parent::__construct();
    }

    public function handle(): int
    {
        $gradePlayerProps = app(GradePlayerProps::class);

        $season = $this->option('season') ?? date('Y');

        $this->info("Grading {$this->sportLabel()} player props for season {$season}...");
        $this->newLine();

        $results = $gradePlayerProps->execute($this->sportKey(), $season);

        if ($results['graded'] === 0) {
            $this->warn('No ungraded player props found for completed games.');

            return self::SUCCESS;
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

        $statsByMarket = $gradePlayerProps->getStatsByMarket($this->sportKey(), $season);

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

        return self::SUCCESS;
    }

    protected function sportKey(): string
    {
        return $this->requiredString(static::SPORT_KEY, 'SPORT_KEY must be defined on grade-player-props command.');
    }

    protected function sportLabel(): string
    {
        return $this->requiredString(static::SPORT_LABEL, 'SPORT_LABEL must be defined on grade-player-props command.');
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
