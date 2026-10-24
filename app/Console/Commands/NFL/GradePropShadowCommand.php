<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\NflPropShadowGrader;
use Illuminate\Console\Command;

class GradePropShadowCommand extends Command
{
    protected $signature = 'nfl:grade-prop-shadow {--season= : Required NFL season}';

    protected $description = 'Grade frozen NFL prop shadow forecasts without changing production props';

    public function handle(NflPropShadowGrader $grader): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        if (! $season || $season < 2000) {
            $this->error('Supply a valid --season.');

            return self::FAILURE;
        }
        $this->line(json_encode($grader->grade($season), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
