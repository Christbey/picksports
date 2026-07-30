<?php

namespace App\Console\Commands\MLB;

use App\Services\MLB\MlbTabularModelRunRegistrar;
use Illuminate\Console\Command;
use Throwable;

class RegisterTabularModelRunCommand extends Command
{
    protected $signature = 'mlb:register-tabular-model-run
        {run-directory : Completed ml/mlb training run directory}
        {--dataset= : Optional exact CSV or Parquet training dataset}';

    protected $description = 'Verify and register a completed Python MLB tabular model run as an immutable bundle';

    public function handle(MlbTabularModelRunRegistrar $registrar): int
    {
        try {
            $artifact = $registrar->register(
                (string) $this->argument('run-directory'),
                filled($this->option('dataset')) ? (string) $this->option('dataset') : null,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('MLB tabular model run registered.');
        $this->line('Model run: '.data_get($artifact->trainingRun?->metadata, 'python_model_run_id'));
        $this->line('Laravel training run: '.$artifact->training_run_id);
        $this->line('Artifact: '.$artifact->id);
        $this->line('Bundle SHA-256: '.$artifact->artifact_hash);
        $this->line('Dataset SHA-256: '.$artifact->dataset_hash);
        $this->line('Storage: '.($artifact->artifact_uri ?: $artifact->artifact_path));

        return self::SUCCESS;
    }
}
