<?php

use App\Actions\CFB\GenerateCanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\ModelArtifact;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Models\SportEvent;
use App\Services\CFB\Predictions\CfbCalculationReleaseRegistrar;
use App\Services\CFB\Predictions\CfbMoneylineCalibrationDataset;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('cfb-ml-artifacts');
    Storage::fake('cfb-ml-cache');
    config()->set('ml.storage.disk', 'cfb-ml-artifacts');
    config()->set('ml.storage.cache_disk', 'cfb-ml-cache');
    config()->set('filesystems.disks.cfb-ml-artifacts.driver', 'local');
});

it('reconstructs the exact pregame elo and never uses the result as a feature', function () {
    $dataset = app(CfbMoneylineCalibrationDataset::class);
    $source = cfbCalibrationSourceRow();
    $homeWin = $dataset->reconstruct($source);
    $awayWin = $dataset->reconstruct([
        ...$source,
        'home_score' => 14,
        'away_score' => 31,
    ]);

    expect($homeWin['home_pregame_elo'])->toBe(1600.0)
        ->and($homeWin['away_pregame_elo'])->toBe(1500.0)
        ->and($homeWin['feature_model_win_probability'])->toBe($awayWin['feature_model_win_probability'])
        ->and($homeWin['feature_model_predicted_home_margin'])->toBe($awayWin['feature_model_predicted_home_margin'])
        ->and($homeWin['target_home_win'])->toBe(1)
        ->and($awayWin['target_home_win'])->toBe(0)
        ->and($homeWin['home_metric_season'])->toBe(2024)
        ->and($homeWin['availability_status'])->toBe('verified_reconstruction');
});

it('trains with season boundaries and registers only a challenger', function () {
    $rows = [];
    foreach (range(2022, 2025) as $season) {
        foreach (range(1, 8) as $index) {
            $homeWin = $index % 2 === 0;
            $rows[] = [
                'game_id' => (($season - 2020) * 100) + $index,
                'season' => $season,
                'week' => ($index % 4) + 1,
                'game_date' => "{$season}-09-".str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'reconstruction_profile' => CfbMoneylineCalibrationDataset::FEATURE_VERSION,
                'pregame_safe' => true,
                'availability_status' => 'verified_reconstruction',
                'feature_model_win_probability' => $homeWin ? 0.90 : 0.70,
                'target_home_win' => $homeWin ? 1 : 0,
            ];
        }
    }

    $dataset = Mockery::mock(CfbMoneylineCalibrationDataset::class);
    $dataset->shouldReceive('rows')->once()->with(2022, 2025, 0, 4)->andReturn($rows);
    app()->instance(CfbMoneylineCalibrationDataset::class, $dataset);

    $datasetPath = storage_path('framework/testing/cfb_moneyline_calibration.csv');
    $artifactPath = storage_path('framework/testing/cfb_moneyline_calibration.json');
    $reportPath = storage_path('framework/testing/cfb_moneyline_calibration_report.json');

    $exitCode = Artisan::call('cfb:train-moneyline-calibration', [
        '--dataset' => $datasetPath,
        '--output' => $artifactPath,
        '--report' => $reportPath,
        '--iterations' => 200,
        '--min-rows' => 20,
    ]);

    $artifact = ModelArtifact::query()->where('sport', 'cfb')->firstOrFail();
    $model = json_decode((string) file_get_contents($artifactPath), true, flags: JSON_THROW_ON_ERROR);
    $report = json_decode((string) file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('held-out test season: 2025')
        ->toContain('Status: challenger (not active)')
        ->and($artifact->status)->toBe('challenger')
        ->and($artifact->market_type)->toBe('win_probability')
        ->and($artifact->dataset_hash)->toBe(hash_file('sha256', $datasetPath))
        ->and($model['training_seasons'])->toBe([2022, 2023, 2024])
        ->and($model['validation_season'])->toBe(2024)
        ->and($model['test_season'])->toBe(2025)
        ->and($model['metrics']['validation']['count'])->toBe(8)
        ->and($model['metrics']['test']['count'])->toBe(8)
        ->and($report['summary']['window_count'])->toBe(3)
        ->and(array_column($report['windows'], 'evaluation_season'))->toBe([2023, 2024, 2025]);
});

it('refuses to train without four independent seasons', function () {
    $dataset = Mockery::mock(CfbMoneylineCalibrationDataset::class);
    $dataset->shouldReceive('rows')->once()->andReturn([
        ['season' => 2023],
        ['season' => 2024],
        ['season' => 2025],
    ]);
    app()->instance(CfbMoneylineCalibrationDataset::class, $dataset);

    $exitCode = Artisan::call('cfb:train-moneyline-calibration', ['--min-rows' => 1]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('across four seasons')
        ->and(ModelArtifact::query()->count())->toBe(0);
});

it('records an observed canonical shadow without changing the published probability', function () {
    $startsAt = now()->addDay()->startOfHour();
    $event = SportEvent::factory()->create([
        'sport' => 'cfb',
        'season' => 2026,
        'season_type' => 'regular',
        'week' => 1,
        'starts_at' => $startsAt,
        'status' => 'STATUS_SCHEDULED',
        'neutral_site' => false,
    ]);
    $home = Team::factory()->create(['elo_rating' => 1600]);
    $away = Team::factory()->create(['elo_rating' => 1450]);

    foreach ([[$home, 31, 20, 8], [$away, 21, 29, -6]] as [$team, $scored, $allowed, $power]) {
        TeamMetric::query()->create([
            'team_id' => $team->id,
            'season' => 2026,
            'wins' => 8,
            'losses' => 4,
            'points_per_game' => $scored,
            'points_allowed_per_game' => $allowed,
            'turnover_differential' => 1,
            'recent_form_rating' => 0,
            'injury_adjusted_team_rating' => $team->elo_rating,
            'rest_travel_fatigue' => 0,
            'power_rating' => $power,
            'fpi' => $power,
            'calculation_date' => now()->toDateString(),
        ]);
    }

    $game = Game::factory()->create([
        'sport_event_id' => $event->id,
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'season_type' => 'regular',
        'week' => 1,
        'game_date' => $startsAt,
        'game_time' => $startsAt->format('H:i:s'),
        'status' => 'STATUS_SCHEDULED',
        'home_score' => null,
        'away_score' => null,
    ]);
    app(CfbCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($game);
    $publishedProbability = (float) $prediction->markets
        ->where('market_type', 'moneyline')
        ->where('selection', 'home')
        ->sole()
        ->probability;

    $trainingRun = app(ModelRunRecorder::class)->create(
        sport: 'cfb',
        runType: 'training',
        modelVersion: 'cfb-moneyline-platt-v1',
        featureVersion: CfbMoneylineCalibrationDataset::FEATURE_VERSION,
        blendVersion: 'challenger-shadow-v1',
        parameters: ['min_week' => 0, 'max_week' => 4],
    );
    $sourcePath = storage_path('framework/testing/cfb_shadow_artifact.json');
    File::ensureDirectoryExists(dirname($sourcePath));
    File::put($sourcePath, json_encode([
        'model_type' => 'cfb_moneyline_platt_calibration',
        'alpha' => 0.8,
        'beta' => 0.2,
    ], JSON_THROW_ON_ERROR));
    $artifact = app(ModelArtifactRegistry::class)->register(
        id: app(ModelArtifactRegistry::class)->newId(),
        trainingRun: $trainingRun,
        marketType: 'win_probability',
        modelType: 'cfb_moneyline_platt_calibration',
        modelVersion: 'cfb-moneyline-platt-v1',
        featureVersion: CfbMoneylineCalibrationDataset::FEATURE_VERSION,
        datasetHash: hash('sha256', 'test-dataset'),
        artifactPath: $sourcePath,
        metrics: [],
    );
    $artifact->update(['status' => 'promotion_eligible']);

    $firstExitCode = Artisan::call('cfb:record-moneyline-calibration-shadow', [
        '--artifact' => $artifact->id,
        '--season' => 2026,
        '--week' => 1,
    ]);
    $secondExitCode = Artisan::call('cfb:record-moneyline-calibration-shadow', [
        '--artifact' => $artifact->id,
        '--season' => 2026,
        '--week' => 1,
    ]);
    $snapshot = PredictionFeatureSnapshot::query()->sole();
    $shadow = ShadowModelOutput::query()->sole();

    expect($firstExitCode)->toBe(0)
        ->and($secondExitCode)->toBe(0)
        ->and($snapshot->prediction_table)->toBe('predictions')
        ->and($snapshot->prediction_id)->toBe($prediction->id)
        ->and($snapshot->pregame_safe)->toBeTrue()
        ->and($snapshot->availability_status)->toBe('observed_pregame')
        ->and(data_get($snapshot->lineage_metadata, 'event_input_snapshot_id'))->toBe($prediction->calculationRun->inputSnapshot->id)
        ->and($shadow->model_artifact_id)->toBe($artifact->id)
        ->and($shadow->baseline_output)->toBe($publishedProbability)
        ->and($shadow->challenger_output)->not->toBe($publishedProbability)
        ->and((float) $prediction->fresh()->markets
            ->where('market_type', 'moneyline')
            ->where('selection', 'home')
            ->sole()
            ->probability)->toBe($publishedProbability)
        ->and(PredictionFeatureSnapshot::query()->count())->toBe(1)
        ->and(ShadowModelOutput::query()->count())->toBe(1);

    $outsideScope = Artisan::call('cfb:record-moneyline-calibration-shadow', [
        '--artifact' => $artifact->id,
        '--season' => 2026,
        '--week' => 5,
    ]);

    expect($outsideScope)->toBe(1)
        ->and(Artisan::output())->toContain('calibration scope is weeks 0-4');
});

/** @return array<string, mixed> */
function cfbCalibrationSourceRow(): array
{
    $metrics = [
        'wins' => 8,
        'losses' => 4,
        'points_per_game' => 31.0,
        'points_allowed_per_game' => 22.0,
        'turnover_differential' => 3.0,
        'recent_form_rating' => 0.2,
        'injury_adjusted_team_rating' => null,
        'rest_travel_fatigue' => 0.0,
        'power_rating' => 10.0,
        'fpi' => 9.0,
    ];

    return [
        'game_id' => 10,
        'season' => 2025,
        'week' => 1,
        'game_date' => '2025-09-01',
        'game_time' => '12:00:00',
        'home_team_id' => 1,
        'away_team_id' => 2,
        'home_score' => 31,
        'away_score' => 14,
        'neutral_site' => false,
        'home_postgame_elo' => 1612.5,
        'home_elo_change' => 12.5,
        'away_postgame_elo' => 1487.5,
        'away_elo_change' => -12.5,
        'home_metric_season' => 2024,
        'away_metric_season' => 2024,
        ...collect($metrics)->mapWithKeys(fn ($value, string $key): array => ["home_{$key}" => $value])->all(),
        ...collect($metrics)->mapWithKeys(fn ($value, string $key): array => ["away_{$key}" => $value])->all(),
    ];
}
