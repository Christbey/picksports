<?php

use App\Application\Sports\ReadModels\CanonicalShadowSnapshot;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\ModelArtifact;
use App\Models\ModelRun;
use App\Models\PredictionMarket;
use App\Models\ShadowModelOutput;
use App\Models\SportEvent;
use App\Services\ML\ModelArtifactRegistry;
use App\Services\MLB\MlbChallengerShadowService;
use App\Services\MLB\MlbInferenceOutputCommit;
use App\Services\MLB\MlbShadowFeatureBuilder;
use App\Services\MLB\MlbTabularArtifactAdmission;
use App\Services\MLB\MlbTabularModelBundle;
use App\Services\MLB\MlbTabularModelInferenceService;
use App\Services\MLB\MlbTabularModelRunRegistrar;
use App\Services\Predictions\CanonicalShadowInputs;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ObservedMlbDatasetFixture;
use Tests\Support\ObservedMlbGameFixture;
use Tests\Support\SyntheticMlbTabularBundleFixture;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    DB::connection()->statement("SET SESSION time_zone = '+00:00'");
    DB::connection()->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    Carbon::setTestNow('2026-07-29T17:00:00Z');
    CarbonImmutable::setTestNow('2026-07-29T17:00:00Z');
    Http::preventStrayRequests();
    Storage::fake('mlb-tabular-artifacts');
    Storage::fake('mlb-tabular-cache');
    config()->set('ml.storage.disk', 'mlb-tabular-artifacts');
    config()->set('ml.storage.cache_disk', 'mlb-tabular-cache');
    config()->set('ml.storage.prefix', 'ml');
    config()->set('filesystems.disks.mlb-tabular-artifacts.driver', 'local');
    config()->set('prediction_lifecycle.canonical_reads.mlb', true);
    $this->mlbMlTestRoot = storage_path('framework/testing/native-final-output-'.Str::uuid());
    config()->set('mlb_ml.bundle.staging_directory', $this->mlbMlTestRoot.'/staging');
    config()->set('mlb_ml.bundle.extraction_directory', $this->mlbMlTestRoot.'/extracted');
    config()->set('mlb_ml.bundle.input_directory', $this->mlbMlTestRoot.'/inputs');
});

afterEach(function () {
    while (DB::connection()->transactionLevel() > 0) {
        DB::connection()->rollBack();
    }
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
    File::deleteDirectory($this->mlbMlTestRoot);
});

it('stores native decimal representation without rounding scientific outputs and retries idempotently', function (string $mode) {
    $fixture = nativeFinalRunFixture($this->mlbMlTestRoot.'/run');
    $artifact = app(MlbTabularModelRunRegistrar::class)->register($fixture['run_directory'], $fixture['dataset_path']);
    $admission = app(MlbTabularArtifactAdmission::class)->admit($artifact);
    $target = nativeFinalCanonicalTarget();
    expect(array_column($admission['proof']['dataset']['references'], 'game_id'))->not->toContain((int) $target->game_id);
    $cli = nativeFinalFakeCli($this->mlbMlTestRoot.'/cli.php');
    File::put($cli, str_replace(['0.614', '1.3', '8.7', '0.558', '0.472', '0.081'],
        ['0.614123456789', '1.234567891234', '8.712345678912', '0.558123456789', '0.472123456789', '0.081123456789'], File::get($cli)));
    config()->set('mlb_ml.process.command', [PHP_BINARY, $cli]);
    config()->set('mlb_ml.shadow.enabled', true);
    config()->set('mlb_ml.shadow.artifact_id', $artifact->id);
    $active = (object) ['value' => true, 'seen' => false, 'trace' => false, 'queries' => [], 'bytes' => 0, 'oversized' => false];
    DB::listen(function ($query) use ($active): void {
        if (! $active->trace || $query->connection->transactionLevel() < 1) {
            return;
        }
        if (count($active->queries) >= 4096 || strlen($query->sql) > 65536 || $active->bytes + strlen($query->sql) > 2097152) {
            $active->oversized = true;

            return;
        }
        $active->bytes += strlen($query->sql);
        $active->queries[] = $query->sql;
    });
    $boundary = new class(app(ModelArtifactRegistry::class), app(MlbTabularModelBundle::class)) extends MlbTabularModelInferenceService
    {
        public int $targetId;

        public string $mode;

        public object $state;

        public bool $ran = false;

        public function predict(ModelArtifact $artifact, array $features): array
        {
            $output = parent::predict($artifact, $features);
            $this->ran = true;
            $this->state->trace = true;
            if ($this->mode === 'target-drift') {
                Game::query()->findOrFail($this->targetId)->update(['status' => 'STATUS_IN_PROGRESS']);
            }

            return $output;
        }
    };
    $boundary->targetId = $target->game_id;
    $boundary->mode = $mode;
    $boundary->state = $active;
    app()->instance(MlbTabularModelInferenceService::class, $boundary);
    if ($mode === 'middle-cancel') {
        ShadowModelOutput::creating(function (ShadowModelOutput $output) use ($active): ?bool {
            if ($active->value && $output->market_type === 'spread') {
                $active->seen = true;

                return false;
            }

            return null;
        });
    }
    $beforeRuns = ModelRun::query()->where('run_type', 'shadow_inference')->where('status', 'completed')->orderBy('id')->pluck('id')->all();
    $beforeRows = DB::table('shadow_model_outputs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    try {
        app(MlbChallengerShadowService::class)->run($artifact->id, $target->game_id);
    } finally {
        $active->value = false;
    }
    $active->trace = false;
    File::put(storage_path('framework/testing/final-output-native-query-'.$mode.'.json'), json_encode(['queries' => $active->queries, 'bytes' => $active->bytes, 'oversized' => $active->oversized], JSON_THROW_ON_ERROR));
    expect($active->oversized)->toBeFalse();
    expect(collect($active->queries)->contains(fn ($sql): bool => preg_match('/select\s+(?:[`"]?mlb_games[`"]?\.)?\*\s+from\s+[`"]?mlb_games/i', $sql) === 1))->toBeFalse();
    $rows = DB::table('shadow_model_outputs')->where('model_artifact_id', $artifact->id)->orderBy('id')->get()->keyBy('market_type');
    if ($mode !== 'unchanged') {
        if ($mode === 'target-drift') {
            expect($boundary->ran)->toBeTrue();
        } else {
            expect($active->seen)->toBeTrue();
        }
        expect(DB::table('shadow_model_outputs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($beforeRows);
        expect(ModelRun::query()->where('run_type', 'shadow_inference')->where('status', 'completed')->orderBy('id')->pluck('id')->all())->toBe($beforeRuns);

        return;
    }
    expect($rows)->toHaveCount(3);
    foreach (['win_probability' => [.65, .614123456789], 'spread' => [0.0, 1.234567891234], 'total' => [9.0, 8.712345678912]] as $market => [$baseline, $challenger]) {
        $expected = DB::connection()->selectOne('SELECT CAST(? AS DECIMAL(12,6)) AS baseline_output, CAST(? AS DECIMAL(12,6)) AS challenger_output, CAST(? AS DECIMAL(12,6)) AS output_delta', [$baseline, $challenger, $challenger - $baseline], false);
        foreach (['baseline_output', 'challenger_output', 'output_delta'] as $field) {
            expect((string) $rows[$market]->$field)->toBe((string) $expected->$field);
        }
        $explanation = json_decode($rows[$market]->explanation, true, 32, JSON_THROW_ON_ERROR);
        expect($explanation['challenger_outputs']['win_probability'])->toBe(.614123456789);
        expect($explanation['challenger_outputs']['predicted_spread'])->toBe(1.234567891234);
    }
    $runIds = ModelRun::query()->where('run_type', 'shadow_inference')->where('status', 'completed')->orderBy('id')->pluck('id')->all();
    expect(count($runIds) - count($beforeRuns))->toBe(1);
    $outputIds = $rows->pluck('id')->all();
    app(MlbChallengerShadowService::class)->run($artifact->id, $target->game_id);
    expect(DB::table('shadow_model_outputs')->where('model_artifact_id', $artifact->id)->orderBy('id')->pluck('id')->all())->toBe($outputIds);
    expect(ModelRun::query()->where('run_type', 'shadow_inference')->where('status', 'completed')->orderBy('id')->pluck('id')->all())->toBe($runIds);
})->with(['unchanged', 'target-drift', 'middle-cancel']);

function nativeFinalRunFixture(string $runDirectory): array
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

function nativeFinalFakeCli(string $path): string
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

function nativeFinalCanonicalTarget(): CanonicalShadowSnapshot
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

it('refuses an inherited native read view instead of treating stale target data as owned', function () {
    $fixture = nativeFinalRunFixture($this->mlbMlTestRoot.'/inherited-run');
    $artifact = app(MlbTabularModelRunRegistrar::class)->register($fixture['run_directory'], $fixture['dataset_path']);
    $target = nativeFinalCanonicalTarget();
    $features = app(MlbShadowFeatureBuilder::class)->build($target);
    config()->set('mlb_ml.process.command', [PHP_BINARY, nativeFinalFakeCli($this->mlbMlTestRoot.'/inherited-cli.php')]);
    $coordinator = app(MlbInferenceOutputCommit::class);
    $prepared = $coordinator->prepare($artifact, $target, $features);
    $output = app(MlbTabularModelInferenceService::class)->predict($artifact, $features);
    $primary = DB::connection();
    config()->set('database.connections.native_final_peer', config('database.connections.'.DB::getDefaultConnection()));
    $peer = DB::connection('native_final_peer');
    $peer->statement("SET SESSION time_zone = '+00:00'");
    $peer->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    expect($peer->getPdo())->not->toBe($primary->getPdo());
    expect($peer->scalar('SELECT CONNECTION_ID()'))->not->toBe($primary->scalar('SELECT CONNECTION_ID()'));
    $beforeRuns = ModelRun::query()->where('run_type', 'shadow_inference')->where('status', 'completed')->orderBy('id')->pluck('id')->all();
    $beforeRows = DB::table('shadow_model_outputs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $wrote = false;
    $write = function () use (&$wrote): int {
        $wrote = true;

        return 0;
    };
    $primary->beginTransaction();
    try {
        expect($primary->table('mlb_games')->where('id', $target->game_id)->value('status'))->toBe('STATUS_SCHEDULED');
        $peer->table('mlb_games')->where('id', $target->game_id)->update(['status' => 'STATUS_IN_PROGRESS']);
        expect($primary->table('mlb_games')->where('id', $target->game_id)->value('status'))->toBe('STATUS_SCHEDULED');
        expect(fn () => $coordinator->commit($prepared, $output, $write))->toThrow(RuntimeException::class);
    } finally {
        $primary->rollBack();
        DB::purge('native_final_peer');
    }
    expect($wrote)->toBeFalse();
    expect($primary->table('mlb_games')->where('id', $target->game_id)->value('status'))->toBe('STATUS_IN_PROGRESS');
    expect(DB::table('shadow_model_outputs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($beforeRows);
    expect(ModelRun::query()->where('run_type', 'shadow_inference')->where('status', 'completed')->orderBy('id')->pluck('id')->all())->toBe($beforeRuns);
});
