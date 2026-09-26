<?php

namespace App\Console\Commands\CFB;

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TrainScoringChallengerCommand extends Command
{
    protected $signature = 'cfb:train-scoring-challenger {--manifest=} {--output=} {--retrospective}';

    protected $description = 'Offline chronological CFB training; registers a challenger without promoting it';

    public function handle(): int
    {
        if (! is_file((string) $this->option('manifest')) || ! $this->option('output')) {
            return self::FAILURE;
        }
        $manifest = json_decode(file_get_contents($this->option('manifest')), true, 512, JSON_THROW_ON_ERROR);
        $run = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'cfb', 'run_type' => 'training', 'blend_version' => 'cfb-possession-v1', 'model_version' => 'cfb-possession-v1', 'feature_version' => 'cfb-scoring-v1',
            'config_hash' => hash('sha256', json_encode($manifest)), 'code_version' => 'cfb-possession-v1', 'status' => 'running', 'started_at' => now(), 'parameters' => ['manifest' => $manifest, 'retrospective' => (bool) $this->option('retrospective')]]);
        try {
            $command = [config('cfb.scoring.python', 'python3'), base_path('ml/cfb/scoring.py'), '--manifest', $this->option('manifest'), '--output', $this->option('output')];
            if ($this->option('retrospective')) {
                $command[] = '--retrospective';
            }
            $process = Process::timeout(7200)->run($command);
            if (! $process->successful()) {
                throw new \RuntimeException($process->errorOutput());
            }
            $json = file_get_contents($this->option('output'));
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $hash = hash('sha256', $json);
            $key = 'cfb/models/'.$hash.'.json';
            $disk = config('cfb.scoring.artifact_disk', 'local');
            if (! Storage::disk($disk)->put($key, $json)) {
                throw new \RuntimeException('Artifact archive failed');
            }
            $artifact = ModelArtifact::create(['training_run_id' => $run->id, 'sport' => 'cfb', 'market_type' => 'multi_market', 'model_type' => 'cfb_possession',
                'model_version' => 'cfb-possession-v1', 'feature_version' => 'cfb-scoring-v1', 'dataset_hash' => $manifest['sha256'], 'dataset_path' => $manifest['data_path'],
                'artifact_path' => $key, 'artifact_disk' => $disk, 'artifact_object_key' => $key, 'artifact_hash' => $hash, 'artifact_size' => strlen($json), 'artifact_content_type' => 'application/json',
                'status' => 'challenger', 'metrics' => ['holdout' => $payload['holdout'], 'promotion_report' => $payload['promotion_report']],
                'promotion_decision' => ['allowed' => false, 'reason' => 'requires_independent_validation_and_prospective_shadow']]);
            $run->update(['status' => 'completed', 'completed_at' => now(), 'artifact_path' => $key, 'artifact_hash' => $hash]);
            $this->line(json_encode(['artifact_id' => $artifact->id, 'sha256' => $hash, 'status' => 'challenger']));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $run->update(['status' => 'failed', 'completed_at' => now(), 'metadata' => ['error' => $error->getMessage()]]);
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
