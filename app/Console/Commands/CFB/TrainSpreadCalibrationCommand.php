<?php

namespace App\Console\Commands\CFB;

use App\Services\CFB\Predictions\CfbSpreadCalibrationDataset;
use App\Services\CFB\Predictions\CfbSpreadResidualCalibration;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TrainSpreadCalibrationCommand extends Command
{
    protected $signature = 'cfb:train-spread-calibration {--release-version= : Exact immutable calculation release} {--output= : Optional JSON report path}';

    protected $description = 'Evaluate frozen pregame spread calibration with chronological holdouts; register challengers only';

    public function handle(CfbSpreadCalibrationDataset $dataset, CfbSpreadResidualCalibration $trainer, ModelArtifactRegistry $registry, ModelRunRecorder $runs): int
    {
        $version = trim((string) $this->option('release-version'));
        if ($version === '') {
            $this->error('An explicit --release-version is required.');

            return self::FAILURE;
        }
        $data = $dataset->rows($version);
        $report = count($data['configuration_hashes']) > 1
            ? ['status' => 'insufficient_evidence', 'reason' => 'mixed_release_configuration']
            : $trainer->train($data['rows']);
        $report['release_version'] = $version;
        $report['release_configuration_hash'] = $data['configuration_hashes'][0] ?? null;
        $report['excluded'] = $data['excluded'];
        $report['trained_at'] = now()->toIso8601String();
        if ($report['status'] === 'challenger') {
            $id = $registry->newId();
            $dir = storage_path('app/cfb-spread-calibration/'.$id);
            File::ensureDirectoryExists($dir);
            File::put($dir.'/dataset.json', json_encode($data['rows'], JSON_THROW_ON_ERROR));
            File::put($dir.'/artifact.json', json_encode($report, JSON_THROW_ON_ERROR));
            $run = $runs->create(sport: 'cfb', runType: 'training', modelVersion: CfbSpreadResidualCalibration::VERSION,
                featureVersion: 'frozen-cfb-margin-v1', blendVersion: 'shadow-only',
                parameters: ['release_version' => $version], metadata: ['point_in_time_basis' => 'immutable_pregame_snapshots']);
            $artifact = $registry->register(id: $id, trainingRun: $run, marketType: 'spread',
                modelType: 'empirical_residual_distribution', modelVersion: CfbSpreadResidualCalibration::VERSION,
                featureVersion: 'frozen-cfb-margin-v1', datasetHash: hash_file('sha256', $dir.'/dataset.json'),
                artifactPath: $dir.'/artifact.json', metrics: ['validation_passed' => $report['validation_passed'],
                    'counts' => $report['counts'], 'reports' => $report['reports']], datasetPath: $dir.'/dataset.json');
            $report['artifact_id'] = $artifact->id;
        }
        unset($report['residuals']);
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if ($path = $this->option('output')) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $json);
        }
        $this->line($json);

        return self::SUCCESS;
    }
}
