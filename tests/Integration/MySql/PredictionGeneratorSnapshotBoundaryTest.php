<?php

use App\Actions\MLB\GeneratePrediction;
use App\Actions\Sports\AbstractPredictionGenerator;
use App\Jobs\Predictions\GeneratePredictionNarrative;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\Team;
use App\Models\ModelRun;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Predictions\ModelRunRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ObservedMlbGameFixture;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    expect(DB::connection()->getDriverName())->toBe('mysql');
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-11-27T12:00:00-06:00'));
    config(['prediction_lifecycle.canonical_pipeline.cbb' => false, 'prediction_lifecycle.canonical_pipeline.mlb' => false]);
    Http::preventStrayRequests();
    Queue::fake();
    expect(DB::transactionLevel())->toBe(0);
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $this->travelBack();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

function nativeControlledCbbGenerator(string $generatedAt): AbstractPredictionGenerator
{
    return new class($generatedAt) extends AbstractPredictionGenerator
    {
        protected const SPORT_KEY = 'cbb';

        protected const PREDICTION_MODEL = Prediction::class;

        public function __construct(private readonly string $generatedAt) {}

        protected function calculatePredictedSpread(int $homeElo, int $awayElo, ?Model $homeMetrics, ?Model $awayMetrics, Model $game): float
        {
            return 3.5;
        }

        protected function calculatePredictedTotal(?Model $homeMetrics, ?Model $awayMetrics, Model $game): float
        {
            return 140.5;
        }

        protected function makePredictionData(Model $game, bool $allowCompleted = false): ?array
        {
            return ['home_elo' => 1500, 'away_elo' => 1490, 'predicted_spread' => 3.5, 'predicted_total' => 140.5,
                'win_probability' => 0.6, 'confidence_score' => 60, 'model_version' => 'synthetic-generator',
                'feature_version' => 'synthetic-core', 'blend_version' => 'synthetic-baseline',
                '_snapshot' => ['generated_at' => $this->generatedAt]];
        }
    };
}

function nativeGeneratorBoundaryGame(bool $historical): Game
{
    return Game::factory()->create(['home_team_id' => Team::factory()->create()->id, 'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'status' => $historical ? 'STATUS_FINAL' : 'STATUS_SCHEDULED',
        'game_date' => $historical ? '2026-11-26' : '2026-11-28', 'game_time' => '18:00:00',
        'home_score' => $historical ? 80 : null, 'away_score' => $historical ? 70 : null]);
}

it('preserves prediction rows when the real recorder refuses snapshot time', function (bool $historical, bool $existing) {
    $game = nativeGeneratorBoundaryGame($historical);
    if ($existing) {
        Prediction::create(['game_id' => $game->id, 'predicted_spread' => 1.5, 'predicted_total' => 130.5,
            'model_version' => 'prior-record', 'model_metadata' => ['retain' => 'original']]);
    }
    $before = DB::table('cbb_predictions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $action = nativeControlledCbbGenerator('not-a-calendar-clock');
    expect(fn () => $historical ? $action->executeHistorical($game, true) : $action->execute($game, true))
        ->toThrow(InvalidArgumentException::class, 'Snapshot timestamps require a valid explicit calendar date and clock.');
    expect(PredictionFeatureSnapshot::count())->toBe(0)->and(ModelRun::count())->toBe(0)->and(DB::transactionLevel())->toBe(0);
    Queue::assertNothingPushed();
    expect(DB::table('cbb_predictions')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
})->with(['current' => false, 'historical' => true])->with(['new' => false, 'existing' => true]);

it('persists a valid prediction with the real run snapshot and queued narrative', function (bool $historical) {
    $game = nativeGeneratorBoundaryGame($historical);
    $action = nativeControlledCbbGenerator(now()->toIso8601String());
    $prediction = $historical ? $action->executeHistorical($game, true) : $action->execute($game, true);
    $snapshot = PredictionFeatureSnapshot::where('prediction_table', 'cbb_predictions')->where('prediction_id', $prediction->id)->sole();
    expect(Prediction::count())->toBe(1)->and(ModelRun::whereKey($snapshot->model_run_id)->exists())->toBeTrue()
        ->and((float) $snapshot->outputs['predicted_spread'])->toBe(3.5)->and(DB::transactionLevel())->toBe(0);
    if ($historical) {
        expect($snapshot->pregame_safe)->toBeFalse()->and($snapshot->lineage_metadata['run_type'])->toBe('historical_reconstruction');
    }
    Queue::assertPushed(GeneratePredictionNarrative::class, 1);
    Http::assertNothingSent();
})->with(['current' => false, 'historical' => true]);

it('defers narrative identity until the outer transaction commits', function () {
    $game = nativeGeneratorBoundaryGame(false);
    DB::beginTransaction();
    try {
        $prediction = nativeControlledCbbGenerator(now()->toIso8601String())->execute($game, true);
        $id = $prediction->id;
        Queue::assertNothingPushed();
        $prediction->id = 999999;
        DB::commit();
        Queue::assertPushed(GeneratePredictionNarrative::class, fn ($job) => $job->predictionId === $id);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
});

it('suppresses narrative and all new rows when an inherited transaction rolls back', function () {
    $game = nativeGeneratorBoundaryGame(false);
    DB::beginTransaction();
    nativeControlledCbbGenerator(now()->toIso8601String())->execute($game, true);
    Queue::assertNothingPushed();
    DB::rollBack();
    Queue::assertNothingPushed();
    expect(Prediction::count())->toBe(0)->and(PredictionFeatureSnapshot::count())->toBe(0)->and(ModelRun::count())->toBe(0);
});

it('rolls back a late snapshot failure and retries with the same recorder', function () {
    $game = nativeGeneratorBoundaryGame(false);
    $action = nativeControlledCbbGenerator(now()->toIso8601String());
    $fail = true;
    $observedRun = null;
    $dispatcher = PredictionFeatureSnapshot::getEventDispatcher();
    PredictionFeatureSnapshot::setEventDispatcher(clone $dispatcher);
    PredictionFeatureSnapshot::creating(function ($snapshot) use (&$fail, &$observedRun) {
        $observedRun = $snapshot->model_run_id;
        expect(ModelRun::whereKey($observedRun)->exists())->toBeTrue();
        if ($fail) {
            throw new RuntimeException('synthetic late snapshot refusal');
        }
    });
    try {
        expect(fn () => $action->execute($game, true))->toThrow(RuntimeException::class, 'synthetic late snapshot refusal');
        $failedRun = $observedRun;
        expect([Prediction::count(), PredictionFeatureSnapshot::count(), ModelRun::count()])->toBe([0, 0, 0]);
        Queue::assertNothingPushed();
        $fail = false;
        $prediction = $action->execute($game, true);
        expect(Prediction::count())->toBe(1)->and(PredictionFeatureSnapshot::count())->toBe(1)->and(ModelRun::count())->toBe(1)
            ->and($observedRun)->not->toBe($failedRun)->and($prediction->exists)->toBeTrue();
        Queue::assertPushed(GeneratePredictionNarrative::class, 1);
    } finally {
        PredictionFeatureSnapshot::setEventDispatcher($dispatcher);
    }
});

it('preserves genuine MLB current capture and historical reconstruction', function (bool $historical) {
    $home = App\Models\MLB\Team::factory()->create(['elo_rating' => 1510]);
    $away = App\Models\MLB\Team::factory()->create(['elo_rating' => 1500]);
    $game = App\Models\MLB\Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED']);
    $game = ObservedMlbGameFixture::observe($game, now()->addDay());
    if ($historical) {
        $game->update(['status' => 'STATUS_FINAL', 'home_score' => 5, 'away_score' => 4]);
    }
    $action = app(GeneratePrediction::class);
    $prediction = $historical ? $action->executeHistorical($game->fresh(['homeTeam', 'awayTeam'])) : $action->execute($game->fresh(['homeTeam', 'awayTeam']), false);
    $snapshot = PredictionFeatureSnapshot::where('prediction_table', 'mlb_predictions')->where('prediction_id', $prediction->id)->sole();
    expect(ModelRun::whereKey($snapshot->model_run_id)->exists())->toBeTrue()->and(DB::transactionLevel())->toBe(0);
    if ($historical) {
        expect($snapshot->pregame_safe)->toBeFalse()->and($snapshot->availability_status)->toBe('reconstructed_now');
    } else {
        expect(data_get($snapshot->model_metadata, 'mlb_capture_integrity.qualified'))->toBeTrue()->and($snapshot->pregame_safe)->toBeTrue();
    }
    Http::assertNothingSent();
})->with(['current' => false, 'historical' => true]);

it('stages code version outside SQL and binds the stored run metadata', function () {
    $game = nativeGeneratorBoundaryGame(false);
    $prior = app(ModelRunRecorder::class);
    $recorder = new class extends ModelRunRecorder
    {
        public array $levels = [];

        public function codeVersion(): ?string
        {
            $this->levels[] = DB::transactionLevel();

            return str_repeat('b', 40);
        }
    };
    app()->instance(ModelRunRecorder::class, $recorder);
    try {
        nativeControlledCbbGenerator(now()->toIso8601String())->execute($game, false);
        $run = ModelRun::sole();
        expect($recorder->levels)->toBe([0])->and($run->code_version)->toBe(str_repeat('b', 40))
            ->and($run->config_hash)->toBe($recorder->configHash('cbb'));
    } finally {
        app()->instance(ModelRunRecorder::class, $prior);
    }
});

it('refuses changed prepared configuration and rolls back the prediction', function () {
    $game = nativeGeneratorBoundaryGame(false);
    $prior = config('cbb');
    $dispatcher = Prediction::getEventDispatcher();
    Prediction::setEventDispatcher(clone $dispatcher);
    Prediction::saving(function () {
        config(['cbb.synthetic_context_drift' => 'changed during saving']);
    });
    try {
        expect(fn () => nativeControlledCbbGenerator(now()->toIso8601String())->execute($game, true))
            ->toThrow(InvalidArgumentException::class, 'Prepared prediction run context changed; retry the complete calculation.');
        expect([Prediction::count(), PredictionFeatureSnapshot::count(), ModelRun::count()])->toBe([0, 0, 0]);
        Queue::assertNothingPushed();
    } finally {
        config(['cbb' => $prior]);
        Prediction::setEventDispatcher($dispatcher);
    }
});

it('refuses ordinary save cancellation without changing its owned atomic rows', function (string $cancel, bool $existing) {
    $game = nativeGeneratorBoundaryGame(false);
    if ($existing) {
        Prediction::create(['game_id' => $game->id, 'predicted_spread' => 1.5, 'predicted_total' => 130.5,
            'model_version' => 'prior-record', 'model_metadata' => ['retain' => 'original']]);
    }
    $raw = static fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $expected = ['refused' => true, 'predictions' => $raw('cbb_predictions'), 'runs' => $raw('model_runs'),
        'snapshots' => $raw('prediction_feature_snapshots'), 'queued' => 0];
    $dispatcher = Prediction::getEventDispatcher();
    Prediction::setEventDispatcher(clone $dispatcher);
    if ($cancel === 'prediction') {
        Prediction::saving(fn () => false);
    } else {
        PredictionFeatureSnapshot::creating(fn () => false);
    }
    $failure = null;
    try {
        try {
            nativeControlledCbbGenerator(now()->toIso8601String())->execute($game, true);
        } catch (Throwable $error) {
            $failure = $error;
        }
        $actual = ['refused' => $failure !== null, 'predictions' => $raw('cbb_predictions'), 'runs' => $raw('model_runs'),
            'snapshots' => $raw('prediction_feature_snapshots'), 'queued' => Queue::pushed(GeneratePredictionNarrative::class)->count()];
        expect($actual)->toBe($expected);
    } finally {
        Prediction::setEventDispatcher($dispatcher);
    }
})->with(['new prediction' => ['prediction', false], 'existing prediction' => ['prediction', true], 'new snapshot' => ['snapshot', false]]);

it('owns the Game row before the Prediction saving event and SQL insert', function () {
    $game = nativeGeneratorBoundaryGame(false);
    config(['database.connections.generator_native_peer' => config('database.connections.'.DB::getDefaultConnection())]);
    $peer = DB::connection('generator_native_peer');
    $peer->statement('SET SESSION innodb_lock_wait_timeout = 1');
    expect($peer->getPdo())->not->toBe(DB::connection()->getPdo());
    $dispatcher = Prediction::getEventDispatcher();
    Prediction::setEventDispatcher(clone $dispatcher);
    $reached = false;
    Prediction::saving(function () use ($peer, $game, &$reached) {
        $peer->beginTransaction();
        try {
            $peer->table('cbb_games')->where('id', $game->id)->update(['status' => 'STATUS_DELAYED']);
            throw new RuntimeException('Peer Game update unexpectedly acquired the owning row.');
        } catch (QueryException $error) {
            expect((int) ($error->errorInfo[1] ?? 0))->toBe(1205)
                ->and($error->getSql())->toContain('cbb_games');
            $reached = true;
        } finally {
            while ($peer->transactionLevel() > 0) {
                $peer->rollBack();
            }
        }
    });
    try {
        $prediction = nativeControlledCbbGenerator(now()->toIso8601String())->execute($game, false);
        expect($reached)->toBeTrue()->and($prediction->exists)->toBeTrue()
            ->and($game->fresh()->status)->toBe('STATUS_SCHEDULED')
            ->and(PredictionFeatureSnapshot::count())->toBe(1);
    } finally {
        Prediction::setEventDispatcher($dispatcher);
        while ($peer->transactionLevel() > 0) {
            $peer->rollBack();
        }
        DB::purge('generator_native_peer');
    }
});
