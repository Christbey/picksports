<?php

namespace App\Console\Commands\Sports;

use App\Services\Predictions\LegacyPredictionGradeRecorder;
use Illuminate\Console\Command;

class RepairLegacyPredictionGradesCommand extends Command
{
    protected $signature = 'sports:repair-legacy-prediction-grades {sport} {--game=* : Explicit game IDs, maximum100} {--apply : Apply reviewed scope; default is dry run}';

    protected $description = 'Inspect or repair bounded legacy forecast grades with append-only audit receipts';

    public function handle(): int
    {
        $sport = strtolower((string) $this->argument('sport'));
        $rawIds = (array) $this->option('game');
        if (! in_array($sport, LegacyPredictionGradeRecorder::SPORTS, true) || $rawIds === [] || count($rawIds) > 100
            || array_filter($rawIds, fn ($id) => ! is_scalar($id) || ! preg_match('/^[1-9][0-9]*$/D', (string) $id)
                || strlen((string) $id) > 18) !== []) {
            $this->error('Choose one supported sport and one to100 explicit positive --game IDs.');

            return self::INVALID;
        }
        $ids = array_values(array_unique(array_map('intval', $rawIds)));
        $prefix = 'App\\Models\\'.strtoupper($sport).'\\';
        $gameClass = $prefix.'Game';
        $predictionClass = $prefix.'Prediction';
        $game = new $gameClass;
        $prediction = new $predictionClass;
        if ($game->getConnection()->getName() !== $prediction->getConnection()->getName()
            || $gameClass::whereIn('id', $ids)->count() !== count($ids)) {
            $this->error('All selected games must exist on the same legacy grading connection before any repair.');

            return self::INVALID;
        }
        if (! $this->option('apply')) {
            $this->info('Dry run: '.count($ids).' existing games selected; no grades, evaluations or receipts changed. Final/score/WNBA safety admission is rechecked under locks on apply.');

            return self::SUCCESS;
        }
        $grader = app('App\\Actions\\'.strtoupper($sport).'\\GradePredictions');
        try {
            $result = $grader->repairForGameIds($ids);
        } catch (\LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Repaired '.$result['graded'].' legacy forecasts; immutable receipts retained.');

        return self::SUCCESS;
    }
}
