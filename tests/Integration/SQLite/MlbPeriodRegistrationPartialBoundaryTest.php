<?php

use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Services\ML\CsvDataset;
use App\Services\MLB\MlbPeriodFeatureBuilder;
use App\Services\MLB\MlbPeriodModelInferenceService;
use App\Services\MLB\MlbPeriodModelRunRegistrar;
use App\Support\MLB\MlbGameStart;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2026-06-30T06:00:00Z'));
    Storage::fake('period-partial-object');
    Storage::fake('period-partial-cache');
    config(['ml.storage.disk' => 'period-partial-object', 'ml.storage.cache_disk' => 'period-partial-cache']);
    $this->periodPartialRoot = storage_path('framework/testing/period-partial-'.Str::uuid());
    File::ensureDirectoryExists($this->periodPartialRoot, 0700, true);
});

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
    File::deleteDirectory($this->periodPartialRoot);
});

function periodPartialState(): array
{
    return ['runs' => DB::table('model_runs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'artifacts' => DB::table('model_artifacts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'objects' => Storage::disk('period-partial-object')->allFiles(), 'cache' => Storage::disk('period-partial-cache')->allFiles()];
}

it('refuses unsupported reconstructed period registration without SQL or storage partials', function (bool $workerDeclaresAvailable) {
    Game::factory()->withSyntheticWallStart('America/Chicago')->create([
        'home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'season_type' => '2', 'game_date' => '2026-06-28', 'game_time' => '19:10:00', 'status' => 'STATUS_FINAL',
        'home_score' => 5, 'away_score' => 3, 'home_linescores' => [1, 1, 1, 1, 1, 0, 0, 0, 0], 'away_linescores' => [1, 0, 1, 0, 1, 0, 0, 0, 0],
    ]);
    $rows = app(MlbPeriodFeatureBuilder::class)->historicalRows([2026]);
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        expect($row['pregame_safe'])->toBeFalse()->and($row['availability_status'])->toBe('reconstructed_now');
    }
    $dataset = $this->periodPartialRoot.'/dataset.csv';
    expect(app(CsvDataset::class)->write($dataset, $rows))->toBe(2);
    $bundle = $this->periodPartialRoot.'/period_bundle.json';
    // Explicitly negative, nonexecuted model container. Worker booleans cannot upgrade retrospective input.
    File::put($bundle, '{"synthetic_negative_container":true}');
    $evaluation = $this->periodPartialRoot.'/evaluation.json';
    File::put($evaluation, '{"markets":[],"promotion_summary":{"eligible":false}}');
    $manifest = $this->periodPartialRoot.'/manifest.json';
    File::put($manifest, json_encode(['execution_format' => MlbPeriodModelInferenceService::EXECUTION_FORMAT,
        'timing_policy' => MlbGameStart::TIMING_POLICY, 'source_availability_verified' => $workerDeclaresAvailable,
        'artifact_id' => (string) Str::uuid(), 'model_run_id' => (string) Str::uuid(),
        'model_version' => 'mlb-period-v1', 'feature_schema_version' => 'mlb-period-test-v1',
        'config_hash' => hash('sha256', 'synthetic-negative-period'), 'dataset_hash' => hash_file('sha256', $dataset),
        'artifact_hash' => hash_file('sha256', $bundle), 'seed' => 42, 'training_seasons' => [2026],
        'markets' => ['first_3_moneyline', 'first_5_moneyline']], JSON_THROW_ON_ERROR));
    $before = periodPartialState();
    $failure = null;
    try {
        app(MlbPeriodModelRunRegistrar::class)->register($bundle, $manifest, $evaluation, $dataset);
    } catch (RuntimeException $error) {
        $failure = $error;
    }
    expect($failure)->toBeInstanceOf(RuntimeException::class)->and(periodPartialState())->toBe($before);
    Http::assertNothingSent();
})->with(['negative worker declaration' => false, 'unsupported worker claim over reconstructed source' => true]);

it('refuses nominal worker flags without a reviewed period source and selection protocol before any writes', function () {
    $dataset = $this->periodPartialRoot.'/claimed.csv';
    // An explicit unqualified declaration, not flags retrofitted onto real historical rows.
    File::put($dataset, "pregame_safe,availability_status\n1,observed_pregame\n");
    $bundle = $this->periodPartialRoot.'/claimed.json';
    File::put($bundle, '{"synthetic_unqualified_declaration":true}');
    $evaluation = $this->periodPartialRoot.'/claimed-evaluation.json';
    File::put($evaluation, '{}');
    $manifest = $this->periodPartialRoot.'/claimed-manifest.json';
    File::put($manifest, json_encode(['execution_format' => MlbPeriodModelInferenceService::EXECUTION_FORMAT,
        'timing_policy' => MlbGameStart::TIMING_POLICY, 'source_availability_verified' => true,
        'artifact_id' => (string) Str::uuid(), 'model_run_id' => (string) Str::uuid(),
        'model_version' => 'mlb-period-v1', 'feature_schema_version' => 'mlb-period-test-v1',
        'config_hash' => hash('sha256', 'unqualified-period'), 'dataset_hash' => hash_file('sha256', $dataset),
        'artifact_hash' => hash_file('sha256', $bundle), 'seed' => 42, 'training_seasons' => [2026],
        'markets' => ['first_3_moneyline', 'first_5_moneyline']], JSON_THROW_ON_ERROR));
    $before = periodPartialState();
    expect(fn () => app(MlbPeriodModelRunRegistrar::class)->register($bundle, $manifest, $evaluation, $dataset))
        ->toThrow(RuntimeException::class, 'reviewed period source-proof and selection protocol');
    expect(periodPartialState())->toBe($before);
});
