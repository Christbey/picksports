<?php

namespace App\Services\MLB;

use App\Models\ModelArtifact;
use App\Services\ML\ModelArtifactRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class MlbPeriodModelInferenceService
{
    public function __construct(private readonly ModelArtifactRegistry $artifacts) {}

    /**
     * @param  array<string, array<string, float|int|null>>  $featuresByMarket
     * @return list<array<string, mixed>>
     */
    public function predict(ModelArtifact $artifact, array $featuresByMarket): array
    {
        if ($artifact->sport !== 'mlb'
            || $artifact->model_type !== 'mlb_period_bundle'
            || ! in_array($artifact->status, ['challenger', 'promotion_eligible', 'promoted'], true)) {
            throw new RuntimeException('Inference requires an active MLB period bundle artifact.');
        }
        $rows = [];
        foreach ($featuresByMarket as $market => $features) {
            $rows[] = ['market_type' => $market, ...$features];
        }
        $directory = storage_path('app/ml/mlb-period/inputs');
        File::ensureDirectoryExists($directory, 0700, true);
        $input = $directory.'/'.Str::uuid().'.json';
        File::put($input, json_encode($rows, JSON_THROW_ON_ERROR), true);
        @chmod($input, 0600);

        try {
            $command = (array) config('mlb_ml.process.command');
            $process = new Process([
                ...$command,
                'predict-period',
                '--bundle',
                $this->artifacts->materializeArtifact($artifact),
                '--input',
                $input,
            ], (string) config('mlb_ml.process.package_directory'));
            $process->setEnv(['PYTHONPATH' => base_path('ml/mlb/src')]);
            $process->setTimeout((float) config('mlb_ml.period_models.inference_timeout_seconds', 30));
            $process->mustRun();
            $outputs = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($outputs) || ! array_is_list($outputs)) {
                throw new RuntimeException('MLB period inference must return a JSON list.');
            }
            foreach ($outputs as $output) {
                $this->validateOutput($artifact, $output);
            }

            return $outputs;
        } finally {
            File::delete($input);
        }
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function validateOutput(ModelArtifact $artifact, array $output): void
    {
        foreach ([
            'home_win_probability',
            'away_win_probability',
            'tie_probability',
            'conditional_home_win_probability',
            'conditional_away_win_probability',
            'uncertainty',
        ] as $field) {
            if (! is_numeric($output[$field] ?? null)
                || (float) $output[$field] < 0
                || (float) $output[$field] > 1) {
                throw new RuntimeException("Invalid MLB period probability: {$field}");
            }
        }
        $sum = (float) $output['home_win_probability']
            + (float) $output['away_win_probability']
            + (float) $output['tie_probability'];
        if (abs($sum - 1.0) > 0.00001) {
            throw new RuntimeException('MLB period outcome probabilities must sum to one.');
        }
        if (! hash_equals((string) $artifact->id, (string) ($output['artifact_id'] ?? ''))
            || ! hash_equals((string) $artifact->dataset_hash, (string) ($output['dataset_hash'] ?? ''))
            || ! hash_equals(
                (string) data_get($artifact->trainingRun?->metadata, 'python_model_run_id'),
                (string) ($output['model_run_id'] ?? ''),
            )) {
            throw new RuntimeException('MLB period inference failed lineage validation.');
        }
    }
}
