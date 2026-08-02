<?php

namespace App\Console\Commands\MLB;

use App\Services\ML\CsvDataset;
use App\Services\MLB\MlbPeriodFeatureBuilder;
use App\Services\MLB\MlbPeriodModelRunRegistrar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class TrainPeriodModelsCommand extends Command
{
    protected $signature = 'mlb:train-period-models
        {--from-season= : First training season}
        {--to-season= : Last training season}
        {--no-register : Train without registering or activating the artifact}';

    protected $description = 'Export, train, evaluate, and register MLB F3/F5 multiclass challengers';

    public function handle(
        MlbPeriodFeatureBuilder $features,
        CsvDataset $csv,
        MlbPeriodModelRunRegistrar $registrar,
    ): int {
        $from = (int) ($this->option('from-season')
            ?: config('mlb_ml.period_models.history_start_season', 2021));
        $to = (int) ($this->option('to-season') ?: now()->year);
        $runDirectory = rtrim((string) config('mlb_ml.period_models.work_directory'), '/')
            .'/'.now()->format('Ymd_His').'_'.Str::lower(Str::random(8));
        File::ensureDirectoryExists($runDirectory, 0700, true);
        $dataset = $runDirectory.'/dataset.csv';
        $bundle = $runDirectory.'/period_bundle.joblib';
        $evaluation = $runDirectory.'/evaluation.json';
        $manifest = $runDirectory.'/manifest.json';
        $rows = $features->historicalRows(range($from, $to));
        if ($rows->isEmpty()) {
            $this->error('No MLB period training rows were available.');

            return self::FAILURE;
        }
        $csv->write($dataset, $rows);

        $command = [
            ...(array) config('mlb_ml.weekly_training.python_command'),
            'train-period',
            '--input',
            $dataset,
            '--schema',
            (string) config('mlb_ml.period_models.schema_path'),
            '--output',
            $bundle,
            '--evaluation-output',
            $evaluation,
            '--manifest-output',
            $manifest,
        ];
        $process = new Process($command, (string) config('mlb_ml.process.package_directory'));
        $process->setEnv(['PYTHONPATH' => base_path('ml/mlb/src')]);
        $process->setTimeout((float) config('mlb_ml.period_models.timeout_seconds', 14_400));
        $process->run(fn (string $type, string $output) => $this->output->write($output));
        if (! $process->isSuccessful()) {
            $this->error(trim($process->getErrorOutput()) ?: 'MLB period training failed.');

            return self::FAILURE;
        }
        if ((bool) $this->option('no-register')) {
            $this->info("MLB period model trained at {$bundle}.");

            return self::SUCCESS;
        }

        $artifact = $registrar->register($bundle, $manifest, $evaluation, $dataset);
        $this->info("Registered MLB period challenger {$artifact->id}.");
        $this->line('F3/F5 remain shadow-only until market-specific promotion evidence passes.');

        return self::SUCCESS;
    }
}
