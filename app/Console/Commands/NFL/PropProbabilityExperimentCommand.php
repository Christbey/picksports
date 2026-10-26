<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\NflPropProbabilityExperiment;
use Illuminate\Console\Command;

class PropProbabilityExperimentCommand extends Command
{
    protected $signature = 'nfl:prop-probability-experiment {mode : capture, grade or report} {--season= : Required season} {--game= : Optional upcoming game}';

    protected $description = 'Capture, grade and monitor immutable prospective NFL prop probabilities without publishing selections';

    public function handle(NflPropProbabilityExperiment $experiment): int
    {
        $mode = $this->argument('mode');
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        $game = $this->option('game');
        if (! in_array($mode, ['capture', 'grade', 'report'], true) || ! $season || $season < 2000 || $season > now()->year
            || ($game !== null && (! ctype_digit((string) $game) || (int) $game < 1))) {
            $this->error('Supply capture, grade or report, a valid season and optional positive game ID.');

            return self::FAILURE;
        }
        $result = match ($mode) {
            'capture' => $experiment->capture($season, $game === null ? null : (int) $game),
            'grade' => $experiment->grade($season),
            'report' => $experiment->monitoring($season),
        };
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
