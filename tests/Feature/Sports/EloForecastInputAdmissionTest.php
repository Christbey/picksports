<?php

use App\Actions\NBA\CalculateElo;
use App\Actions\NBA\GenerateCanonicalPrediction;
use App\Actions\NBA\GeneratePrediction;
use App\Exceptions\EloAdmissionRefused;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\MLB\EloRating;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Player;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SportEloWriterGate;
use App\Models\SportEvent;
use App\Services\NBA\Predictions\NbaCalculationReleaseRegistrar;
use App\Services\Sports\Elo\EloAdmissionMode;
use App\Services\Sports\Elo\EloBaselineAdmission;
use App\Services\Sports\Elo\EloInputAdmission;
use App\Services\Sports\Elo\EloSportRegistry;
use App\Services\Sports\Elo\EloWriterCapabilities;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use App\Support\Sports\LegacyTimestampCarrier;
use Carbon\CarbonImmutable;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EloClockFixture;

beforeEach(fn () => EloClockFixture::prepare());

function eloForecastFixture(?CarbonImmutable $clock = null): array
{
    Http::preventStrayRequests();
    Queue::fake();
    test()->travelTo($clock ?? CarbonImmutable::parse('2026-11-26 12:00:00 UTC'));
    config(['elo_integrity.enabled' => true, 'prediction_lifecycle.canonical_pipeline.nba' => false]);
    app()->instance(EloWriterCapabilities::class, new class extends EloWriterCapabilities
    {
        public function supports(string $sport, int $protocol): bool
        {
            return $sport === 'nba' && $protocol === 1;
        }
    });
    $definition = app(EloSportRegistry::class)->resolve('nba', 'team');
    SportEloWriterGate::whereKey('nba')->update(['mode' => 'admitting', 'protocol_version' => 1,
        'calculation_version' => $definition['version'], 'configuration_hash' => $definition['configuration_hash'],
        'drain_reference' => 'synthetic-forecast-admission-drain', 'drain_verified_at' => now()->subMinute()]);
    [$a, $b, $c, $d] = Team::factory()->count(4)->create(['elo_rating' => 1500])->all();
    $ids = [$a->id, $b->id, $c->id, $d->id];
    $admission = app(EloBaselineAdmission::class);
    $report = $admission->inspect('nba', 2026, 'team', $ids);
    $admission->apply('nba', 2026, 'team', $ids, $report['fingerprint'], 'synthetic-forecast-baseline-review');
    config(['elo_integrity.write_target_fingerprints.nba' => app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint']]);
    SportEloWriterGate::whereKey('nba')->update(['mode' => 'active']);
    $ancestor = Game::factory()->create(['home_team_id' => $b->id, 'away_team_id' => $c->id, 'season' => 2026,
        'season_type' => 2, 'week' => 1, 'status' => 'STATUS_FINAL', 'game_date' => '2026-10-01', 'game_time' => '18:00:00',
        'home_score' => 20, 'away_score' => 10, 'neutral_site' => false]);
    (new CalculateElo)->execute($ancestor);
    (new CalculateElo)->execute(Game::factory()->create(['home_team_id' => $a->id, 'away_team_id' => $b->id, 'season' => 2026,
        'season_type' => 2, 'week' => 2, 'status' => 'STATUS_FINAL', 'game_date' => '2026-10-08', 'game_time' => '18:00:00',
        'home_score' => 20, 'away_score' => 10, 'neutral_site' => false]));
    $event = SportEvent::factory()->create(['sport' => 'nba', 'season' => 2026, 'season_type' => '2',
        'starts_at' => CarbonImmutable::parse('2026-11-27 18:00:00 UTC')->setTimezone((string) config('app.timezone')), 'status' => 'STATUS_SCHEDULED', 'neutral_site' => false]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $a->id, 'away_team_id' => $d->id,
        'season' => 2026, 'season_type' => 2, 'week' => 3, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-11-27', 'game_time' => '18:00:00', 'home_score' => null, 'away_score' => null, 'neutral_site' => false]);
    app(NbaCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());

    return compact('a', 'b', 'c', 'd', 'ancestor', 'event', 'game');
}

it('refuses corrected opponent ancestry before real canonical generation captures or persists inputs', function () {
    $fixture = eloForecastFixture();
    Game::whereKey($fixture['ancestor']->id)->update(['away_score' => 40]);
    expect(fn () => app(GenerateCanonicalPrediction::class)->execute($fixture['game'], false))->toThrow(EloAdmissionRefused::class);
    expect(CanonicalPrediction::count())->toBe(0)->and(EventInputSnapshot::count())->toBe(0);
});

it('refuses corrected opponent ancestry through the actual shared legacy generator before persistence', function () {
    $fixture = eloForecastFixture();
    Game::whereKey($fixture['ancestor']->id)->update(['away_score' => 40]);
    expect(fn () => app(GeneratePrediction::class)->execute($fixture['game'], false))->toThrow(EloAdmissionRefused::class);
    expect(Prediction::count())->toBe(0)->and(PredictionFeatureSnapshot::count())->toBe(0);
});

it('retains unchanged guarded rating values through real canonical generation', function () {
    $fixture = eloForecastFixture();
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game'], false);
    $snapshot = $prediction->calculationRun->inputSnapshot;
    expect((float) data_get($snapshot->inputs, 'home.elo'))->toBe((float) $fixture['a']->fresh()->elo_rating)
        ->and((float) data_get($snapshot->inputs, 'away.elo'))->toBe((float) $fixture['d']->fresh()->elo_rating)
        ->and($prediction->publication_state)->toBe('draft');
});

it('retains exact admission receipts alongside canonical input values', function () {
    $fixture = eloForecastFixture();
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game'], false);
    $snapshot = $prediction->calculationRun->inputSnapshot;
    $evidence = data_get($snapshot->metadata, 'elo_input_admission');
    expect($evidence['policy'])->toBe('guarded-team-input-closure-v2-utc-source')
        ->and($evidence['game_id'])->toBe($fixture['game']->id)
        ->and($evidence['target_fingerprint'])->toBe(app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint'])
        ->and($evidence['dependency_plan_hash'])->toMatch('/\A[a-f0-9]{64}\z/')
        ->and((float) $evidence['heads'][$fixture['a']->id]['rating'])->toBe((float) data_get($snapshot->inputs, 'home.elo'));
});

it('refuses durably admitted prediction inputs after configuration is turned off', function () {
    $fixture = eloForecastFixture();
    config(['elo_integrity.enabled' => false]);
    expect(fn () => app(GenerateCanonicalPrediction::class)->execute($fixture['game'], false))->toThrow(EloAdmissionRefused::class, 'durable_elo_admission_disabled');
    expect(CanonicalPrediction::count())->toBe(0)->and(EventInputSnapshot::count())->toBe(0);
});

it('refuses a legacy ordinary writer after durable admission even with the flag off', function () {
    $fixture = eloForecastFixture();
    config(['elo_integrity.enabled' => false]);
    $before = DB::table('nba_elo_ratings')->orderBy('id')->get()->all();
    expect(fn () => (new CalculateElo)->execute($fixture['ancestor']))->toThrow(EloAdmissionRefused::class, 'durable_elo_admission_disabled');
    expect(DB::table('nba_elo_ratings')->orderBy('id')->get()->all())->toEqual($before);
});

it('refuses the actual league command before a legacy writer when admitted mode is disabled', function () {
    eloForecastFixture();
    config(['elo_integrity.enabled' => false]);
    $before = DB::table('nba_teams')->orderBy('id')->get()->all();
    $this->artisan('nba:calculate-elo', ['--season' => 2026])->assertFailed();
    expect(DB::table('nba_teams')->orderBy('id')->get()->all())->toEqual($before);
});

it('refuses a stale caller after target team identity changes', function () {
    $fixture = eloForecastFixture();
    Game::whereKey($fixture['game']->id)->update(['home_team_id' => $fixture['c']->id]);
    expect(fn () => app(EloInputAdmission::class)->teams('nba', $fixture['game']))->toThrow(EloAdmissionRefused::class, 'input_target_changed');
    expect(CanonicalPrediction::count())->toBe(0)->and(EventInputSnapshot::count())->toBe(0);
});

it('refuses a changed canonical kickoff behind a cached event relation before snapshot persistence', function () {
    $fixture = eloForecastFixture();
    $fixture['game']->setRelation('sportEvent', $fixture['event']);
    DB::table('sport_events')->where('id', $fixture['event']->id)->update(['starts_at' => now()->addDays(2)]);
    expect(fn () => app(GenerateCanonicalPrediction::class)->execute($fixture['game'], false))->toThrow(EloAdmissionRefused::class, 'input_event_context_changed');
    expect(CanonicalPrediction::count())->toBe(0)->and(EventInputSnapshot::count())->toBe(0);
});

it('preserves legacy default-off behavior for a sport with no admissions', function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['elo_integrity.enabled' => false]);
    $home = Team::factory()->create(['elo_rating' => 1600]);
    $away = Team::factory()->create(['elo_rating' => 1400]);
    $event = SportEvent::factory()->create(['sport' => 'nba', 'season' => 2026, 'season_type' => '2',
        'status' => 'STATUS_SCHEDULED', 'starts_at' => now()->addDay(), 'neutral_site' => false]);
    $game = Game::factory()->create(['sport_event_id' => $event->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED', 'game_date' => now()->addDay(), 'neutral_site' => false]);
    app(NbaCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($game, false);
    $snapshot = $prediction->calculationRun->inputSnapshot;
    expect((float) data_get($snapshot->inputs, 'home.elo'))->toBe(1600.0)
        ->and((float) data_get($snapshot->inputs, 'away.elo'))->toBe(1400.0)
        ->and(data_get($snapshot->metadata, 'elo_input_admission'))->toBeNull();
});

it('admits exact values from the supplied game connection despite colliding default IDs', function () {
    $original = DB::getDefaultConnection();
    config(['database.connections.elo_input_peer' => array_replace(config('database.connections.sqlite'), ['database' => ':memory:', 'url' => null])]);
    try {
        Artisan::call('migrate', ['--database' => 'elo_input_peer', '--force' => true]);
        DB::setDefaultConnection('elo_input_peer');
        $fixture = eloForecastFixture();
        $fixture['game']->setConnection('elo_input_peer');
        $expected = (float) $fixture['a']->fresh()->elo_rating;
        DB::setDefaultConnection($original);
        $home = Team::factory()->create(['id' => $fixture['a']->id, 'elo_rating' => 900]);
        $away = Team::factory()->create(['id' => $fixture['d']->id, 'elo_rating' => 2200]);
        Game::factory()->create(['id' => $fixture['game']->id, 'home_team_id' => $home->id, 'away_team_id' => $away->id]);
        $before = DB::table('nba_teams')->orderBy('id')->get()->all();
        $capture = app(EloInputAdmission::class)->teams('nba', $fixture['game']);
        expect((float) $capture['values'][$fixture['a']->id])->toBe($expected)
            ->and($capture['evidence']['target_fingerprint'])->toBe(app(EloWriteTargetIdentity::class)->describe(DB::connection('elo_input_peer'))['fingerprint'])
            ->and(DB::table('nba_teams')->orderBy('id')->get()->all())->toEqual($before)
            ->and(CanonicalPrediction::count())->toBe(0)->and(EventInputSnapshot::count())->toBe(0);
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('elo_input_peer');
    }
});

it('refuses every MLB legacy writer entry after durable admission when the flag is off', function (string $entry) {
    eloForecastFixture();
    app()->instance(EloWriterCapabilities::class, new class extends EloWriterCapabilities
    {
        public function supports(string $sport, int $protocol): bool
        {
            return in_array($sport, ['nba', 'mlb'], true) && $protocol === 1;
        }
    });
    $definition = app(EloSportRegistry::class)->resolve('mlb', 'team');
    SportEloWriterGate::whereKey('mlb')->update(['mode' => 'admitting', 'calculation_version' => $definition['version'],
        'configuration_hash' => $definition['configuration_hash'], 'drain_reference' => 'synthetic-mlb-drain', 'drain_verified_at' => now()->subMinute()]);
    $teams = App\Models\MLB\Team::factory()->count(2)->create(['elo_rating' => 1500]);
    $admission = app(EloBaselineAdmission::class);
    $report = $admission->inspect('mlb', 2026, 'team', $teams->modelKeys());
    $admission->apply('mlb', 2026, 'team', $teams->modelKeys(), $report['fingerprint'], 'synthetic-mlb-baseline');
    SportEloWriterGate::whereKey('mlb')->update(['mode' => 'active']);
    $pitcher = Player::factory()->pitcher()->create(['team_id' => $teams[0]->id, 'elo_rating' => 1500]);
    $game = App\Models\MLB\Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'game_date' => '2026-10-01', 'game_time' => '18:00:00',
        'home_score' => 1, 'away_score' => 0]);
    config(['elo_integrity.enabled' => false]);
    $before = [DB::table('mlb_teams')->orderBy('id')->get()->all(), $pitcher->fresh()->getRawOriginal()];
    $calculator = new App\Actions\MLB\CalculateElo;
    if ($entry === 'command') {
        $this->artisan('mlb:apply-offseason-regression')->assertFailed();
    } else {
        $operation = match ($entry) {
            'ordinary' => fn () => $calculator->execute($game),
            'team' => fn () => $calculator->applyTeamRegression($teams[0], 0.3),
            'pitcher' => fn () => $calculator->applyPitcherRegression($pitcher, 0.3),
            'prediction' => fn () => app(App\Actions\MLB\GeneratePrediction::class)->executeHistorical($game, false),
        };
        expect($operation)->toThrow(EloAdmissionRefused::class, 'durable_elo_admission_disabled');
    }
    expect([DB::table('mlb_teams')->orderBy('id')->get()->all(), $pitcher->fresh()->getRawOriginal()])->toEqual($before)
        ->and(EloRating::count())->toBe(0)->and(PitcherEloRating::count())->toBe(0);
})->with(['ordinary', 'team', 'pitcher', 'command', 'prediction']);

it('accepts only the equivalent all-zero ORM date suffix without replacing the kickoff', function () {
    $fixture = eloForecastFixture();
    $fixture['game']->setRawAttributes([...$fixture['game']->getAttributes(), 'game_date' => '2026-11-27 00:00:00.000000'], true);
    $capture = app(EloInputAdmission::class)->teams('nba', $fixture['game']);
    expect($capture['evidence']['cutoff_at_utc'])->toBe('2026-11-27T18:00:00.000000Z')
        ->and($fixture['game']->getRawOriginal('game_time'))->toBe('18:00:00')
        ->and((float) $capture['values'][$fixture['a']->id])->toBe((float) $fixture['a']->fresh()->elo_rating);
});

it('does not discard a changed non-midnight target date suffix', function () {
    $fixture = eloForecastFixture();
    $fixture['game']->setRawAttributes([...$fixture['game']->getAttributes(), 'game_date' => '2026-11-27 12:00:00'], true);
    expect(fn () => app(EloInputAdmission::class)->teams('nba', $fixture['game']))
        ->toThrow(EloAdmissionRefused::class, 'input_target_changed_game_date');
    expect(CanonicalPrediction::count())->toBe(0)->and(EventInputSnapshot::count())->toBe(0);
});

it('preserves the separate observed kickoff clock identity check', function () {
    $fixture = eloForecastFixture();
    Game::whereKey($fixture['game']->id)->update(['game_time' => '19:00:00']);
    expect(fn () => app(EloInputAdmission::class)->teams('nba', $fixture['game']))
        ->toThrow(EloAdmissionRefused::class, 'input_target_changed_game_time');
});

it('rejects invalid stored neutral values instead of coercing them to true', function () {
    $fixture = eloForecastFixture();
    DB::table('nba_games')->where('id', $fixture['game']->id)->update(['neutral_site' => 2]);
    expect(fn () => app(EloInputAdmission::class)->teams('nba', $fixture['game']))
        ->toThrow(EloAdmissionRefused::class, 'input_target_neutral_invalid');
});

it('refuses a target that became final before capturing forecast inputs', function () {
    $fixture = eloForecastFixture();
    Game::whereKey($fixture['game']->id)->update(['status' => 'STATUS_FINAL', 'home_score' => 0, 'away_score' => 0]);
    expect(fn () => app(EloInputAdmission::class)->teams('nba', $fixture['game']))
        ->toThrow(EloAdmissionRefused::class, 'input_target_not_open');
    expect(CanonicalPrediction::count())->toBe(0)->and(EventInputSnapshot::count())->toBe(0);
});

it('checks durable admission on the write PDO when a read replica lacks the new tables', function () {
    config(['elo_integrity.enabled' => false]);
    $write = new PDO('sqlite::memory:');
    $write->exec('CREATE TABLE sport_elo_writer_gates (sport TEXT PRIMARY KEY, mode TEXT, calculation_version TEXT, configuration_hash TEXT, generation INTEGER)');
    $write->exec('CREATE TABLE sport_elo_season_initializations (id INTEGER PRIMARY KEY, sport TEXT)');
    $write->exec("INSERT INTO sport_elo_writer_gates VALUES ('nba', 'disabled', NULL, NULL, 0)");
    $write->exec("INSERT INTO sport_elo_season_initializations VALUES (1, 'nba')");
    $probe = new SQLiteConnection($write, ':memory:', '',
        ['name' => 'elo_authoritative_probe', 'driver' => 'sqlite', 'database' => ':memory:']);
    $probe->setReadPdo(new PDO('sqlite::memory:'));
    // This is the old convenience call: its scalar query consults the empty read PDO.
    expect($probe->getSchemaBuilder()->hasTable('sport_elo_writer_gates'))->toBeFalse();
    expect(fn () => app(EloAdmissionMode::class)->managed('nba', $probe))
        ->toThrow(EloAdmissionRefused::class, 'durable_elo_admission_disabled');
});

it('preserves the actual admission UTC second after canonical capture persistence', function () {
    $fixture = eloForecastFixture();
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game'], false);
    $snapshot = $prediction->calculationRun->inputSnapshot->fresh();
    $actual = CarbonImmutable::parse(data_get($snapshot->metadata, 'elo_input_admission.admitted_at_utc'));
    expect($snapshot->captured_at->getTimestamp())->toBe($actual->getTimestamp());
});

it('does not select future app-wall metrics through a managed UTC capture comparison', function () {
    $fixture = eloForecastFixture();
    $metric = TeamMetric::query()->create(['team_id' => $fixture['a']->id, 'season' => 2026, 'season_type' => '2',
        'wins' => 30, 'losses' => 10, 'offensive_efficiency' => 120, 'defensive_efficiency' => 110, 'net_rating' => 10,
        'tempo' => 100, 'calculation_date' => now()->toDateString()]);
    $metric->forceFill(['updated_at' => now()->addHour()])->save();
    expect($metric->fresh()->updated_at->getTimestamp())->toBe(now()->addHour()->getTimestamp());
    $prediction = app(GenerateCanonicalPrediction::class)->execute($fixture['game'], false);
    expect(data_get($prediction->calculationRun->inputSnapshot->inputs, 'home.metrics'))->toBeNull();
});

it('refuses mixed-worker legacy timezone configuration without changing the physical target hash', function () {
    $fixture = eloForecastFixture();
    $target = app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint'];
    config(['app.timezone' => 'UTC']);
    expect(app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint'])->toBe($target);
    expect(fn () => app(EloInputAdmission::class)->teams('nba', $fixture['game']))->toThrow(RuntimeException::class, 'not active and coherent');
    expect(CanonicalPrediction::count())->toBe(0)->and(EventInputSnapshot::count())->toBe(0);
});

it('refuses a managed capture instant the legacy snapshot column cannot roundtrip', function () {
    $fixture = eloForecastFixture(CarbonImmutable::parse('2026-10-30 12:00:00 UTC'));
    $unsupported = null;
    foreach (['2026-11-01 06:30:00 UTC', '2026-11-01 07:30:00 UTC'] as $value) {
        $instant = CarbonImmutable::parse($value);
        if (LegacyTimestampCarrier::forInstant($instant) === null) {
            $unsupported = $instant;
            break;
        }
    }
    expect($unsupported)->not->toBeNull();
    $this->travelTo($unsupported);
    expect(fn () => app(GenerateCanonicalPrediction::class)->execute($fixture['game'], false))
        ->toThrow(EloAdmissionRefused::class, 'input_capture_clock_unrepresentable');
    expect(CanonicalPrediction::count())->toBe(0)->and(EventInputSnapshot::count())->toBe(0);
});
