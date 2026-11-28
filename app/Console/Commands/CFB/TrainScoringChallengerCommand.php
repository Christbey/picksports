<?php

namespace App\Console\Commands\CFB;

use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Services\CFB\Scoring\CfbScoringArtifact;
use App\Services\CFB\Scoring\CfbScoringFeatures;
use App\Support\Json\JsonAllocationBudget;
use App\Support\Processes\InferenceProcessEnvironment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
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
        try {
            [$manifestJson, $manifest] = $this->readJsonFile((string) $this->option('manifest'), 2097152, 100000, 32, 'CFB training manifest JSON');
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        if (isset($manifest['scan']) && (! is_array($manifest['scan']) || ($manifest['scan']['complete'] ?? false) !== true
            || ($manifest['scan']['has_more'] ?? false) === true
            || (int) ($manifest['scan']['after_game_id'] ?? 0) !== 0)) {
            $this->error('Training requires a complete export; bounded shards require reviewed assembly.');

            return self::FAILURE;
        }
        if (! is_string($manifest['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $manifest['sha256']) !== 1
            || ! is_string($manifest['data_path'] ?? null) || $manifest['data_path'] === '' || strlen($manifest['data_path']) > 4096) {
            $this->error('CFB training manifest requires its dataset path and SHA-256.');

            return self::FAILURE;
        }
        $run = ModelRun::create(['id' => (string) Str::uuid(), 'sport' => 'cfb', 'run_type' => 'training', 'blend_version' => 'cfb-possession-v1', 'model_version' => 'cfb-possession-v1', 'feature_version' => CfbScoringFeatures::POLICY,
            'config_hash' => hash('sha256', json_encode($manifest)), 'code_version' => CfbScoringFeatures::POLICY, 'status' => 'running', 'started_at' => now(), 'parameters' => ['manifest' => $manifest, 'retrospective' => (bool) $this->option('retrospective')],
            'metadata' => ['input_manifest_sha256' => hash('sha256', $manifestJson)]]);
        $stagedManifest = null;
        try {
            $stagedManifest = $this->stageManifest($manifestJson);
            $command = [config('cfb.scoring.python', 'python3'), base_path('ml/cfb/scoring.py'), '--manifest', $stagedManifest, '--output', $this->option('output')];
            if ($this->option('retrospective')) {
                $command[] = '--retrospective';
            }
            $process = Process::env(InferenceProcessEnvironment::forPython(base_path('ml/cfb')))->timeout(7200)->run($command);
            if (! $process->successful()) {
                throw new \RuntimeException($process->errorOutput());
            }
            $stagedJson = $this->readFile($stagedManifest, 2097152, 'Staged CFB training manifest');
            if (! hash_equals(hash('sha256', $manifestJson), hash('sha256', $stagedJson))) {
                throw new \RuntimeException('Staged CFB training manifest changed during training.');
            }
            [$json, $payload] = $this->readJsonFile((string) $this->option('output'), CfbScoringArtifact::MAXIMUM_BYTES,
                CfbScoringArtifact::MAXIMUM_TOKENS, CfbScoringArtifact::MAXIMUM_DEPTH, 'CFB trainer artifact JSON');
            if (! is_string($payload['dataset_hash'] ?? null) || ! hash_equals($manifest['sha256'], $payload['dataset_hash'])) {
                throw new \RuntimeException('CFB trainer artifact does not match the admitted dataset hash.');
            }
            if (! is_array($payload['holdout'] ?? null) || ! is_array($payload['promotion_report'] ?? null)) {
                throw new \RuntimeException('CFB trainer artifact requires holdout and promotion reports.');
            }
            $hash = hash('sha256', $json);
            $key = 'cfb/models/'.$hash.'.json';
            $disk = config('cfb.scoring.artifact_disk', 'local');
            if (! Storage::disk($disk)->put($key, $json)) {
                throw new \RuntimeException('Artifact archive failed');
            }
            $artifact = ModelArtifact::create(['training_run_id' => $run->id, 'sport' => 'cfb', 'market_type' => 'multi_market', 'model_type' => 'cfb_possession',
                'model_version' => 'cfb-possession-v1', 'feature_version' => CfbScoringFeatures::POLICY, 'dataset_hash' => $manifest['sha256'], 'dataset_path' => $manifest['data_path'],
                'artifact_path' => $key, 'artifact_disk' => $disk, 'artifact_object_key' => $key, 'artifact_hash' => $hash, 'artifact_size' => strlen($json), 'artifact_content_type' => 'application/json',
                'status' => 'challenger', 'metrics' => ['holdout' => $payload['holdout'], 'promotion_report' => $payload['promotion_report']],
                'promotion_decision' => ['allowed' => false, 'reason' => 'requires_independent_validation_and_prospective_shadow']]);
            $run->update(['status' => 'completed', 'completed_at' => now(), 'artifact_path' => $key, 'artifact_hash' => $hash]);
            $this->line(json_encode(['artifact_id' => $artifact->id, 'sha256' => $hash, 'status' => 'challenger']));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $run->update(['status' => 'failed', 'completed_at' => now(), 'metadata' => ['input_manifest_sha256' => hash('sha256', $manifestJson), 'error' => mb_strcut($error->getMessage(), 0, 32768, 'UTF-8')]]);
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            if ($stagedManifest !== null) {
                @unlink($stagedManifest);
            }
        }
    }

    /** @return array{string, array<string, mixed>} */
    private function readJsonFile(string $path, int $maximumBytes, int $maximumTokens, int $depth, string $label): array
    {
        $raw = $this->readFile($path, $maximumBytes, $label);
        JsonAllocationBudget::inspect($raw, $maximumBytes, $maximumTokens, $depth, $label);
        $payload = json_decode($raw, true, $depth, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new \RuntimeException($label.' requires an object.');
        }

        return [$raw, $payload];
    }

    private function readFile(string $path, int $maximumBytes, string $label): string
    {
        if (! is_file($path) || ! is_resource($stream = fopen($path, 'rb'))) {
            throw new \RuntimeException($label.' file is unavailable.');
        }
        try {
            $raw = stream_get_contents($stream, $maximumBytes + 1);
        } finally {
            fclose($stream);
        }
        if (! is_string($raw)) {
            throw new \RuntimeException($label.' read failed.');
        }
        if (strlen($raw) > $maximumBytes) {
            throw new \RuntimeException($label.' exceeds the byte limit.');
        }

        return $raw;
    }

    private function stageManifest(string $raw): string
    {
        $directory = storage_path('app/private/cfb-training-inputs');
        File::ensureDirectoryExists($directory, 0700);
        $path = tempnam($directory, 'manifest-');
        if ($path === false) {
            throw new \RuntimeException('Unable to stage admitted CFB training manifest.');
        }
        try {
            if (dirname($path) !== realpath($directory) || file_put_contents($path, $raw, LOCK_EX) !== strlen($raw) || ! chmod($path, 0400)) {
                throw new \RuntimeException('Unable to protect admitted CFB training manifest.');
            }

            return $path;
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
    }
}
