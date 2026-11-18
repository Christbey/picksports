<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Services\BettingRecommendations\NflPropProjectionBacktest;
use App\Services\NFL\NflPropShadowBundle;
use App\Services\NFL\NflPropShadowFeatures;
use App\Services\NFL\NflPropShadowGrader;
use App\Services\NFL\NflPropShadowService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-23 18:00:00', 'UTC'));
    $this->team = Team::factory()->create();
    $this->opponent = Team::factory()->create();
    $this->player = Player::factory()->create(['team_id' => $this->team->id, 'position' => 'RB']);
    $this->game = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => $this->opponent->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-10-24', 'game_time' => '17:00:00']);
    $this->prop = PlayerProp::create(['game_id' => $this->game->id, 'player_id' => $this->player->id,
        'player_name' => $this->player->full_name, 'market' => 'player_rush_attempts', 'line' => 12.5,
        'bookmaker' => 'draftkings', 'over_price' => -110, 'under_price' => -110, 'fetched_at' => now()]);
    foreach (['2026-10-04', '2026-10-11', '2026-10-18'] as $date) {
        $game = Game::factory()->create(['home_team_id' => $this->team->id, 'away_team_id' => $this->opponent->id,
            'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'game_date' => $date, 'game_time' => '17:00:00']);
        PlayerStat::factory()->create(['game_id' => $game->id, 'player_id' => $this->player->id, 'team_id' => $this->team->id,
            'rushing_attempts' => 15, 'rushing_yards' => 60]);
    }
    $props = new Collection([$this->prop->load(['player', 'game'])]);
    $this->sample = app(NflPropShadowFeatures::class)->forProps($props)[$this->prop->id];
    $names = array_keys($this->sample['features']);
    $this->bundle = ['format' => 'nfl-prop-shadow-bundle-v1', 'feature_version' => NflPropShadowFeatures::VERSION,
        'frozen_at' => '2026-10-22T00:00:00Z', 'dataset_sha256' => str_repeat('a', 64), 'production_promotion' => false,
        'features' => $names, 'markets' => ['player_rush_attempts' => [
            'training_labels_available_at' => '2026-10-21T00:00:00Z', 'nonnegative' => true,
            'ridge' => ['intercept' => 14.0, 'imputation' => array_fill(0, count($names), 0),
                'mean' => array_fill(0, count($names), 0), 'scale' => array_fill(0, count($names), 1), 'coefficients' => array_fill(0, count($names), 0)],
            'xgboost' => ['base_score' => 10, 'trees' => [['nodeid' => 0, 'split' => 'feature_recent5_mean', 'split_condition' => 10,
                'yes' => 1, 'no' => 2, 'missing' => 1, 'children' => [['nodeid' => 1, 'leaf' => -2], ['nodeid' => 2, 'leaf' => 2]]]]],
        ]]];
    $this->path = tempnam(sys_get_temp_dir(), 'nfl-shadow-test-');
    $this->configureBundle = function () {
        File::put($this->path, json_encode($this->bundle, JSON_THROW_ON_ERROR));
        config(['nfl.props.shadow.enabled' => true, 'nfl.props.shadow.bundle_path' => $this->path,
            'nfl.props.shadow.bundle_sha256' => hash_file('sha256', $this->path)]);
    };
    ($this->configureBundle)();
    $this->runShadow = fn () => app(NflPropShadowService::class)->run(2026, $this->game->id);
});

afterEach(function () {
    File::delete($this->path);
});

it('records one immutable paired observation across books lines and repeated runs without changing production props', function () {
    $before = $this->prop->fresh()->getAttributes();
    expect(($this->runShadow)()['created'])->toBe(1);
    $snapshot = PredictionFeatureSnapshot::where('blend_version', NflPropShadowService::POLICY)->sole();
    expect($snapshot->outputs['ridge'])->toEqual(14)->and($snapshot->outputs['xgboost'])->toEqual(12)
        ->and($snapshot->outputs['recent5_mean'])->toEqual(15)
        ->and($snapshot->generated_at->lt($snapshot->game_start_at))->toBeTrue()
        ->and($this->prop->fresh()->getAttributes())->toBe($before);
    $frozen = $snapshot->getAttributes();
    $this->prop->update(['line' => 100.5, 'over_price' => 150]);
    expect(($this->runShadow)()['created'])->toBe(0)
        ->and($snapshot->fresh()->getAttributes())->toBe($frozen);
    expect(fn () => $snapshot->update(['outputs' => ['ridge' => 99]]))->toThrow(LogicException::class);
    expect(fn () => $snapshot->delete())->toThrow(LogicException::class);
});

it('keeps versioned fingerprints stable across JSON number representation and PHP precision settings', function () {
    $precision = ini_get('serialize_precision');
    $features = ['mean' => 31.25158548, 'count' => 2.0, 'zero' => -0.0];
    $outputs = ['ridge' => 34.90915802];
    $market = ['line' => 32.5, 'price' => -110];
    try {
        ini_set('serialize_precision', '-1');
        $expected = NflPropShadowService::fingerprint($features, $outputs, $market);
        $features = ['zero' => 0, 'count' => 2, 'mean' => 31.25158548];
        ini_set('serialize_precision', '14');
        expect(NflPropShadowService::fingerprint($features, $outputs, $market))->toBe($expected);
        $outputs['ridge'] += 0.001;
        expect(NflPropShadowService::fingerprint($features, $outputs, $market))->not->toBe($expected);
        $outputs['ridge'] = 34.909158021;
        expect(fn () => NflPropShadowService::fingerprint($features, $outputs, $market))->toThrow(InvalidArgumentException::class, 'numeric resolution');
    } finally {
        ini_set('serialize_precision', $precision);
    }
});

it('quantizes captured values before hashing and rejects sub-resolution changes to the stored payload', function () {
    $this->partialMock(NflPropShadowBundle::class, fn ($mock) => $mock->shouldReceive('predict')->once()
        ->andReturn(['ridge' => 34.90915802242381, 'xgboost' => 34.296099177652984]));
    ($this->runShadow)();
    $snapshot = PredictionFeatureSnapshot::where('blend_version', NflPropShadowService::POLICY)->sole();
    expect($snapshot->outputs['ridge'])->toBe(34.90915802)
        ->and($snapshot->outputs['xgboost'])->toBe(34.29609918)
        ->and($snapshot->model_metadata['fingerprint_version'])->toBe(NflPropShadowService::FINGERPRINT_VERSION);
    $outputs = $snapshot->outputs;
    $outputs['ridge'] += 0.000000001;
    DB::table('prediction_feature_snapshots')->where('id', $snapshot->id)->update(['outputs' => json_encode($outputs)]);
    $this->game->update(['status' => 'STATUS_FINAL']);
    $this->travelTo(now()->addDays(2));

    $result = app(NflPropShadowGrader::class)->grade(2026);
    expect($result['invalid_snapshots'])->toBe([$snapshot->id => 'snapshot_numeric_resolution_mismatch'])
        ->and(PredictionEvaluation::where('blend_version', NflPropShadowService::POLICY)->count())->toBe(0);
});

it('distinguishes numeric scalars from strings and maps that resemble the canonical number encoding', function () {
    $hash = NflPropShadowService::fingerprint(['value' => 1], [], []);
    expect(NflPropShadowService::fingerprint(['value' => ['number' => '1.00000000']], [], []))->not->toBe($hash)
        ->and(NflPropShadowService::fingerprint(['value' => '1.00000000'], [], []))->not->toBe($hash);
    ($this->runShadow)();
    $snapshot = PredictionFeatureSnapshot::where('blend_version', NflPropShadowService::POLICY)->sole();
    $outputs = $snapshot->outputs;
    $outputs['ridge'] = ['number' => sprintf('%.8F', $outputs['ridge'])];
    DB::table('prediction_feature_snapshots')->where('id', $snapshot->id)->update(['outputs' => json_encode($outputs)]);
    $this->game->update(['status' => 'STATUS_FINAL']);
    $this->travelTo(now()->addDays(2));

    expect(app(NflPropShadowGrader::class)->grade(2026)['invalid_snapshots'])
        ->toBe([$snapshot->id => 'invalid_snapshot_payload'])
        ->and(PredictionEvaluation::where('blend_version', NflPropShadowService::POLICY)->count())->toBe(0);
});

it('isolates an invalid frozen snapshot without starving valid later observations or approving the failed run', function (string $field) {
    ($this->runShadow)();
    $snapshot = PredictionFeatureSnapshot::where('blend_version', NflPropShadowService::POLICY)->sole();
    $attributes = $snapshot->getAttributes();
    unset($attributes['id']);
    $attributes['snapshot_run_id'] = (string) Str::uuid();
    $validId = DB::table('prediction_feature_snapshots')->insertGetId($attributes);
    $corrupt = $field === 'feature_hash' ? str_repeat('f', 64) : json_encode('invalid scalar payload');
    DB::table('prediction_feature_snapshots')->where('id', $snapshot->id)->update([$field => $corrupt]);
    $this->game->update(['status' => 'STATUS_FINAL']);
    $this->travelTo(now()->addDays(2));
    PlayerStat::factory()->create(['game_id' => $this->game->id, 'player_id' => $this->player->id,
        'team_id' => $this->team->id, 'rushing_attempts' => 8]);

    $result = app(NflPropShadowGrader::class)->grade(2026);
    expect($result['graded'])->toBe(1)
        ->and($result['invalid_snapshots'])->toBe([$snapshot->id => $field === 'feature_hash' ? 'snapshot_integrity_mismatch' : 'invalid_snapshot_payload'])
        ->and(PredictionEvaluation::where('blend_version', NflPropShadowService::POLICY)->sole()->prediction_id)->toBe($validId)
        ->and($snapshot->fresh()->getRawOriginal($field))->toBe($corrupt);
    $this->artisan('nfl:grade-prop-shadow --season=2026')->expectsOutputToContain('invalid_snapshot_count')->assertFailed();
})->with(['feature_hash', 'features', 'outputs', 'market_context', 'model_metadata']);

it('isolates incomplete grading payloads and still grades a later valid snapshot', function (string $case) {
    ($this->runShadow)();
    $snapshot = PredictionFeatureSnapshot::where('blend_version', NflPropShadowService::POLICY)->sole();
    $attributes = $snapshot->getAttributes();
    unset($attributes['id']);
    $attributes['snapshot_run_id'] = (string) Str::uuid();
    $validId = DB::table('prediction_feature_snapshots')->insertGetId($attributes);
    $metadata = $snapshot->model_metadata;
    $market = $snapshot->market_context;
    $changes = match ($case) {
        'empty_metadata' => ['model_metadata' => []],
        'missing_player' => ['model_metadata' => array_diff_key($metadata, ['player_id' => true])],
        'invalid_team' => ['model_metadata' => [...$metadata, 'team_id' => 0]],
        'invalid_market' => ['market_context' => [...$market, 'market' => []]],
        'missing_line' => ['market_context' => array_diff_key($market, ['line' => true])],
        'nonfinite_line' => ['market_context' => [...$market, 'line' => '1e999']],
        'empty_outputs' => ['outputs' => []],
        'invalid_output' => ['outputs' => ['ridge' => ['invalid' => true]]],
        'nonfinite_output' => ['outputs' => ['ridge' => '1e999']],
        'overflow_output' => ['outputs' => ['ridge' => 1e308]],
    };
    $encoded = array_map(fn ($value) => json_encode($value, JSON_THROW_ON_ERROR), $changes);
    if ($case === 'overflow_output') {
        $encoded['feature_hash'] = NflPropShadowService::fingerprint($snapshot->features, $changes['outputs'], $market);
    }
    DB::table('prediction_feature_snapshots')->where('id', $snapshot->id)->update($encoded);
    $this->game->update(['status' => 'STATUS_FINAL']);
    $this->travelTo(now()->addDays(2));
    PlayerStat::factory()->create(['game_id' => $this->game->id, 'player_id' => $this->player->id,
        'team_id' => $this->team->id, 'rushing_attempts' => 8]);

    $result = app(NflPropShadowGrader::class)->grade(2026);
    expect($result['graded'])->toBe(1)
        ->and($result['invalid_snapshots'])->toBe([$snapshot->id => 'invalid_snapshot_payload'])
        ->and(PredictionEvaluation::where('blend_version', NflPropShadowService::POLICY)->sole()->prediction_id)->toBe($validId);
    $this->artisan('nfl:grade-prop-shadow --season=2026')->assertFailed();
})->with(['empty_metadata', 'missing_player', 'invalid_team', 'invalid_market', 'missing_line', 'nonfinite_line', 'empty_outputs', 'invalid_output', 'nonfinite_output', 'overflow_output']);

it('verifies legacy fingerprints exactly without rewriting immutable observations', function () {
    ($this->runShadow)();
    $snapshot = PredictionFeatureSnapshot::where('blend_version', NflPropShadowService::POLICY)->sole();
    $metadata = $snapshot->model_metadata;
    unset($metadata['fingerprint_version']);
    $hash = NflPropShadowService::fingerprint($snapshot->features, $snapshot->outputs, $snapshot->market_context, NflPropShadowService::LEGACY_FINGERPRINT_VERSION);
    DB::table('prediction_feature_snapshots')->where('id', $snapshot->id)->update(['model_metadata' => json_encode($metadata), 'feature_hash' => $hash]);
    $this->game->update(['status' => 'STATUS_FINAL']);
    $this->travelTo(now()->addDays(2));

    $result = app(NflPropShadowGrader::class)->grade(2026);
    expect($result['pending_missing_actual'])->toBe(1)->and($result['invalid_snapshot_count'])->toBe(0)
        ->and($snapshot->fresh()->feature_hash)->toBe($hash);
});

it('rejects in-play observations stale or future quotes and targets outside the 24 hour enrollment window', function (string $case) {
    match ($case) {
        'in_play' => $this->game->update(['status' => 'STATUS_IN_PROGRESS']),
        'stale' => $this->prop->update(['fetched_at' => now()->subDays(3)]),
        'future' => $this->prop->update(['fetched_at' => now()->addMinute()]),
        'outside_window' => $this->game->update(['game_date' => '2026-10-26']),
    };
    expect(($this->runShadow)()['created'])->toBe(0)
        ->and(PredictionFeatureSnapshot::where('blend_version', NflPropShadowService::POLICY)->count())->toBe(0);
})->with(['in_play', 'stale', 'future', 'outside_window']);

it('fails closed on bundle tampering and future training outcomes', function () {
    File::append($this->path, ' ');
    expect(fn () => ($this->runShadow)())->toThrow(RuntimeException::class, 'hash mismatch');
    $this->bundle['markets']['player_rush_attempts']['training_labels_available_at'] = '2026-10-25T00:00:00Z';
    ($this->configureBundle)();
    expect(fn () => ($this->runShadow)())->toThrow(RuntimeException::class, 'future training');
});

it('grades the frozen line once and leaves missing actuals pending without changing frozen inputs', function () {
    ($this->runShadow)();
    $snapshot = PredictionFeatureSnapshot::where('blend_version', NflPropShadowService::POLICY)->sole();
    $frozen = $snapshot->getAttributes();
    $this->prop->update(['line' => 100]);
    $this->game->update(['status' => 'STATUS_FINAL']);
    $this->travelTo(now()->addDays(2));
    $grader = app(NflPropShadowGrader::class);
    expect($grader->grade(2026)['pending_missing_actual'])->toBe(1);
    $stat = PlayerStat::factory()->create(['game_id' => $this->game->id, 'player_id' => $this->player->id,
        'team_id' => $this->team->id, 'rushing_attempts' => null]);
    expect($grader->grade(2026)['pending_missing_actual'])->toBe(1);
    $stat->update(['rushing_attempts' => 0]);
    expect($grader->grade(2026)['graded'])->toBe(1);
    $evaluation = PredictionEvaluation::where('blend_version', NflPropShadowService::POLICY)->sole();
    expect($evaluation->actuals['value'])->toEqual(0)
        ->and($evaluation->market_comparison['line'])->toEqual(12.5)
        ->and($evaluation->errors['ridge']['absolute_error'])->toEqual(14)
        ->and($snapshot->fresh()->getAttributes())->toBe($frozen)
        ->and($this->prop->fresh()->graded_at)->toBeNull();
    $stat->update(['rushing_attempts' => 99]);
    expect($grader->grade(2026)['graded'])->toBe(0)->and($evaluation->fresh()->actuals['value'])->toEqual(0);
    expect(fn () => $evaluation->update(['actuals' => ['value' => 99]]))->toThrow(LogicException::class);
    expect(fn () => $evaluation->delete())->toThrow(LogicException::class);
    expect(Artisan::call('nfl:report-prop-shadow', ['--season' => 2026]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['experiments'][0]['models']['ridge']['mae'])->toEqual(14)
        ->and($report['production_promotion'])->toBeFalse();
});

it('uses float32 tree thresholds missing branches and ridge imputation correctly', function () {
    $features = $this->sample['features'];
    $features['feature_recent5_mean'] = null;
    $result = app(NflPropShadowBundle::class)->predict($this->bundle, 'player_rush_attempts', $features);
    expect($result['xgboost'])->toEqual(8)->and($result['ridge'])->toEqual(14);
    $features['feature_recent5_mean'] = 10;
    expect(app(NflPropShadowBundle::class)->predict($this->bundle, 'player_rush_attempts', $features)['xgboost'])->toEqual(12);
});

it('registers capture grading and weekly reporting schedules behind the experiment flag', function () {
    $events = collect(app(Schedule::class)->events());
    $capture = $events->first(fn ($event) => str_contains($event->command ?? '', 'nfl:snapshot-prop-shadow'));
    $grade = $events->first(fn ($event) => str_contains($event->command ?? '', 'nfl:grade-prop-shadow'));
    $report = $events->first(fn ($event) => str_contains($event->command ?? '', 'nfl:report-prop-shadow'));
    expect($capture->expression)->toBe('15,45 8-23 * * *')
        ->and($grade->expression)->toBe('45 8 * * *')
        ->and($report->expression)->toBe('55 8 * * 1');
    config(['nfl.props.shadow.enabled' => false]);
    expect($capture->filtersPass(app()))->toBeFalse()
        ->and(($this->runShadow)()['status'])->toBe('disabled');
});

it('matches actual Python ridge and XGBoost predictions from the same frozen numeric bundle', function () {
    $fixture = json_decode(File::get(base_path('tests/Fixtures/nfl-prop-shadow-parity.json')), true, flags: JSON_THROW_ON_ERROR);
    foreach ($fixture['cases'] as $case) {
        $predictions = app(NflPropShadowBundle::class)->predict($fixture, 'player_rush_attempts', $case['features']);
        expect($predictions['ridge'])->toEqualWithDelta($case['expected']['ridge'], 0.000000001)
            ->and($predictions['xgboost'])->toEqualWithDelta($case['expected']['xgboost'], 0.0001);
    }
});

it('does not grade another player with the same name when the frozen player has no result', function () {
    ($this->runShadow)();
    $this->game->update(['status' => 'STATUS_FINAL']);
    $other = Player::factory()->create(['full_name' => $this->player->full_name, 'team_id' => $this->team->id]);
    PlayerStat::factory()->create(['game_id' => $this->game->id, 'player_id' => $other->id,
        'team_id' => $this->team->id, 'rushing_attempts' => 25]);
    $this->travelTo(now()->addDays(2));
    expect(app(NflPropShadowGrader::class)->grade(2026)['pending_missing_actual'])->toBe(1)
        ->and(PredictionEvaluation::where('blend_version', NflPropShadowService::POLICY)->count())->toBe(0);
});

it('builds the same frozen features as the historical benchmark for the same eligible history', function () {
    $this->game->update(['status' => 'STATUS_FINAL']);
    PlayerStat::factory()->create(['game_id' => $this->game->id, 'player_id' => $this->player->id,
        'team_id' => $this->team->id, 'rushing_attempts' => 20]);
    $this->travelTo(now()->addDays(2));
    $report = app(NflPropProjectionBacktest::class)->run(2026, '2026-10-24', includeSamples: true);
    $features = collect($report['samples'][0])->filter(fn ($value, $key) => str_starts_with($key, 'feature_'))->all();
    expect($features)->toBe($this->sample['features']);
});

it('refuses a capture when kickoff passes while inference is running', function () {
    $this->partialMock(NflPropShadowBundle::class, function ($mock) {
        $mock->shouldReceive('predict')->once()->andReturnUsing(function () {
            $this->travelTo(now()->addDays(2));

            return ['ridge' => 14.0, 'xgboost' => 12.0];
        });
    });
    expect(($this->runShadow)()['created'])->toBe(0)
        ->and(PredictionFeatureSnapshot::where('blend_version', NflPropShadowService::POLICY)->count())->toBe(0);
});
