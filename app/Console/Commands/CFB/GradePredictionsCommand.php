<?php

namespace App\Console\Commands\CFB;

use App\Actions\CFB\GradePredictions;
use App\Console\Commands\Sports\AbstractGradePredictionsCommand;
use Illuminate\Support\Facades\Artisan;

class GradePredictionsCommand extends AbstractGradePredictionsCommand
{
    protected const COMMAND_NAME = 'cfb:grade-predictions';

    protected const COMMAND_DESCRIPTION = 'Grade CFB predictions against actual game outcomes and display accuracy metrics';

    protected const GRADE_ACTION_CLASS = GradePredictions::class;

    public function handle(): int
    {
        $exitCode = parent::handle();

        if (! $this->option('calibrate')) {
            return $exitCode;
        }

        $season = (int) ($this->option('season') ?? date('Y'));
        $this->newLine();
        $this->info('Updating CFB adaptive calibration from graded predictions...');

        return Artisan::call('cfb:update-adaptive-calibration', [
            '--season' => $season,
        ]) === self::SUCCESS ? $exitCode : self::FAILURE;
    }

    protected function buildSignature(): string
    {
        return parent::buildSignature()."\n {--calibrate : Update active adaptive calibration after grading}";
    }
}
