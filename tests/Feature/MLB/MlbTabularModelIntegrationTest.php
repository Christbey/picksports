<?php

use App\Models\BetDecision;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\ML\ShadowArtifactSelector;
use App\Services\MLB\MlbChallengerShadowService;
use App\Services\MLB\MlbShadowFeatureBuilder;
use App\Services\MLB\MlbTabularModelBundle;
use App\Services\MLB\MlbTabularModelInferenceService;
use App\Services\MLB\MlbTabularModelRunRegistrar;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('mlb-tabular-artifacts');
    Storage::fake('mlb-tabular-cache');

    config()->set('ml.storage.disk', 'mlb-tabular-artifacts');
    config()->set('ml.storage.cache_disk', 'mlb-tabular-cache');
    config()->set('ml.storage.prefix', 'ml');
    config()->set('filesystems.disks.mlb-tabular-artifacts.driver', 'local');

    $this->mlbMlTestRoot = storage_path('framework/testing/mlb-tabular-'.Str::uuid());
    config()->set('mlb_ml.bundle.staging_directory', $this->mlbMlTestRoot.'/staging');
    config()->set('mlb_ml.bundle.extraction_directory', $this->mlbMlTestRoot.'/extracted');
    config()->set('mlb_ml.bundle.input_directory', $this->mlbMlTestRoot.'/inputs');
});

afterEach(function () {
    Carbon::setTestNow();
    File::deleteDirectory($this->mlbMlTestRoot);
});

it('registers and invokes a verified Python MLB bundle with the finalized contract', function () {
    $fixture = mlbTabularRunFixture($this->mlbMlTestRoot.'/run');

    $this->artisan('mlb:register-tabular-model-run', [
        'run-directory' => $fixture['run_directory'],
        '--dataset' => $fixture['dataset_path'],
    ])
        ->expectsOutputToContain('MLB tabular model run registered.')
        ->expectsOutputToContain('Model run: '.$fixture['model_run_id'])
        ->expectsOutputToContain('Artifact: '.$fixture['artifact_id'])
        ->assertSuccessful();

    $artifact = ModelArtifact::query()->findOrFail($fixture['artifact_id']);
    $run = ModelRun::query()->findOrFail($fixture['model_run_id']);

    expect($artifact->sport)->toBe('mlb')
        ->and($artifact->model_type)->toBe('mlb_tabular_bundle')
        ->and($artifact->market_type)->toBe('multi_market')
        ->and($artifact->dataset_hash)->toBe($fixture['dataset_hash'])
        ->and($artifact->artifact_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($artifact->artifact_content_type)->toBe('application/zip')
        ->and($run->status)->toBe('completed')
        ->and($run->config_hash)->toBe($fixture['config_hash'])
        ->and(data_get($run->metadata, 'bundle_manifest.dataset_entry'))->toBe('dataset/training-data.csv')
        ->and(data_get($run->metadata, 'inference_alias_path'))->toBeNull();

    $bundlePath = app(ModelArtifactRegistry::class)->materializeArtifact($artifact);
    $zip = new ZipArchive;
    expect($zip->open($bundlePath, ZipArchive::RDONLY))->toBeTrue()
        ->and($zip->locateName('models/xgboost_total_points.ubj'))->not->toBeFalse()
        ->and($zip->locateName('dataset/training-data.csv'))->not->toBeFalse();
    $zip->close();
    $runtimePath = app(MlbTabularModelBundle::class)->extractAndVerify($artifact, $bundlePath);

    expect(File::isFile($runtimePath.'/manifest.json'))->toBeTrue()
        ->and(File::isFile($runtimePath.'/bundle.json'))->toBeFalse()
        ->and(File::isDirectory($runtimePath.'/dataset'))->toBeFalse()
        ->and(File::isFile(dirname($runtimePath).'/dataset/training-data.csv'))->toBeTrue();

    $cli = mlbTabularFakeCli($this->mlbMlTestRoot.'/fake-cli.php');
    config()->set('mlb_ml.process.command', [PHP_BINARY, $cli]);
    $output = app(MlbTabularModelInferenceService::class)->predict($artifact, [
        'feature_home_team_elo' => 1532,
        'feature_away_team_elo' => 1498,
        'feature_home_pitcher_elo' => 1511,
        'feature_away_pitcher_elo' => 1487,
    ]);

    expect($output)->toHaveKeys([
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
    ])
        ->and($output['home_win_probability'])->toBe(0.614)
        ->and($output['expected_home_margin'])->toBe(1.3)
        ->and($output['expected_total'])->toBe(8.7)
        ->and($output['home_cover_probability'])->toBe(0.558)
        ->and($output['over_probability'])->toBe(0.472)
        ->and($output['model_run_id'])->toBe($fixture['model_run_id'])
        ->and($output['artifact_id'])->toBe($fixture['artifact_id'])
        ->and($output['dataset_hash'])->toBe($fixture['dataset_hash']);
});

it('records canonical MLB shadow outputs and tracking-only no-bet decisions', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    $fixture = mlbTabularRunFixture($this->mlbMlTestRoot.'/shadow-run');
    $artifact = app(MlbTabularModelRunRegistrar::class)->register($fixture['run_directory']);
    config()->set('mlb_ml.process.command', [PHP_BINARY, mlbTabularFakeCli($this->mlbMlTestRoot.'/shadow-cli.php')]);
    config()->set('mlb_ml.shadow.enabled', true);
    config()->set('mlb_ml.shadow.artifact_id', $artifact->id);

    $homeSnapshot = mlbCanonicalSnapshot(
        marketHomeMargin: 1.5,
        homeTeamElo: 1532,
    );
    $awaySnapshot = mlbCanonicalSnapshot(
        marketHomeMargin: 1.5,
        homeTeamElo: 1400,
    );
    expect(app(MlbShadowFeatureBuilder::class)->build($homeSnapshot)['feature_market_home_spread'])
        ->toBe(1.5);
    mlbExactLineQuotes($homeSnapshot);
    mlbExactLineQuotes($awaySnapshot);

    $this->artisan('mlb:run-tabular-shadow', ['--artifact' => $artifact->id])
        ->expectsOutputToContain('MLB tabular shadow inference completed.')
        ->expectsOutputToContain('Snapshots inferred: 2')
        ->expectsOutputToContain('Shadow outputs created: 6')
        ->expectsOutputToContain('Recorded 6 new immutable shadow decision(s).')
        ->assertSuccessful();

    $shadows = ShadowModelOutput::query()
        ->where('model_artifact_id', $artifact->id)
        ->get();
    $decisions = BetDecision::query()
        ->where('model_artifact_id', $artifact->id)
        ->get();
    $homeSpreadDecision = $decisions
        ->where('game_id', $homeSnapshot->game_id)
        ->firstWhere('market_type', 'spread');
    $awaySpreadDecision = $decisions
        ->where('game_id', $awaySnapshot->game_id)
        ->firstWhere('market_type', 'spread');
    $homeTotalDecision = $decisions
        ->where('game_id', $homeSnapshot->game_id)
        ->firstWhere('market_type', 'total');

    expect($shadows)->toHaveCount(6)
        ->and($shadows->every(
            fn (ShadowModelOutput $shadow): bool => data_get($shadow->explanation, 'active_source') === 'baseline'
                && data_get($shadow->explanation, 'public_output_changed') === false
                && data_get($shadow->explanation, 'model_run_id') === $fixture['model_run_id'],
        ))->toBeTrue()
        ->and($shadows->every(
            fn (ShadowModelOutput $shadow): bool => data_get($shadow->explanation, 'market_reference') === null,
        ))->toBeTrue()
        ->and($decisions)->toHaveCount(6)
        ->and($decisions->every(fn (BetDecision $decision): bool => $decision->is_public === false))
        ->toBeTrue()
        ->and($decisions->every(fn (BetDecision $decision): bool => $decision->is_tracking_only === true))
        ->toBeTrue()
        ->and($decisions->every(fn (BetDecision $decision): bool => $decision->is_bet === false))
        ->toBeTrue()
        ->and($decisions->firstWhere('market_type', 'moneyline')->eligibility_reasons)
        ->toContain('pregame_market_quote_missing')
        ->and($homeSpreadDecision->side)->toBe('home')
        ->and($homeSpreadDecision->line)->toBe(-1.5)
        ->and($homeSpreadDecision->model_probability)->toBe(0.558)
        ->and(data_get($homeSpreadDecision->explanation, 'model_market_reference_line'))->toBe(-1.5)
        ->and($awaySpreadDecision->side)->toBe('away')
        ->and($awaySpreadDecision->line)->toBe(1.5)
        ->and($awaySpreadDecision->model_probability)->toBe(0.558)
        ->and(data_get($awaySpreadDecision->explanation, 'model_market_reference_line'))->toBe(1.5)
        ->and($homeTotalDecision->line)->toBe(8.5)
        ->and($homeTotalDecision->model_probability)->toBe(0.528)
        ->and(data_get($homeTotalDecision->explanation, 'model_market_reference_line'))->toBe(8.5);
});

it('returns stable readiness and inference failure reasons without publishing', function () {
    config()->set('mlb_ml.shadow.enabled', false);
    expect(app(MlbChallengerShadowService::class)->run()['message'])
        ->toBe(MlbChallengerShadowService::DISABLED);

    config()->set('mlb_ml.shadow.enabled', true);
    expect(app(MlbChallengerShadowService::class)->run()['message'])
        ->toBe(MlbChallengerShadowService::MISSING_ARTIFACT);

    $fixture = mlbTabularRunFixture($this->mlbMlTestRoot.'/readiness-run');
    $artifact = app(MlbTabularModelRunRegistrar::class)->register($fixture['run_directory']);
    config()->set('mlb_ml.shadow.artifact_id', $artifact->id);
    $snapshot = mlbCanonicalSnapshot(false);

    $pitcherResult = app(MlbChallengerShadowService::class)->run($artifact->id);
    expect($pitcherResult['reasons'])->toBe([
        MlbChallengerShadowService::PITCHERS_NOT_READY => 1,
    ]);

    Game::query()->whereKey($snapshot->game_id)->update([
        'probable_home_pitcher_espn_id' => 'home-starter',
        'probable_away_pitcher_espn_id' => 'away-starter',
    ]);
    $staleSnapshotResult = app(MlbChallengerShadowService::class)->run($artifact->id);
    expect($staleSnapshotResult['reasons'])->toBe([
        MlbChallengerShadowService::PITCHERS_NOT_READY => 1,
    ]);

    $regeneratedSnapshot = mlbRegeneratedSnapshotWithPitchers($snapshot);
    config()->set('mlb_ml.shadow.required_features', ['feature_not_captured']);
    $featureResult = app(MlbChallengerShadowService::class)->run($artifact->id);
    expect($featureResult['reasons'])->toBe([
        MlbChallengerShadowService::FEATURES_NOT_READY => 1,
    ]);

    Game::query()->whereKey($snapshot->game_id)->update([
        'probable_home_pitcher_espn_id' => 'changed-home-starter',
    ]);
    $changedPitcherResult = app(MlbChallengerShadowService::class)->run($artifact->id);
    expect($changedPitcherResult['reasons'])->toBe([
        MlbChallengerShadowService::PITCHERS_NOT_READY => 1,
    ]);

    Game::query()->whereKey($snapshot->game_id)->update([
        'probable_home_pitcher_espn_id' => 'home-starter',
    ]);
    config()->set('mlb_ml.shadow.required_features', [
        'feature_home_team_elo',
        'feature_away_team_elo',
        'feature_home_pitcher_elo',
        'feature_away_pitcher_elo',
    ]);
    config()->set('mlb_ml.process.command', [PHP_BINARY, $this->mlbMlTestRoot.'/missing-cli.php']);

    $inferenceResult = app(MlbChallengerShadowService::class)->run($artifact->id);
    expect($inferenceResult['reasons'])->toBe([
        MlbChallengerShadowService::INFERENCE_FAILED => 1,
    ])
        ->and($inferenceResult['status'])->toBe('failed')
        ->and(ModelRun::query()
            ->where('run_type', 'shadow_inference')
            ->where('status', 'failed')
            ->where('parameters->game_id', $regeneratedSnapshot->game_id)
            ->exists())->toBeTrue()
        ->and(ShadowModelOutput::query()->count())->toBe(0)
        ->and(BetDecision::query()->count())->toBe(0);
});

it('runs the active MLB challenger and latest promoted champion together when unpinned', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    $challengerFixture = mlbTabularRunFixture($this->mlbMlTestRoot.'/cohort-challenger');
    $challenger = app(MlbTabularModelRunRegistrar::class)->register(
        $challengerFixture['run_directory'],
    );
    $championFixture = mlbTabularRunFixture($this->mlbMlTestRoot.'/cohort-champion');
    $champion = app(MlbTabularModelRunRegistrar::class)->register(
        $championFixture['run_directory'],
    );
    $champion->update([
        'status' => 'promoted',
        'promotion_decision' => [
            'promoted_markets' => ['win_probability', 'spread', 'total'],
        ],
        'promoted_at' => now()->subHour(),
    ]);
    app(ShadowArtifactSelector::class)->activateChallenger($challenger, [
        'source' => 'weekly_training',
    ]);

    config()->set('mlb_ml.process.command', [
        PHP_BINARY,
        mlbTabularFakeCli($this->mlbMlTestRoot.'/cohort-cli.php'),
    ]);
    config()->set('mlb_ml.shadow.enabled', true);
    config()->set('mlb_ml.shadow.artifact_id', '');
    $snapshot = mlbCanonicalSnapshot();

    $result = app(MlbChallengerShadowService::class)->run();

    expect($result['status'])->toBe('completed')
        ->and($result['artifact_ids'])->toBe([$challenger->id, $champion->id])
        ->and($result['considered'])->toBe(1)
        ->and($result['inferred'])->toBe(2)
        ->and($result['outputs_created'])->toBe(6)
        ->and(ShadowModelOutput::query()
            ->where('prediction_feature_snapshot_id', $snapshot->id)
            ->where('model_artifact_id', $challenger->id)
            ->count())->toBe(3)
        ->and(ShadowModelOutput::query()
            ->where('prediction_feature_snapshot_id', $snapshot->id)
            ->where('model_artifact_id', $champion->id)
            ->count())->toBe(3)
        ->and(ShadowModelOutput::query()
            ->where('prediction_feature_snapshot_id', $snapshot->id)
            ->get()
            ->every(fn (ShadowModelOutput $output): bool => data_get(
                $output->explanation,
                'public_output_changed',
            ) === false))->toBeTrue();
});

/**
 * @return array{
 *     run_directory: string,
 *     dataset_path: string,
 *     dataset_hash: string,
 *     model_run_id: string,
 *     artifact_id: string,
 *     config_hash: string
 * }
 */
function mlbTabularRunFixture(string $runDirectory): array
{
    File::ensureDirectoryExists($runDirectory.'/models');
    File::ensureDirectoryExists($runDirectory.'/calibrators');

    $files = [
        'calibrators/logistic_regression_isotonic.joblib' => 'logistic-isotonic',
        'calibrators/logistic_regression_platt.joblib' => 'logistic-platt',
        'calibrators/xgboost_isotonic.joblib' => 'xgboost-isotonic',
        'calibrators/xgboost_platt.joblib' => 'xgboost-platt',
        'feature_schema.yaml' => "schema_version: mlb-pregame-ml-v1\n",
        'models/logistic_classifier.joblib' => 'logistic-model',
        'models/xgboost_classifier.ubj' => 'xgboost-classifier',
        'models/xgboost_home_margin.ubj' => 'xgboost-margin',
        'models/xgboost_total_points.ubj' => 'xgboost-total',
        'prediction_example.json' => "{}\n",
        'preprocessor.joblib' => 'preprocessor',
    ];
    $modelRunId = (string) Str::uuid();
    $artifactId = (string) Str::uuid();
    $datasetPath = dirname($runDirectory).'/training-data.csv';
    File::put($datasetPath, "game_id,feature_home_team_elo,target_home_win\n1,1532,1\n");
    $datasetHash = hash_file('sha256', $datasetPath);
    $featureSchemaHash = hash('sha256', 'mlb-feature-schema');
    $configHash = hash('sha256', 'mlb-training-config');
    $evaluation = [
        'report_type' => 'mlb_tabular_walk_forward_evaluation',
        'model_type' => 'mlb_tabular_bundle',
        'model_run_id' => $modelRunId,
        'artifact_id' => $artifactId,
        'dataset' => ['sha256' => $datasetHash],
        'feature_schema' => ['sha256' => $featureSchemaHash],
        'final_holdout' => ['test_start_at' => '2026-07-01T00:00:00+00:00'],
        'rolling_weekly' => ['summary' => ['window_count' => 4]],
        'promotion_summary' => ['recommendation' => 'shadow_only'],
    ];
    $files['evaluation.json'] = json_encode(
        $evaluation,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;

    foreach ($files as $relativePath => $contents) {
        File::put($runDirectory.'/'.$relativePath, $contents);
    }

    $inventory = [];
    foreach (array_keys($files) as $relativePath) {
        $path = $runDirectory.'/'.$relativePath;
        $inventory[$relativePath] = [
            'sha256' => hash_file('sha256', $path),
            'bytes' => filesize($path),
        ];
    }
    ksort($inventory);

    $manifest = [
        'manifest_version' => 1,
        'model_type' => 'mlb_tabular_bundle',
        'model_version' => 'mlb-tabular-v1',
        'package' => 'picksports_mlb_ml',
        'module' => 'picksports_mlb_ml',
        'model_run_id' => $modelRunId,
        'artifact_id' => $artifactId,
        'generated_at' => '2026-07-29T12:00:00+00:00',
        'dataset_hash' => $datasetHash,
        'feature_schema_version' => 'mlb-pregame-ml-v1',
        'feature_schema_hash' => $featureSchemaHash,
        'config_hash' => $configHash,
        'code_version' => str_repeat('a', 40),
        'source_hash' => hash('sha256', 'mlb-source'),
        'seed' => 20260729,
        'champion_classifier' => 'xgboost',
        'selected_calibrators' => [
            'logistic_regression' => 'platt',
            'xgboost' => 'platt',
        ],
        'training_seasons' => [2025, 2026],
        'chronological_boundaries' => [
            'train_end_at' => '2026-06-01T00:00:00+00:00',
            'test_end_at' => '2026-07-28T00:00:00+00:00',
        ],
        'artifacts' => $inventory,
    ];
    File::put(
        $runDirectory.'/manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );

    return [
        'run_directory' => $runDirectory,
        'dataset_path' => $datasetPath,
        'dataset_hash' => $datasetHash,
        'model_run_id' => $modelRunId,
        'artifact_id' => $artifactId,
        'config_hash' => $configHash,
    ];
}

function mlbTabularFakeCli(string $path): string
{
    File::put($path, <<<'PHP'
<?php

$arguments = array_slice($argv, 1);
$runDirectory = $arguments[array_search('--run-dir', $arguments, true) + 1] ?? null;
$inputPath = $arguments[array_search('--input', $arguments, true) + 1] ?? null;
$manifest = json_decode((string) file_get_contents($runDirectory.'/manifest.json'), true);
$features = json_decode((string) file_get_contents($inputPath), true);
$homeCoverProbability = ($features['feature_home_team_elo'] ?? 0) >= 1500
    ? 0.558
    : 0.442;
echo json_encode([[
    'home_win_probability' => 0.614,
    'expected_home_margin' => 1.3,
    'expected_total' => 8.7,
    'home_cover_probability' => $homeCoverProbability,
    'over_probability' => 0.472,
    'uncertainty' => 0.081,
    'model_run_id' => $manifest['model_run_id'],
    'artifact_id' => $manifest['artifact_id'],
    'dataset_hash' => $manifest['dataset_hash'],
    'feature_hash' => hash('sha256', json_encode($features)),
]], JSON_THROW_ON_ERROR);
PHP);

    return $path;
}

function mlbCanonicalSnapshot(
    bool $withPitchers = true,
    float $marketHomeMargin = 1.5,
    float $homeTeamElo = 1532,
): PredictionFeatureSnapshot {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $gameStart = now()->addDay();
    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'season_type' => '2',
        'game_date' => $gameStart->toDateString(),
        'game_time' => $gameStart->format('H:i:s'),
        'status' => 'STATUS_SCHEDULED',
        'probable_home_pitcher_espn_id' => $withPitchers ? 'home-starter' : null,
        'probable_away_pitcher_espn_id' => $withPitchers ? 'away-starter' : null,
    ]);
    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'season' => 2026,
        'season_type' => '2',
        'predicted_spread' => 0.7,
        'predicted_total' => 8.2,
        'win_probability' => 0.54,
        'confidence_score' => 0.58,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
    ]);
    $predictionRun = app(ModelRunRecorder::class)->create(
        sport: 'mlb',
        runType: 'prediction',
        modelVersion: 'rules-v1',
        featureVersion: 'core-v3',
        blendVersion: 'baseline-v1',
        status: 'completed',
        completedAt: now(),
    );

    return PredictionFeatureSnapshot::query()->create([
        'sport' => 'mlb',
        'prediction_table' => 'mlb_predictions',
        'prediction_id' => $prediction->id,
        'game_id' => $game->id,
        'snapshot_run_id' => (string) Str::uuid(),
        'model_run_id' => $predictionRun->id,
        'model_version' => 'rules-v1',
        'feature_version' => 'core-v3',
        'blend_version' => 'baseline-v1',
        'features' => [
            'home_team_elo' => $homeTeamElo,
            'away_team_elo' => 1498,
            'home_pitcher_elo' => 1511,
            'away_pitcher_elo' => 1487,
            'home_pitcher_confidence' => 1.0,
            'away_pitcher_confidence' => 1.0,
            'home_probable_pitcher_espn_id' => $withPitchers ? 'home-starter' : null,
            'away_probable_pitcher_espn_id' => $withPitchers ? 'away-starter' : null,
        ],
        'outputs' => [
            'win_probability' => 0.54,
            'predicted_spread' => 0.7,
            'predicted_total' => 8.2,
            'bookmaker_home_spread' => -$marketHomeMargin,
            'market_spread' => $marketHomeMargin,
            'market_total' => 8.5,
        ],
        'market_context' => [
            'market_home_margin' => $marketHomeMargin,
            'market_total' => 8.5,
        ],
        'model_metadata' => [
            'pitcher_inputs' => [
                'home_probable_pitcher_espn_id' => $withPitchers ? 'home-starter' : null,
                'away_probable_pitcher_espn_id' => $withPitchers ? 'away-starter' : null,
            ],
        ],
        'feature_hash' => hash('sha256', 'mlb-canonical-'.$game->id),
        'generated_at' => now()->subMinute(),
        'game_start_at' => $gameStart,
        'features_available_at' => now()->subMinute(),
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ]);
}

function mlbRegeneratedSnapshotWithPitchers(
    PredictionFeatureSnapshot $snapshot,
): PredictionFeatureSnapshot {
    $regenerated = $snapshot->replicate();
    $regenerated->snapshot_run_id = (string) Str::uuid();
    $regenerated->features = [
        ...(array) $snapshot->features,
        'home_probable_pitcher_espn_id' => 'home-starter',
        'away_probable_pitcher_espn_id' => 'away-starter',
    ];
    $regenerated->model_metadata = [
        ...(array) $snapshot->model_metadata,
        'pitcher_inputs' => [
            'home_probable_pitcher_espn_id' => 'home-starter',
            'away_probable_pitcher_espn_id' => 'away-starter',
        ],
    ];
    $regenerated->feature_hash = hash('sha256', 'mlb-regenerated-'.$snapshot->game_id);
    $regenerated->generated_at = now();
    $regenerated->features_available_at = now();
    $regenerated->save();

    return $regenerated;
}

function mlbExactLineQuotes(PredictionFeatureSnapshot $snapshot): void
{
    $oddsSnapshot = GameOddsSnapshot::query()->create([
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => $snapshot->game_id,
        'source' => 'test',
        'commence_time' => $snapshot->game_start_at,
        'captured_at' => now()->subMinutes(2),
        'payload_hash' => hash('sha256', 'mlb-exact-lines-'.$snapshot->game_id),
        'odds_data' => [],
    ]);

    foreach ([
        ['market_key' => 'spreads', 'side' => 'home', 'line' => -1.5, 'price' => -110, 'no_vig' => 0.5],
        ['market_key' => 'spreads', 'side' => 'away', 'line' => 1.5, 'price' => -110, 'no_vig' => 0.5],
        ['market_key' => 'totals', 'side' => 'under', 'line' => 8.5, 'price' => -105, 'no_vig' => 0.49],
    ] as $quote) {
        MarketQuote::query()->create([
            'game_odds_snapshot_id' => $oddsSnapshot->id,
            'sport' => 'mlb',
            'game_table' => 'mlb_games',
            'game_id' => $snapshot->game_id,
            'source' => 'test',
            'bookmaker_key' => 'testbook',
            'market_key' => $quote['market_key'],
            'side' => $quote['side'],
            'line' => $quote['line'],
            'price' => $quote['price'],
            'implied_probability' => 0.51,
            'no_vig_probability' => $quote['no_vig'],
            'commence_time' => $snapshot->game_start_at,
            'captured_at' => now()->subMinutes(2),
            'is_pregame' => true,
            'quote_hash' => hash('sha256', implode('|', [
                'mlb-exact-line',
                $snapshot->game_id,
                $quote['market_key'],
                $quote['side'],
            ])),
        ]);
    }
}
