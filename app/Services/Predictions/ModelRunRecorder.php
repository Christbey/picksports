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
        ?array $preparedContext = null,
    ): ModelRun {
        $config = $this->canonicalize((array) config($sport, []));
        $configHash = hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));
        if ($preparedContext !== null) {
            $this->assertPreparedContext($sport, $configHash, $preparedContext);
        }
        $runType = (string) ($metadata['run_type'] ?? 'prediction');
        $parameters = $metadata['parameters'] ?? null;
        $cacheKey = hash('sha256', json_encode($this->cacheIdentity([
            $sport, $runType, $modelVersion, $featureVersion, $blendVersion, $configHash, $parameters,
        ]), JSON_THROW_ON_ERROR));
        $cached = $this->runs[$cacheKey] ?? null;
        if ($cached !== null) {
            // A command-scoped recorder can outlive a rolled-back transaction.
            // Check the parent on the writer connection before reusing its ID.
            if ($cached->getConnection() === (new ModelRun)->getConnection()
                && ModelRun::query()->useWritePdo()->whereKey($cached->getKey())->first(['id']) !== null) {
                return $cached;
            }
            unset($this->runs[$cacheKey]);
        }

        return $this->runs[$cacheKey] = $this->create(
            sport: $sport,
            runType: $runType,
            modelVersion: $modelVersion,
            featureVersion: $featureVersion,
            blendVersion: $blendVersion,
            parameters: $parameters,
            metadata: $metadata,
            status: 'completed',
            completedAt: now(),
            configHash: $configHash,
            preparedContext: $preparedContext,
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
        ?array $preparedContext = null,
    ): ModelRun {
        if ($preparedContext !== null) {
            $this->assertPreparedContext($sport, $configHash ?? $this->configHash($sport), $preparedContext);
        }

        return ModelRun::query()->create([
            'id' => (string) Str::uuid(),
            'sport' => $sport,
            'run_type' => $runType,
            'model_version' => $modelVersion,
            'feature_version' => $featureVersion,
            'blend_version' => $blendVersion,
            'config_hash' => $configHash ?? $this->configHash($sport),
            'code_version' => $preparedContext !== null ? $preparedContext['code_version'] : $this->codeVersion(),
            'parameters' => $parameters,
            'status' => $status,
            'started_at' => now(),
            'completed_at' => $completedAt,
            'metadata' => $metadata,
        ]);
    }

    /** Stage only descriptive run metadata; this does not confer input or model authority. */
    public function preparePredictionContext(string $sport): array
    {
        return ['sport' => $sport, 'config_hash' => $this->configHash($sport), 'code_version' => $this->codeVersion()];
    }

    private function assertPreparedContext(string $sport, string $configHash, array $context): void
    {
        if (($context['sport'] ?? null) !== $sport || ($context['config_hash'] ?? null) !== $configHash
            || $configHash !== $this->configHash($sport) || ! array_key_exists('code_version', $context)
            || ($context['code_version'] !== null && (! is_string($context['code_version']) || strlen($context['code_version']) > 64))) {
            throw new \InvalidArgumentException('Prepared prediction run context changed; retry the complete calculation.');
        }
    }

    public function configHash(string $sport): string
    {
        $config = $this->canonicalize((array) config($sport, []));

        return hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));
    }

    public function codeVersion(): ?string
    {
        $version = config('app.code_version');
        if (is_string($version) && trim($version) !== '') {
            return Str::limit(trim($version), 64, '');
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

    /** Preserve list/map shape for cache identity without changing published config hashes. */
    private function cacheIdentity(mixed $value): array
    {
        if (! is_array($value)) {
            return ['value', $value];
        }
        if (array_is_list($value)) {
            return ['list', array_map(fn (mixed $item): array => $this->cacheIdentity($item), $value)];
        }
        $keys = array_keys($value);
        $keyIdentity = static fn (int|string $key): string => (is_int($key) ? 'integer:' : 'string:').$key;
        usort($keys, static fn (int|string $left, int|string $right): int => strcmp($keyIdentity($left), $keyIdentity($right)));
        $entries = [];
        foreach ($keys as $key) {
            $entries[] = [is_int($key) ? 'integer' : 'string', $key, $this->cacheIdentity($value[$key])];
        }

        return ['map', $entries];
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
