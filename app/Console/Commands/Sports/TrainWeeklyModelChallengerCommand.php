<?php

namespace App\Console\Commands\Sports;

use App\Services\ML\WeeklyChallengerTrainingService;
use Illuminate\Console\Command;
use Throwable;

class TrainWeeklyModelChallengerCommand extends Command
{
    protected $signature = 'sports:train-weekly-model-challenger
        {sport : Sport to train: mlb or nfl}
        {--force : Run even when weekly training is disabled or the training fingerprint is unchanged}
        {--no-promote : Evaluate the active challenger without promoting it}
        {--retain-workdir : Keep the local dataset and Python run directory after successful registration}';

    protected $description = 'Export trusted data, train, register, evaluate, and shadow a weekly model challenger';

    public function handle(WeeklyChallengerTrainingService $training): int
    {
        try {
            $result = $training->run(
                sport: strtolower((string) $this->argument('sport')),
                force: (bool) $this->option('force'),
                allowPromotion: ! (bool) $this->option('no-promote'),
                retainWorkDirectory: (bool) $this->option('retain-workdir'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $status = (string) ($result['status'] ?? 'unknown');
        $this->info((string) ($result['message'] ?? 'Weekly model training finished.'));
        $this->line('Status: '.$status);

        foreach ([
            'cycle_run_id' => 'Cycle run',
            'model_run_id' => 'Model run',
            'artifact_id' => 'Artifact',
            'dataset_hash' => 'Dataset SHA-256',
            'training_fingerprint' => 'Training fingerprint',
            'shadow_artifact_id' => 'Active shadow challenger',
        ] as $key => $label) {
            if (filled($result[$key] ?? null)) {
                $this->line($label.': '.$result[$key]);
            }
        }

        return in_array($status, ['completed', 'skipped', 'disabled'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
