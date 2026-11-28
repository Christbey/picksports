<?php

use App\Actions\CFB\GenerateCanonicalPrediction;
use App\Actions\ESPN\CFB\SyncGameMetadata;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Models\SportEvent;
use App\Services\CFB\Predictions\CfbCalculationReleaseRegistrar;
use App\Services\CFB\Predictions\CfbMoneylineCalibrationDataset;
use App\Services\ESPN\CFB\EspnService;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\ML\ShadowModelOutputRecorder;
use App\Services\Predictions\ModelRunRecorder;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if (DB::connection()->getDriverName() !== 'mysql') {
        throw new RuntimeException('Native generic output contract requires owned disposable MySQL.');
    }
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Http::preventStrayRequests();
    Storage::fake('cfb-ml-artifacts');
    Storage::fake('cfb-ml-cache');
    config(['ml.storage.disk' => 'cfb-ml-artifacts', 'ml.storage.cache_disk' => 'cfb-ml-cache', 'filesystems.disks.cfb-ml-artifacts.driver' => 'local']);
});
function nativeGenericSupportedCfbFixture($test): array
{
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
    // Observe this intended synthetic fixture through the real verified-summary boundary.
    // Event wall time alone does not certify the current provider clock.
    $summary = Mockery::mock(EspnService::class);
    $summary->shouldReceive('getGame')->once()->with((string) $game->espn_event_id)->andReturn([
        'header' => ['id' => (string) $game->espn_event_id, 'season' => ['year' => 2026], 'competitions' => [[
            'date' => $startsAt->toIso8601String(), 'dateValid' => true, 'timeValid' => true,
            'status' => ['type' => ['name' => 'STATUS_SCHEDULED']], 'competitors' => [
                ['homeAway' => 'home', 'team' => ['id' => (string) $home->espn_id]],
                ['homeAway' => 'away', 'team' => ['id' => (string) $away->espn_id]],
            ],
        ]]],
    ]);
    expect((new SyncGameMetadata($summary))->execute($game))->toBeTrue();
    $game->refresh();
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

    return [$artifact, $prediction];
}

it('does not acknowledge a cancelled generic output from the supported CFB command', function (string $event) {
    [$artifact] = nativeGenericSupportedCfbFixture($this);
    $dispatcher = ShadowModelOutput::getEventDispatcher();
    ShadowModelOutput::setEventDispatcher(clone $dispatcher);
    $reached = 0;
    ShadowModelOutput::{$event}(function () use (&$reached) {
        $reached++;

        return false;
    });
    $failure = null;
    $code = null;
    try {
        $code = Artisan::call('cfb:record-moneyline-calibration-shadow', ['--artifact' => $artifact->id, '--season' => 2026, '--week' => 1]);
    } catch (Throwable $error) {
        $failure = $error;
    } finally {
        ShadowModelOutput::setEventDispatcher($dispatcher);
    }
    expect($reached)->toBe(1)->and(ShadowModelOutput::query()->count())->toBe(0);
    expect($failure !== null || $code !== 0)->toBeTrue();
    expect(Artisan::call('cfb:record-moneyline-calibration-shadow', ['--artifact' => $artifact->id, '--season' => 2026, '--week' => 1]))->toBe(0)
        ->and(ShadowModelOutput::query()->count())->toBe(1)
        ->and(ShadowModelOutput::query()->firstOrFail()->inferenceRun()->exists())->toBeTrue();
})->with(['creating', 'saving']);

it('does not acknowledge an observer replacing the requested generic output value', function () {
    [$artifact] = nativeGenericSupportedCfbFixture($this);
    $before = DB::table('model_runs')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $dispatcher = ShadowModelOutput::getEventDispatcher();
    ShadowModelOutput::setEventDispatcher(clone $dispatcher);
    $reached = 0;
    ShadowModelOutput::saving(function ($row) use (&$reached) {
        $reached++;
        $row->challenger_output = 0.123456;
    });
    $failure = null;
    $code = null;
    try {
        $code = Artisan::call('cfb:record-moneyline-calibration-shadow', ['--artifact' => $artifact->id, '--season' => 2026, '--week' => 1]);
    } catch (Throwable $error) {
        $failure = $error;
    } finally {
        ShadowModelOutput::setEventDispatcher($dispatcher);
    }
    expect($reached)->toBe(1)->and($failure !== null || $code !== 0)->toBeTrue()->and(ShadowModelOutput::query()->count())->toBe(0);
});

it('stores valid native signed decimal endpoints and refuses unrepresentable differences before new execution rows', function () {
    [$artifact] = nativeGenericSupportedCfbFixture($this);
    expect(Artisan::call('cfb:record-moneyline-calibration-shadow', ['--artifact' => $artifact->id, '--season' => 2026, '--week' => 1]))->toBe(0);
    $snapshot = PredictionFeatureSnapshot::where('sport', 'cfb')->where('prediction_table', 'predictions')->latest('id')->firstOrFail();
    $original = DB::table('model_artifacts')->where('id', $artifact->id)->first();
    foreach ([['win_probability', 0.0, 0.0, true], ['win_probability', 1.0, 1.0, true], ['spread', -4.1234567, -2.1234567, true], ['spread', 0.0, 999999.999999, true], ['total', 0.0, 0.0, true], ['spread', -999999.999999, 999999.999999, false], ['win_probability', 0.5, '1e999', false]] as [$market,$baseline,$challenger,$valid]) {
        DB::table('shadow_model_outputs')->where('prediction_feature_snapshot_id', $snapshot->id)->delete();
        $metadata = $snapshot->model_metadata;
        $metadata['shadow_inference']['market_type'] = $market;
        $metadata['shadow_inference']['baseline_output'] = $baseline;
        $metadata['shadow_inference']['challenger_output'] = $challenger;
        $snapshot->model_metadata = $metadata;
        $runs = DB::table('model_runs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $output = null;
        $error = null;
        try {
            $output = app(ShadowModelOutputRecorder::class)->record($snapshot);
        } catch (Throwable $failure) {
            $error = $failure;
        }
        if (! $valid) {
            expect($output)->toBeNull()->and(ShadowModelOutput::query()->count())->toBe(0)->and(DB::table('model_runs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($runs);

            continue;
        }
        expect($error)->toBeNull()->and($output->exists)->toBeTrue();
        $stored = $output->fresh();
        expect($stored->baseline_output)->toBe(round((float) $baseline, 6))->and($stored->challenger_output)->toBe(round((float) $challenger, 6))->and($stored->output_delta)->toBe(round((float) $challenger - (float) $baseline, 6));
    }
    expect((array) DB::table('model_artifacts')->where('id', $artifact->id)->first())->toBe((array) $original);
});
