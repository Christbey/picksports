<?php

use App\Application\Sports\ReadModels\CanonicalShadowSnapshot;
use App\Models\BetDecision;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\MarketQuote;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionMarket;
use App\Models\ShadowModelOutput;
use App\Models\SportEvent;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\ML\ShadowArtifactSelector;
use App\Services\MLB\MlbArtifactCohortSerializer;
use App\Services\MLB\MlbChallengerShadowService;
use App\Services\MLB\MlbInferenceOutputCommit;
use App\Services\MLB\MlbShadowFeatureBuilder;
use App\Services\MLB\MlbTabularArtifactAdmission;
use App\Services\MLB\MlbTabularModelBundle;
use App\Services\MLB\MlbTabularModelInferenceService;
use App\Services\MLB\MlbTabularModelRunRegistrar;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use App\Services\Predictions\CanonicalShadowInputs;
use App\Services\Predictions\ModelRunRecorder;
use App\Services\Predictions\PredictionFeatureSnapshotRecorder;
use App\Support\MLB\MlbGameStart;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ObservedMlbDatasetFixture;
use Tests\Support\ObservedMlbGameFixture;
use Tests\Support\SingleActorSqliteMlbArtifactCohortSerializer;
use Tests\Support\SyntheticMlbTabularBundleFixture;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Functional range collaborator only: current source and transaction admission remain real.
    app()->instance(MlbArtifactCohortSerializer::class, new SingleActorSqliteMlbArtifactCohortSerializer);
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Carbon::setTestNow('2026-07-29T17:00:00Z');
    CarbonImmutable::setTestNow('2026-07-29T17:00:00Z');
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
    CarbonImmutable::setTestNow();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
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
        ->and($zip->locateName('models/xgboost_total_points.json'))->not->toBeFalse()
        ->and($zip->locateName('dataset/training-data.csv'))->not->toBeFalse();
    $zip->close();
    $runtimePath = app(MlbTabularModelBundle::class)->extractAndVerify($artifact, $bundlePath);

    expect(File::isFile($runtimePath.'/manifest.json'))->toBeTrue()
        ->and(File::isFile($runtimePath.'/bundle.json'))->toBeFalse()
        ->and(File::isDirectory($runtimePath.'/dataset'))->toBeFalse()
        ->and(File::isFile(dirname($runtimePath).'/dataset/training-data.csv'))->toBeTrue();

    $cli = mlbTabularFakeCli($this->mlbMlTestRoot.'/fake-cli.php');
    config()->set('mlb_ml.process.command', [PHP_BINARY, $cli]);
    $priorEnvironment = mlbSyntheticParentEnvironment();
    try {
        $output = app(MlbTabularModelInferenceService::class)->predict($artifact, [
            'feature_home_team_elo' => 1532,
            'feature_away_team_elo' => 1498,
            'feature_home_pitcher_elo' => 1511,
            'feature_away_pitcher_elo' => 1487,
        ]);
    } finally {
        mlbRestoreParentEnvironment($priorEnvironment);
    }

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
    $artifact = app(MlbTabularModelRunRegistrar::class)->register($fixture['run_directory'], $fixture['dataset_path']);
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
    $artifact = app(MlbTabularModelRunRegistrar::class)->register($fixture['run_directory'], $fixture['dataset_path']);
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
        $challengerFixture['run_directory'], $challengerFixture['dataset_path'],
    );
    $championFixture = mlbTabularRunFixture($this->mlbMlTestRoot.'/cohort-champion');
    $champion = app(MlbTabularModelRunRegistrar::class)->register(
        $championFixture['run_directory'], $championFixture['dataset_path'],
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
    $mutable = Carbon::getTestNow();
    $immutable = CarbonImmutable::getTestNow();
    try {
        Carbon::setTestNow('2026-06-30T06:00:00Z');
        CarbonImmutable::setTestNow('2026-06-30T06:00:00Z');
        $source = ObservedMlbDatasetFixture::create(true);
    } finally {
        Carbon::setTestNow($mutable);
        CarbonImmutable::setTestNow($immutable);
    }
    $datasetPath = $runDirectory.'.training-data.csv';
    File::ensureDirectoryExists(dirname($datasetPath), 0700, true);
    expect(File::copy($source['path'], $datasetPath))->toBeTrue();
    expect(File::copy($source['path'].'.manifest.json', $datasetPath.'.manifest.json'))->toBeTrue();
    @unlink($source['path']);
    @unlink($source['path'].'.manifest.json');

    return SyntheticMlbTabularBundleFixture::create($runDirectory, $datasetPath);
}

function mlbTabularFakeCli(string $path): string
{
    $script = <<<'PHP'
<?php

if (getenv('PICKSPORTS_TEST_MLB_ENV_SENTINEL') !== false) {
    fwrite(STDERR, 'Unexpected synthetic inherited environment sentinel.');
    exit(23);
}
if (getenv('PYTHONPATH') !== __EXPECTED_MLB_SOURCE_PATH__) {
    fwrite(STDERR, 'Controlled MLB source path was not preserved.');
    exit(24);
}
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
PHP;
    File::put($path, str_replace('__EXPECTED_MLB_SOURCE_PATH__', var_export(base_path('ml/mlb/src'), true), $script));

    return $path;
}

function mlbCanonicalSnapshot(
    bool $withPitchers = true,
    float $marketHomeMargin = 1.5,
    float $homeTeamElo = 1532,
    bool $compact = false,
): PredictionFeatureSnapshot {
    $home = Team::factory()->create(['name' => 'Synthetic Home']);
    $away = Team::factory()->create(['name' => 'Synthetic Away']);
    $gameStart = now()->addDay();
    $game = Game::factory()->withSyntheticWallStart((string) config('app.timezone'))->create([
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
    $receipt = app(GameOddsSnapshotRecorder::class)->record('mlb', $game,
        ['id' => 'synthetic-tabular-'.$game->id, 'commence_time' => MlbGameStart::for($game)->toIso8601String()],
        ['home_team' => $home->name, 'away_team' => $away->name, 'bookmakers' => [['key' => 'testbook', 'markets' => [
            ['key' => 'spreads', 'outcomes' => [
                ['name' => $home->name, 'point' => -$marketHomeMargin, 'price' => -110],
                ['name' => $away->name, 'point' => $marketHomeMargin, 'price' => -110],
            ]],
            ['key' => 'totals', 'outcomes' => [['name' => 'Under', 'point' => 8.5, 'price' => -105], ['name' => 'Over', 'point' => 8.5, 'price' => -115]]],
        ]]]], Carbon::instance(now()), 'test');

    return app(PredictionFeatureSnapshotRecorder::class)->recordCurrentMlb($prediction, $game, [], [
        'model_version' => 'rules-v1', 'feature_version' => 'core-v3', 'blend_version' => 'baseline-v1',
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
            'safety' => ['snapshot_id' => $receipt->id],
        ],
        'model_metadata' => [
            ...($compact ? ['test_padding' => str_repeat('x', 8192)] : []),
            'pitcher_inputs' => [
                'home_probable_pitcher_espn_id' => $withPitchers ? 'home-starter' : null,
                'away_probable_pitcher_espn_id' => $withPitchers ? 'away-starter' : null,
            ],
        ],
        'feature_hash' => hash('sha256', 'mlb-canonical-'.$game->id),
        'generated_at' => now(),
        'game_start_at' => $gameStart,
        'features_available_at' => now(),
        'pregame_safe' => true,
        'availability_status' => 'observed_pregame',
    ]);
}

function mlbRegeneratedSnapshotWithPitchers(PredictionFeatureSnapshot $snapshot): PredictionFeatureSnapshot
{
    $game = Game::findOrFail($snapshot->game_id);
    $prediction = Prediction::findOrFail($snapshot->prediction_id);

    return app(PredictionFeatureSnapshotRecorder::class)->recordCurrentMlb($prediction, $game, [], [
        'model_version' => $snapshot->model_version, 'feature_version' => $snapshot->feature_version, 'blend_version' => $snapshot->blend_version,
        'features' => [...$snapshot->features, 'home_probable_pitcher_espn_id' => 'home-starter', 'away_probable_pitcher_espn_id' => 'away-starter'],
        'outputs' => $snapshot->outputs, 'market_context' => $snapshot->market_context,
        'model_metadata' => ['pitcher_inputs' => ['home_probable_pitcher_espn_id' => 'home-starter', 'away_probable_pitcher_espn_id' => 'away-starter']],
    ]);
}

function mlbExactLineQuotes(PredictionFeatureSnapshot $snapshot): void
{
    expect(MarketQuote::query()->where('game_id', $snapshot->game_id)->where('is_pregame', true)->count())->toBeGreaterThanOrEqual(3);
}

/** Synthetic environment only; save and restore parent state without recording any existing values. */
function mlbSyntheticParentEnvironment(): array
{
    $prior = [];
    foreach (['PICKSPORTS_TEST_MLB_ENV_SENTINEL' => 'synthetic-private-sentinel', 'PYTHONPATH' => '/synthetic-poison-not-a-package'] as $key => $value) {
        $prior[$key] = ['process' => getenv($key), 'env_exists' => array_key_exists($key, $_ENV), 'env' => $_ENV[$key] ?? null,
            'server_exists' => array_key_exists($key, $_SERVER), 'server' => $_SERVER[$key] ?? null];
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    return $prior;
}

function mlbRestoreParentEnvironment(array $prior): void
{
    foreach ($prior as $key => $value) {
        putenv($value['process'] === false ? $key : $key.'='.$value['process']);
        if ($value['env_exists']) {
            $_ENV[$key] = $value['env'];
        } else {
            unset($_ENV[$key]);
        }
        if ($value['server_exists']) {
            $_SERVER[$key] = $value['server'];
        } else {
            unset($_SERVER[$key]);
        }
    }
}

// Final-output before-controls: real registration/admission/child; only scheduling
// the synthetic target change between real inference completion and output commit.
it('refuses final tabular output after current target drift', function (string $drift, bool $canonical) {
    config()->set('prediction_lifecycle.canonical_reads.mlb', $canonical);
    $fixture = mlbTabularRunFixture($this->mlbMlTestRoot.'/final-boundary');
    $artifact = app(MlbTabularModelRunRegistrar::class)->register($fixture['run_directory'], $fixture['dataset_path']);
    if ($drift === 'market-role') {
        // Trusted synthetic SQL constructs role state only, not scientific approval.
        // Original protected training evaluation/hash/proof remain untouched.
        $artifact->getConnection()->table('model_artifacts')->where('id', $artifact->id)->update([
            'status' => 'promoted', 'promoted_at' => now()->subMinute()->toDateTimeString(),
            'promotion_decision' => json_encode(['promoted_markets' => ['spread']], JSON_THROW_ON_ERROR),
        ]);
        $artifact->refresh();
    }
    $admitted = app(MlbTabularArtifactAdmission::class)->admit($artifact);
    $snapshot = $canonical ? opsFinalCanonicalTarget() : mlbCanonicalSnapshot(compact: $drift === 'compact');
    if ($drift === 'compact') {
        $this->artisan('sports:compact-snapshot-metadata', ['--after-id' => $snapshot->id - 1, '--through-id' => $snapshot->id, '--limit' => 1, '--apply' => true])->assertSuccessful();
        $snapshot->refresh();
        expect($snapshot->getRawOriginal('model_metadata'))->toContain('picksports:snapshot-metadata:gzip-v1:');
    }
    $sourceGameIds = array_column($admitted['proof']['dataset']['references'], 'game_id');
    expect($sourceGameIds)->not->toContain((int) $snapshot->game_id);
    config()->set('mlb_ml.process.command', [PHP_BINARY, mlbTabularFakeCli($this->mlbMlTestRoot.'/final-cli.php')]);
    config()->set('mlb_ml.shadow.enabled', true);
    config()->set('mlb_ml.shadow.artifact_id', $artifact->id);
    $beforeOutputs = ShadowModelOutput::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    $beforeSuccessfulRuns = ModelRun::query()->where('sport', 'mlb')->where('run_type', 'shadow_inference')->where('status', 'completed')->orderBy('id')->pluck('id')->all();
    $boundary = new class(app(ModelArtifactRegistry::class), app(MlbTabularModelBundle::class)) extends MlbTabularModelInferenceService
    {
        public Closure $afterRealChild;

        public bool $ran = false;

        public function predict(ModelArtifact $artifact, array $features): array
        {
            $output = parent::predict($artifact, $features);
            $this->ran = true;
            ($this->afterRealChild)();

            return $output;
        }
    };
    $cancellation = (object) ['active' => true, 'seen' => false];
    $boundary->afterRealChild = function () use ($snapshot, $drift, $artifact, $cancellation, $sourceGameIds): void {
        $game = Game::query()->findOrFail($snapshot->game_id);
        if ($drift === 'status') {
            $game->update(['status' => 'STATUS_IN_PROGRESS']);
        } elseif ($drift === 'starter') {
            $game->update(['probable_home_pitcher_espn_id' => 'different-current-starter']);
        } elseif ($drift === 'market-role') {
            $artifact->getConnection()->table('model_artifacts')->where('id', $artifact->id)->update([
                'promotion_decision' => json_encode(['promoted_markets' => []], JSON_THROW_ON_ERROR),
            ]);
            expect(ModelArtifact::query()->findOrFail($artifact->id)->status)->toBe('promoted');
        } elseif ($drift === 'cancel-run') {
            ModelRun::creating(function (ModelRun $run) use ($cancellation): ?bool {
                if ($cancellation->active && $run->run_type === 'shadow_inference' && $run->status === 'completed') {
                    $cancellation->seen = true;

                    return false;
                }

                return null;
            });
        } elseif (in_array($drift, ['cancel-first-output', 'cancel-middle-output'], true)) {
            $market = $drift === 'cancel-first-output' ? 'win_probability' : 'spread';
            ShadowModelOutput::creating(function (ShadowModelOutput $output) use ($cancellation, $market): ?bool {
                if ($cancellation->active && $output->market_type === $market) {
                    $cancellation->seen = true;

                    return false;
                }

                return null;
            });
        } elseif (in_array($drift, ['created-run-delete', 'created-run-status'], true)) {
            ModelRun::created(function (ModelRun $run) use ($cancellation, $drift): void {
                if ($cancellation->active && $run->run_type === 'shadow_inference' && $run->status === 'completed') {
                    $cancellation->seen = true;
                    $query = $run->getConnection()->table('model_runs')->where('id', $run->id);
                    $drift === 'created-run-delete' ? $query->delete() : $query->update(['status' => 'failed']);
                }
            });
        } elseif (in_array($drift, ['created-output-delete', 'created-output-identity'], true)) {
            ShadowModelOutput::created(function (ShadowModelOutput $output) use ($cancellation, $drift, $sourceGameIds): void {
                if ($cancellation->active && $output->market_type === 'spread') {
                    $cancellation->seen = true;
                    $query = $output->getConnection()->table('shadow_model_outputs')->where('id', $output->id);
                    $drift === 'created-output-delete' ? $query->delete() : $query->update(['game_id' => $sourceGameIds[0]]);
                }
            });
        } elseif ($drift === 'created-last-output-run-status') {
            ShadowModelOutput::created(function (ShadowModelOutput $output) use ($cancellation): void {
                if ($cancellation->active && $output->market_type === 'total') {
                    $cancellation->seen = true;
                    $output->getConnection()->table('model_runs')->where('id', $output->inference_run_id)->update(['status' => 'failed']);
                }
            });
        } elseif ($drift === 'created-last-output-prior-output') {
            ShadowModelOutput::created(function (ShadowModelOutput $output) use ($cancellation): void {
                if ($cancellation->active && $output->market_type === 'total') {
                    $cancellation->seen = true;
                    $output->getConnection()->table('shadow_model_outputs')->where('inference_run_id', $output->inference_run_id)
                        ->where('market_type', 'win_probability')->delete();
                }
            });
        } elseif ($drift === 'revision') {
            CanonicalPrediction::query()->findOrFail($snapshot->prediction_id)->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
        }
    };
    app()->instance(MlbTabularModelInferenceService::class, $boundary);
    app()->forgetInstance(MlbChallengerShadowService::class);
    try {
        app(MlbChallengerShadowService::class)->run($artifact->id, $snapshot->game_id);
    } catch (Throwable $exception) {
        // An explicit cancellation may surface as a refusal; it must still leave
        // no successful receipt or partial output. Unexpected fixture errors fail.
        if (! in_array($drift, ['cancel-run', 'cancel-first-output', 'cancel-middle-output', 'created-run-delete', 'created-run-status', 'created-output-delete', 'created-output-identity', 'created-last-output-run-status', 'created-last-output-prior-output'], true)) {
            throw $exception;
        }
    } finally {
        $cancellation->active = false;
    }
    expect($boundary->ran)->toBeTrue();
    if (in_array($drift, ['cancel-run', 'cancel-first-output', 'cancel-middle-output', 'created-run-delete', 'created-run-status', 'created-output-delete', 'created-output-identity', 'created-last-output-run-status', 'created-last-output-prior-output'], true)) {
        expect($cancellation->seen)->toBeTrue();
    }
    $afterOutputs = ShadowModelOutput::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    if (in_array($drift, ['unchanged', 'compact'], true)) {
        expect(count($afterOutputs) - count($beforeOutputs))->toBe(3);
        $successIds = ModelRun::query()->where('sport', 'mlb')->where('run_type', 'shadow_inference')->where('status', 'completed')->orderBy('id')->pluck('id')->all();
        $outputIds = ShadowModelOutput::query()->orderBy('id')->pluck('id')->all();
        $boundary->afterRealChild = static function (): void {};
        app(MlbChallengerShadowService::class)->run($artifact->id, $snapshot->game_id);
        expect(ShadowModelOutput::query()->orderBy('id')->pluck('id')->all())->toBe($outputIds);
        expect(ModelRun::query()->where('sport', 'mlb')->where('run_type', 'shadow_inference')->where('status', 'completed')->orderBy('id')->pluck('id')->all())->toBe($successIds);
    } elseif ($drift === 'market-role') {
        expect(ShadowModelOutput::query()->where('model_artifact_id', $artifact->id)->where('status', 'promoted_shadow')->count())->toBe(0);
        if ($afterOutputs === $beforeOutputs) {
            expect(ModelRun::query()->where('sport', 'mlb')->where('run_type', 'shadow_inference')->where('status', 'completed')->orderBy('id')->pluck('id')->all())->toBe($beforeSuccessfulRuns);
        }
    } else {
        expect($afterOutputs)->toBe($beforeOutputs);
        expect(ModelRun::query()->where('sport', 'mlb')->where('run_type', 'shadow_inference')->where('status', 'completed')->orderBy('id')->pluck('id')->all())->toBe($beforeSuccessfulRuns);
    }
})->with([['status', false], ['starter', false], ['unchanged', false], ['status', true], ['starter', true], ['revision', true], ['unchanged', true], ['market-role', true], ['cancel-run', false], ['cancel-first-output', false], ['cancel-middle-output', true], ['compact', false], ['created-run-delete', false], ['created-run-status', false], ['created-output-delete', true], ['created-output-identity', true], ['created-last-output-run-status', true], ['created-last-output-prior-output', true]]);

function opsFinalCanonicalTarget(): CanonicalShadowSnapshot
{
    $start = now()->addDay()->toImmutable();
    $event = SportEvent::factory()->create(['sport' => 'mlb', 'season' => 2026, 'starts_at' => $start]);
    $game = ObservedMlbGameFixture::observe(Game::factory()->create([
        'sport_event_id' => $event->id, 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => $start->toDateString(), 'game_time' => $start->toTimeString(),
        'probable_home_pitcher_espn_id' => 'home-starter', 'probable_away_pitcher_espn_id' => 'away-starter',
    ]), $start);
    $input = EventInputSnapshot::factory()->create(ObservedMlbGameFixture::snapshotAttributes($game, [
        'event' => ['starts_at' => $start->toIso8601String()],
        'home' => ['team_id' => $game->home_team_id, 'elo' => 1510],
        'away' => ['team_id' => $game->away_team_id, 'elo' => 1480],
        'pitching' => ['home' => ['elo' => 1600, 'espn_id' => 'home-starter'], 'away' => ['elo' => 1500, 'espn_id' => 'away-starter']],
        'pregame_market' => ['available' => true, 'consensus' => ['spread' => ['line' => -1.5], 'total' => ['line' => 8.5]]],
    ]));
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'mlb', 'phase' => 'pregame']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded', 'output_hash' => str_repeat('a', 64)]);
    $prediction = CanonicalPrediction::factory()->create(['sport' => 'mlb', 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
        'generated_at' => now(), 'output_hash' => str_repeat('a', 64), 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, .65], ['spread', 'home', 0, null], ['total', 'combined', 9, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'market_type' => $type,
            'selection' => $side, 'projected_line' => $line, 'probability' => $probability]);
    }
    $prediction->update(['publication_state' => 'published', 'published_at' => now()]);
    $selected = app(CanonicalShadowInputs::class)->forGames('mlb', $game->id);
    expect($selected)->toHaveCount(1);
    expect($selected->sole()->outputs['predicted_spread'])->toBe(0.0);

    return $selected->sole();
}

it('refuses foreign current final tabular commit authority before writes', function (string $change) {
    config()->set('prediction_lifecycle.canonical_reads.mlb', false);
    $fixture = mlbTabularRunFixture($this->mlbMlTestRoot.'/authority-run');
    $artifact = app(MlbTabularModelRunRegistrar::class)->register($fixture['run_directory'], $fixture['dataset_path']);
    $snapshot = mlbCanonicalSnapshot();
    $features = app(MlbShadowFeatureBuilder::class)->build($snapshot);
    config()->set('mlb_ml.process.command', [PHP_BINARY, mlbTabularFakeCli($this->mlbMlTestRoot.'/authority-cli.php')]);
    $coordinator = app(MlbInferenceOutputCommit::class);
    $prepared = $coordinator->prepare($artifact, $snapshot, $features);
    $output = app(MlbTabularModelInferenceService::class)->predict($artifact, $features);
    expect($output['artifact_id'])->toBe($artifact->id);
    $beforeRuns = ModelRun::query()->where('run_type', 'shadow_inference')->where('status', 'completed')->orderBy('id')->pluck('id')->all();
    $beforeOutputs = ShadowModelOutput::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    $connection = $artifact->getConnection();
    if ($change === 'artifact-owner') {
        // Trusted negative SQL changes ownership only; the new run has no protected proof.
        $other = app(ModelRunRecorder::class)->create(sport: 'mlb', runType: 'training', modelVersion: 'negative-owner-only', featureVersion: 'negative-owner-only', blendVersion: 'negative-owner-only', status: 'completed', completedAt: now());
        $connection->table('model_artifacts')->where('id', $artifact->id)->update(['training_run_id' => $other->id]);
    } elseif ($change === 'target-event-owner') {
        $other = SportEvent::factory()->create(['sport' => 'mlb', 'starts_at' => now()->addDay()]);
        $connection->table('mlb_games')->where('id', $snapshot->game_id)->update(['sport_event_id' => $other->id]);
    } elseif ($change === 'foreign-token') {
        $prepared = new stdClass;
    } elseif ($change === 'foreign-coordinator') {
        $coordinator = app()->build(MlbInferenceOutputCommit::class);
    } elseif ($change === 'inherited-transaction') {
        $connection->beginTransaction();
    }
    $wrote = false;
    $write = function () use (&$wrote): int {
        $wrote = true;

        return 0;
    };
    try {
        expect(fn () => $coordinator->commit($prepared, $output, $write))->toThrow(RuntimeException::class);
    } finally {
        if ($change === 'inherited-transaction') {
            $connection->rollBack();
        }
    }
    expect($wrote)->toBeFalse();
    expect(ShadowModelOutput::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($beforeOutputs);
    expect(ModelRun::query()->where('run_type', 'shadow_inference')->where('status', 'completed')->orderBy('id')->pluck('id')->all())->toBe($beforeRuns);
})->with(['artifact-owner', 'target-event-owner', 'foreign-token', 'foreign-coordinator', 'inherited-transaction']);
