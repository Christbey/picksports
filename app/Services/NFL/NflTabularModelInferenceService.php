<?php

namespace App\Services\NFL;

use App\Models\ModelArtifact;
use App\Services\ML\ModelArtifactRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

class NflTabularModelInferenceService
{
    /**
     * @var list<string>
     */
    private const OUTPUT_FIELDS = [
        'home_win_probability',
        'expected_home_margin',
        'expected_total',
        'home_cover_probability',
        'over_probability',
        'uncertainty',
        'model_run_id',
        'artifact_id',
        'dataset_hash',
        'feature_hash',
    ];

    public function __construct(
        private readonly ModelArtifactRegistry $artifacts,
        private readonly NflTabularModelBundle $bundles,
    ) {}

    /**
     * @param  array<string, int|float|null>  $features
     * @return array{
     *     home_win_probability: float,
     *     expected_home_margin: float,
     *     expected_total: float,
     *     home_cover_probability: float|null,
     *     over_probability: float|null,
     *     uncertainty: float,
     *     model_run_id: string,
     *     artifact_id: string,
     *     dataset_hash: string,
     *     feature_hash: string
     * }
     */
    public function predict(ModelArtifact $artifact, array $features): array
    {
        $this->assertRegisteredArtifact($artifact);
        $bundlePath = $this->artifacts->materializeArtifact($artifact);
        $runDirectory = $this->bundles->extractAndVerify($artifact->refresh(), $bundlePath);
        $inputDirectory = rtrim((string) config(
            'nfl_ml.bundle.input_directory',
            storage_path('app/ml/nfl-tabular/inputs'),
        ), '/');
        File::ensureDirectoryExists($inputDirectory, 0700, true);
        $inputPath = $inputDirectory.'/'.Str::uuid().'.json';

        try {
            File::put(
                $inputPath,
                json_encode($this->validatedFeatures($features), JSON_THROW_ON_ERROR),
                true,
            );
            @chmod($inputPath, 0600);

            $command = config('nfl_ml.process.command');
            if (! is_array($command)
                || $command === []
                || collect($command)->contains(fn (mixed $argument): bool => ! is_string($argument) || $argument === '')) {
                throw new RuntimeException('NFL tabular model process command is not configured.');
            }

            $process = new Process([
                ...array_values($command),
                'predict',
                '--run-dir',
                $runDirectory,
                '--input',
                $inputPath,
            ]);
            $process->setTimeout((float) config('nfl_ml.process.timeout_seconds', 30));
            $process->run();

            if (! $process->isSuccessful()) {
                $error = trim($process->getErrorOutput());
                throw new RuntimeException('NFL tabular model inference failed'.($error !== '' ? ': '.$error : '.'));
            }

            $outputs = $this->decodeOutput($process->getOutput());
            if (count($outputs) !== 1 || ! is_array($outputs[0])) {
                throw new RuntimeException('NFL tabular model inference must return exactly one output row.');
            }

            return $this->validatedOutput($outputs[0], $artifact->refresh());
        } finally {
            File::delete($inputPath);
        }
    }

    private function assertRegisteredArtifact(ModelArtifact $artifact): void
    {
        if (! $artifact->exists
            || $artifact->sport !== 'nfl'
            || $artifact->model_type !== 'nfl_tabular_bundle'
            || $artifact->training_run_id === null) {
            throw new RuntimeException('Inference requires a registered NFL tabular bundle artifact.');
        }
    }

    /**
     * @param  array<string, mixed>  $features
     * @return array<string, int|float|null>
     */
    private function validatedFeatures(array $features): array
    {
        if ($features === [] || array_is_list($features)) {
            throw new RuntimeException('NFL tabular inference features must be a non-empty JSON object.');
        }

        foreach ($features as $name => $value) {
            if (! is_string($name)
                || $name === ''
                || (! is_int($value) && ! is_float($value) && $value !== null)
                || (is_float($value) && ! is_finite($value))) {
                throw new RuntimeException("NFL tabular inference feature [{$name}] is not numeric or null.");
            }
        }

        ksort($features);

        return $features;
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeOutput(string $output): array
    {
        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('NFL tabular model inference returned invalid JSON.', previous: $exception);
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException('NFL tabular model inference output must be a JSON array.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function validatedOutput(array $output, ModelArtifact $artifact): array
    {
        $keys = array_keys($output);
        sort($keys);
        $expectedKeys = self::OUTPUT_FIELDS;
        sort($expectedKeys);
        if ($keys !== $expectedKeys) {
            throw new RuntimeException('NFL tabular model inference output does not match the model contract.');
        }

        foreach (['home_win_probability', 'uncertainty'] as $field) {
            $this->assertProbability($output[$field], $field, false);
        }
        foreach (['home_cover_probability', 'over_probability'] as $field) {
            $this->assertProbability($output[$field], $field, true);
        }
        foreach (['expected_home_margin', 'expected_total'] as $field) {
            if (! is_int($output[$field]) && ! is_float($output[$field])) {
                throw new RuntimeException("NFL tabular model output [{$field}] must be numeric.");
            }
            if (! is_finite((float) $output[$field])) {
                throw new RuntimeException("NFL tabular model output [{$field}] must be finite.");
            }
            $output[$field] = (float) $output[$field];
        }

        $lineage = [
            'model_run_id' => (string) data_get($artifact->trainingRun?->metadata, 'python_model_run_id', ''),
            'artifact_id' => (string) $artifact->id,
            'dataset_hash' => (string) $artifact->dataset_hash,
        ];
        foreach ($lineage as $field => $expected) {
            if (! is_string($output[$field]) || ! hash_equals($expected, $output[$field])) {
                throw new RuntimeException("NFL tabular model output [{$field}] failed lineage verification.");
            }
        }

        if (! is_string($output['feature_hash'])
            || preg_match('/^[a-f0-9]{64}$/', $output['feature_hash']) !== 1) {
            throw new RuntimeException('NFL tabular model output [feature_hash] is not a SHA-256 hash.');
        }

        return array_replace(array_flip(self::OUTPUT_FIELDS), $output);
    }

    private function assertProbability(mixed &$value, string $field, bool $nullable): void
    {
        if ($nullable && $value === null) {
            return;
        }
        if ((! is_int($value) && ! is_float($value))
            || ! is_finite((float) $value)
            || (float) $value < 0
            || (float) $value > 1) {
            throw new RuntimeException("NFL tabular model output [{$field}] must be a probability.");
        }

        $value = (float) $value;
    }
}
