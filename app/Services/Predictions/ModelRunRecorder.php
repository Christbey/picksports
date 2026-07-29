<?php

namespace App\Services\Predictions;

use App\Models\ModelRun;
use Illuminate\Support\Str;

class ModelRunRecorder
{
    /**
     * @var array<string, ModelRun>
     */
    private array $runs = [];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function forPrediction(
        string $sport,
        string $modelVersion,
        string $featureVersion,
        string $blendVersion,
        array $metadata = [],
    ): ModelRun {
        $config = $this->canonicalize((array) config($sport, []));
        $configHash = hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));
        $runType = (string) ($metadata['run_type'] ?? 'prediction');
        $cacheKey = implode('|', [$sport, $runType, $modelVersion, $featureVersion, $blendVersion, $configHash]);

        return $this->runs[$cacheKey] ??= $this->create(
            sport: $sport,
            runType: $runType,
            modelVersion: $modelVersion,
            featureVersion: $featureVersion,
            blendVersion: $blendVersion,
            parameters: $metadata['parameters'] ?? null,
            metadata: $metadata,
            status: 'completed',
            completedAt: now(),
            configHash: $configHash,
        );
    }

    /**
     * @param  array<string, mixed>|null  $parameters
     * @param  array<string, mixed>|null  $metadata
     */
    public function create(
        string $sport,
        string $runType,
        string $modelVersion,
        string $featureVersion,
        string $blendVersion,
        ?array $parameters = null,
        ?array $metadata = null,
        string $status = 'running',
        mixed $completedAt = null,
        ?string $configHash = null,
    ): ModelRun {
        return ModelRun::query()->create([
            'id' => (string) Str::uuid(),
            'sport' => $sport,
            'run_type' => $runType,
            'model_version' => $modelVersion,
            'feature_version' => $featureVersion,
            'blend_version' => $blendVersion,
            'config_hash' => $configHash ?? $this->configHash($sport),
            'code_version' => $this->codeVersion(),
            'parameters' => $parameters,
            'status' => $status,
            'started_at' => now(),
            'completed_at' => $completedAt,
            'metadata' => $metadata,
        ]);
    }

    public function configHash(string $sport): string
    {
        $config = $this->canonicalize((array) config($sport, []));

        return hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));
    }

    public function codeVersion(): ?string
    {
        foreach (['SOURCE_VERSION', 'APP_COMMIT_SHA', 'GIT_COMMIT'] as $key) {
            $value = env($key);
            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), 64, '');
            }
        }

        $gitDirectory = base_path('.git');
        if (is_file($gitDirectory)) {
            $gitFile = trim((string) @file_get_contents($gitDirectory));
            if (str_starts_with($gitFile, 'gitdir:')) {
                $gitDirectory = trim(substr($gitFile, 7));
                if (! str_starts_with($gitDirectory, DIRECTORY_SEPARATOR)) {
                    $gitDirectory = base_path($gitDirectory);
                }
            }
        }

        $head = trim((string) @file_get_contents($gitDirectory.'/HEAD'));
        if (preg_match('/^[a-f0-9]{40}$/i', $head) === 1) {
            return $head;
        }

        if (! str_starts_with($head, 'ref: ')) {
            return null;
        }

        $reference = substr($head, 5);
        $commit = trim((string) @file_get_contents($gitDirectory.'/'.$reference));
        if (preg_match('/^[a-f0-9]{40}$/i', $commit) === 1) {
            return $commit;
        }

        $packedRefs = (string) @file_get_contents($gitDirectory.'/packed-refs');
        if (preg_match('/^([a-f0-9]{40})\s+'.preg_quote($reference, '/').'$/mi', $packedRefs, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
