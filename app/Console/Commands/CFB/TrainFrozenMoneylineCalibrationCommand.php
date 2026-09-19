<?php

namespace App\Console\Commands\CFB;

use App\Models\ModelArtifact;
use App\Services\CFB\Predictions\CfbCalculationReleaseDefinition;
use App\Services\CFB\Predictions\CfbFrozenMoneylineCalibrationDataset;
use App\Services\CFB\Predictions\CfbMoneylineTemporalCalibration;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TrainFrozenMoneylineCalibrationCommand extends Command
{
    protected $signature = 'cfb:train-frozen-moneyline-calibration {--release-version= : Defaults to current calculation release} {--output= : Optional JSON report}';

    protected $description = 'Reassess actual frozen CFB moneyline predictions with game-separated temporal holdouts';

    public function handle(CfbFrozenMoneylineCalibrationDataset $dataset, CfbMoneylineTemporalCalibration $trainer, ModelArtifactRegistry $registry, ModelRunRecorder $runs): int
    {
        $version = $this->option('release-version') ?: CfbCalculationReleaseDefinition::SEMANTIC_VERSION;
        $data = $dataset->rows($version);
        $report = count($data['configuration_hashes']) > 1
            ? ['status' => 'insufficient_evidence', 'reason' => 'mixed_release_configuration'] : $trainer->train($data['rows']);
        $report = [...$report, 'release_version' => $version, 'release_configuration_hash' => $data['configuration_hashes'][0] ?? null,
            'excluded' => $data['excluded'], 'trained_at' => now()->toIso8601String()];
        if ($report['status'] === 'challenger') {
            $jsonRows = json_encode($data['rows'], JSON_THROW_ON_ERROR);
            $hash = hash('sha256', $jsonRows);
            $existing = ModelArtifact::query()->where('sport', 'cfb')->where('model_version', CfbMoneylineTemporalCalibration::VERSION)
                ->where('dataset_hash', $hash)->first();
            if ($existing) {
                $report['artifact_id'] = $existing->id;
                $report['artifact_reused'] = true;
            } else {
                $id = $registry->newId();
                $dir = storage_path('app/cfb-moneyline-calibration/'.$id);
                File::ensureDirectoryExists($dir);
                File::put($dir.'/dataset.json', $jsonRows);
                File::put($dir.'/artifact.json', json_encode($report, JSON_THROW_ON_ERROR));
                $run = $runs->create(sport: 'cfb', runType: 'training', modelVersion: CfbMoneylineTemporalCalibration::VERSION,
                    featureVersion: 'frozen-cfb-moneyline-v1', blendVersion: 'shadow-only',
                    parameters: ['release_version' => $version, 'min_week' => 0, 'max_week' => 30],
                    metadata: ['point_in_time_basis' => 'immutable_pregame_snapshots']);
                $artifact = $registry->register(id: $id, trainingRun: $run, marketType: 'win_probability',
                    modelType: 'cfb_moneyline_platt_calibration', modelVersion: CfbMoneylineTemporalCalibration::VERSION,
                    featureVersion: 'frozen-cfb-moneyline-v1', datasetHash: $hash, artifactPath: $dir.'/artifact.json',
                    metrics: [...$report['reports'], 'validation_passed' => $report['validation_passed']], datasetPath: $dir.'/dataset.json');
                $report['artifact_id'] = $artifact->id;
            }
        }
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if ($path = $this->option('output')) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $json);
        }
        $this->line($json);

        return self::SUCCESS;
    }
}
