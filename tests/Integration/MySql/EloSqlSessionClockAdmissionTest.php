<?php

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Exceptions\EloAdmissionRefused;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;
use App\Models\SportEloWriterGate;
use App\Services\NBA\Predictions\NbaCalculationReleaseRegistrar;
use App\Services\NBA\Predictions\NbaInputSnapshotBuilder;
use App\Services\Sports\Elo\EloBaselineAdmission;
use App\Services\Sports\Elo\EloSportRegistry;
use App\Services\Sports\Elo\EloSqlClockPolicy;
use App\Services\Sports\Elo\EloWriterCapabilities;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use App\Services\Sports\SportEventIdentitySynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    if (DB::connection()->getDriverName() !== 'mysql') {
        throw new RuntimeException('SQL carrier clock controls require disposable MySQL.');
    }
    Artisan::call('migrate:fresh', ['--force' => true]);
    DB::statement("SET SESSION time_zone = '+00:00'");
    config(['app.timezone' => 'America/Chicago', 'elo_integrity.enabled' => true,
        'database.connections.elo_clock_observer' => config('database.connections.mysql')]);
    DB::connection('elo_clock_observer')->statement("SET SESSION time_zone = '+00:00'");
    $this->travelTo(CarbonImmutable::parse('2026-11-26 12:00:00 UTC'));
    Http::preventStrayRequests();
    Queue::fake();
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
        'drain_reference' => 'synthetic-session-clock-drain', 'drain_verified_at' => now()->subMinute()]);
    $teams = Team::factory()->count(2)->create(['elo_rating' => 1500]);
    $admission = app(EloBaselineAdmission::class);
    $report = $admission->inspect('nba', 2026, 'team', $teams->modelKeys());
    $admission->apply('nba', 2026, 'team', $teams->modelKeys(), $report['fingerprint'], 'synthetic-clock-default-baseline');
    $this->targetFingerprint = app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint'];
    config(['elo_integrity.write_target_fingerprints.nba' => $this->targetFingerprint]);
    SportEloWriterGate::whereKey('nba')->update(['mode' => 'active']);
    $this->clockGame = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED', 'game_date' => '2026-11-27',
        'game_time' => '18:00:00', 'neutral_site' => false, 'home_score' => null, 'away_score' => null]);
    $this->clockEvent = app(SportEventIdentitySynchronizer::class)->sync('nba', $this->clockGame);
    $release = app(NbaCalculationReleaseRegistrar::class)->register(effectiveAt: now()->subMinute()->toImmutable());
    $this->clockRelease = CalculationReleaseData::fromModel($release);
});

afterEach(function () {
    while (DB::connection()->transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::statement("SET SESSION time_zone = '+00:00'");
    DB::purge('elo_clock_observer');
});

function sessionClockMetric($test, int $hours): TeamMetric
{
    $metric = TeamMetric::create(['team_id' => $test->clockGame->home_team_id, 'season' => 2026, 'season_type' => 2,
        'wins' => 7, 'losses' => 3, 'offensive_efficiency' => 120, 'defensive_efficiency' => 110,
        'net_rating' => 10, 'tempo' => 100, 'calculation_date' => now()->toDateString()]);
    $metric->forceFill(['updated_at' => now()->addHours($hours)])->save();
    $observed = TeamMetric::on('elo_clock_observer')->findOrFail($metric->id);
    expect($observed->updated_at->getTimestamp())->toBe(now()->addHours($hours)->getTimestamp());

    return $metric;
}

it('excludes a truly future metric on the unchanged UTC SQL session', function () {
    sessionClockMetric($this, 1);
    $input = app(NbaInputSnapshotBuilder::class)->build($this->clockEvent->fresh(), $this->clockRelease);
    expect(data_get($input->inputs, 'home.metrics'))->toBeNull()
        ->and($input->capturedAt->getTimestamp())->toBe(now()->getTimestamp());
});

it('refuses a mixed-session canonical event cutoff before selecting inputs', function () {
    sessionClockMetric($this, 1);
    DB::statement("SET SESSION time_zone = '-09:00'");
    expect(app(EloWriteTargetIdentity::class)->describe(DB::connection())['fingerprint'])->toBe($this->targetFingerprint);
    expect(fn () => app(NbaInputSnapshotBuilder::class)->build($this->clockEvent->fresh(), $this->clockRelease))
        ->toThrow(EloAdmissionRefused::class, 'sql_clock_session_not_explicit_utc');
    expect(CanonicalPrediction::count())->toBe(0)->and(EventInputSnapshot::count())->toBe(0);
});

it('retains a valid past metric or explicitly refuses after mixed-session identity synchronization', function () {
    sessionClockMetric($this, -1);
    DB::statement("SET SESSION time_zone = '-09:00'");
    $event = app(SportEventIdentitySynchronizer::class)->sync('nba', $this->clockGame->fresh());
    try {
        $input = app(NbaInputSnapshotBuilder::class)->build($event, $this->clockRelease);
    } catch (EloAdmissionRefused $refusal) {
        expect($refusal->reason)->toBeIn(['sql_clock_session_not_explicit_utc']);

        return;
    }
    expect(data_get($input->inputs, 'home.metrics.wins'))->toBe(7);
});

it('never admits a future metric after the actual synchronizer runs on a mixed SQL session', function () {
    sessionClockMetric($this, 1);
    DB::statement("SET SESSION time_zone = '-09:00'");
    $event = app(SportEventIdentitySynchronizer::class)->sync('nba', $this->clockGame->fresh());
    try {
        $input = app(NbaInputSnapshotBuilder::class)->build($event, $this->clockRelease);
    } catch (EloAdmissionRefused $refusal) {
        expect($refusal->reason)->toBeIn(['sql_clock_session_not_explicit_utc']);

        return;
    }
    expect(data_get($input->inputs, 'home.metrics'))->toBeNull();
    expect(CanonicalPrediction::count())->toBe(0)->and(EventInputSnapshot::count())->toBe(0);
});

it('refuses an independently non UTC read PDO without changing either session', function () {
    $connection = DB::connection();
    $peer = DB::connection('elo_clock_observer')->getPdo();
    $peer->exec("SET SESSION time_zone = '-09:00'");
    $connection->setReadPdo($peer);
    try {
        expect(fn () => app(EloSqlClockPolicy::class)->capture($connection))
            ->toThrow(EloAdmissionRefused::class, 'sql_clock_session_not_explicit_utc');
        expect($peer->query('SELECT @@SESSION.time_zone')->fetchColumn())->toBe('-09:00');
        expect($connection->getPdo()->query('SELECT @@SESSION.time_zone')->fetchColumn())->toBe('+00:00');
    } finally {
        $connection->setReadPdo($connection->getPdo());
    }
});

it('invalidates a token after replacement by a UTC PDO for the same physical target', function () {
    $connection = DB::connection();
    $original = $connection->getPdo();
    $policy = app(EloSqlClockPolicy::class);
    $token = $policy->capture($connection);
    $connection->setPdo(DB::connection('elo_clock_observer')->getPdo());
    try {
        expect(fn () => $policy->assertUnchanged($token, $connection))
            ->toThrow(EloAdmissionRefused::class, 'sql_clock_connection_changed_requires_fresh_admission');
    } finally {
        $connection->setPdo($original);
    }
});

it('invalidates a token when its unchanged PDO changes selected database', function () {
    $connection = DB::connection();
    $pdo = $connection->getPdo();
    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (! is_string($database) || ! preg_match('/^[a-zA-Z0-9_]+$/D', $database)) {
        throw new RuntimeException('Disposable database identity cannot be safely restored.');
    }
    $policy = app(EloSqlClockPolicy::class);
    $token = $policy->capture($connection);
    try {
        $pdo->exec('USE information_schema');
        expect(fn () => $policy->assertUnchanged($token, $connection))
            ->toThrow(EloAdmissionRefused::class, 'sql_clock_target_changed_requires_fresh_admission');
    } finally {
        $pdo->exec('USE `'.$database.'`');
    }
});

it('refuses a UTC read PDO pointed at a different database', function () {
    $connection = DB::connection();
    $peer = DB::connection('elo_clock_observer')->getPdo();
    $database = $peer->query('SELECT DATABASE()')->fetchColumn();
    if (! is_string($database) || ! preg_match('/^[a-zA-Z0-9_]+$/D', $database)) {
        throw new RuntimeException('Disposable database identity cannot be safely restored.');
    }
    $connection->setReadPdo($peer);
    try {
        $peer->exec('USE information_schema');
        expect(fn () => app(EloSqlClockPolicy::class)->capture($connection))
            ->toThrow(EloAdmissionRefused::class, 'sql_clock_read_write_target_mismatch');
    } finally {
        $peer->exec('USE `'.$database.'`');
        $connection->setReadPdo($connection->getPdo());
    }
});

it('invalidates captured evidence when the same PDO session clock changes', function () {
    $connection = DB::connection();
    $policy = app(EloSqlClockPolicy::class);
    $token = $policy->capture($connection);
    $connection->getPdo()->exec("SET SESSION time_zone = '-09:00'");
    expect(fn () => $policy->assertUnchanged($token, $connection))
        ->toThrow(EloAdmissionRefused::class, 'sql_clock_session_not_explicit_utc');
    expect($connection->getPdo()->query('SELECT @@SESSION.time_zone')->fetchColumn())->toBe('-09:00');
});

it('refuses an incompatible declared policy without changing the physical target or actual session', function () {
    $connection = DB::connection();
    $before = app(EloWriteTargetIdentity::class)->describe($connection)['fingerprint'];
    config(['elo_integrity.sql_clock.mysql_session_timezone' => 'SYSTEM']);
    expect(fn () => app(EloSqlClockPolicy::class)->capture($connection))
        ->toThrow(EloAdmissionRefused::class, 'sql_clock_policy_invalid');
    expect($connection->getPdo()->query('SELECT @@SESSION.time_zone')->fetchColumn())->toBe('+00:00')
        ->and(app(EloWriteTargetIdentity::class)->describe($connection)['fingerprint'])->toBe($before);
});
