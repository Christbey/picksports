<?php

use App\Models\CFB\FpiRating;
use App\Models\CFB\Team;
use App\Models\CFB\TeamSeasonAffiliation;
use App\Models\CfbdTeamMapping;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../../Support/FpiSeasonFixture.php';

beforeEach(function () {
    TestDatabaseSafety::assertSafe(config()->all(), app()->configurationIsCached(), Env::get('PICKSPORTS_TEST_MYSQL_DATABASE'), Env::get('PICKSPORTS_TEST_MYSQL_SOCKET'));
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->travelTo(CarbonImmutable::parse('2026-11-27T12:00:00-06:00'));
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    Http::preventStrayRequests();
    activateSyntheticFpiSeasonScope();
    $this->fpiNativeDefault = DB::getDefaultConnection();
    config(['database.connections.fpi_native_peer' => config('database.connections.'.$this->fpiNativeDefault)]);
    DB::connection('fpi_native_peer')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    expect(DB::connection()->getPdo())->not->toBe(DB::connection('fpi_native_peer')->getPdo());
    expect(DB::connection()->selectOne('SELECT @@transaction_isolation AS level', [], false)->level)->toBe('REPEATABLE-READ');
});
afterEach(function () {
    $peer = DB::connection('fpi_native_peer');
    while ($peer->transactionLevel() > 0) {
        $peer->rollBack();
    }
    DB::purge('fpi_native_peer');
    $this->travelBack();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

function fpiNativeImport(int $week = 3): int
{
    return Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => $week, '--require-complete' => true]);
}

it('applies qualified mapped native floats and preserves one paid source observation across weeks', function () {
    $team = Team::factory()->create(['school' => 'Native Mapped College', 'division' => 'FBS', 'cfbd_team_id' => null]);
    CfbdTeamMapping::create(['sport' => 'cfb', 'cfbd_team_id' => 901, 'cfbd_team_name' => 'Provider Native College', 'espn_team_name' => $team->school]);
    config(['services.collegefootballdata.api_key' => 'synthetic-native-fpi']);
    $empty = false;
    Http::fake(function () use (&$empty) {
        return Http::response($empty ? [] : [['team' => 'Provider Native College', 'fpi' => 12.345, 'efficiencies' => ['offense' => -2.345, 'defense' => 0, 'specialTeams' => 1.125]]]);
    });
    $observed = now()->startOfSecond();
    $this->travelTo($observed);
    expect(fpiNativeImport())->toBe(0, Artisan::output());
    $first = DB::table('cfb_fpi_ratings')->sole();
    expect(round((float) $first->fpi, 3))->toBe(12.345)->and(round((float) $first->offense, 3))->toBe(-2.345)
        ->and((float) $first->defense)->toBe(0.0)->and(round((float) $first->special_teams, 3))->toBe(1.125);
    expect((int) $team->fresh()->cfbd_team_id)->toBe(901);
    $this->travel(20)->minutes();
    expect(fpiNativeImport(4))->toBe(0, Artisan::output());
    Http::assertSentCount(1);
    expect(FpiRating::orderBy('week')->get()->every(fn ($row) => $row->updated_at->equalTo($observed)))->toBeTrue();
    $head = DB::table('cfbd_fpi_season_heads')->sole();
    expect((int) $head->issued_sequence)->toBe(2)->and((int) $head->latest_applied_sequence)->toBe(2);
    expect(DB::table('cfbd_fpi_season_attempts')->orderBy('sequence')->pluck('outcome')->all())->toBe(['applied', 'applied']);
    expect($head->latest_cache_origin_key)->toBeString()->and($head->latest_cache_origin_source_hash)->toHaveLength(64);
    // Truthful empty negative cache has a separate accepted-origin counter, never a published-rating claim.
    activateSyntheticFpiSeasonScope(2025);
    $empty = true;
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2025, '--week' => 3]))->toBe(0);
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2025, '--week' => 4, '--require-data' => true]))->toBe(1);
    Http::assertSentCount(2); // One actual fake HTTP fetch per independently scoped season; no calls for either cached repeat.
    $emptyHead = DB::table('cfbd_fpi_season_heads')->where('season', 2025)->sole();
    expect((int) $emptyHead->latest_applied_sequence)->toBe(0)->and((int) $emptyHead->latest_cache_origin_sequence)->toBe(2);
    expect(DB::table('cfbd_fpi_season_attempts')->where('head_id', $emptyHead->id)->orderBy('sequence')->pluck('outcome')->all())->toBe(['unavailable', 'unavailable']);
    expect(DB::table('cfb_fpi_ratings')->where('season', 2025)->count())->toBe(0);

});

it('refuses a real peer mapping change committed during the provider boundary', function () {
    $team = Team::factory()->create(['school' => 'Native Source College', 'division' => 'FBS', 'cfbd_team_id' => null]);
    $mapping = CfbdTeamMapping::create(['sport' => 'cfb', 'cfbd_team_id' => 902, 'cfbd_team_name' => 'Native Provider Alias', 'espn_team_name' => $team->school]);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturnUsing(function () use ($mapping) {
        expect(DB::transactionLevel())->toBe(0);
        expect(DB::connection('fpi_native_peer')->table('cfbd_team_mappings')->where('id', $mapping->id)->update(['espn_team_name' => 'Different Current College']))->toBe(1);

        return ['rows' => [['team' => 'Native Provider Alias', 'fpi' => 10]], 'observed_at' => now()->toIso8601String()];
    });
    expect(fpiNativeImport())->toBe(1);
    expect(FpiRating::count())->toBe(0)->and($team->fresh()->cfbd_team_id)->toBeNull();
    expect($mapping->fresh()->espn_team_name)->toBe('Different Current College');
    expect(DB::table('cfbd_fpi_season_attempts')->value('outcome'))->toBe('failed');
    expect((int) DB::table('cfbd_fpi_season_heads')->value('latest_applied_sequence'))->toBe(0);
});

it('uses locking current source rows despite an earlier owned transaction RR read view', function () {
    $team = Team::factory()->create(['school' => 'Native RR College', 'division' => 'FBS']);
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $connection->setEventDispatcher(clone $dispatcher);
    $armed = true;
    $oldName = null;
    $connection->listen(function ($query) use (&$armed, &$oldName, $connection, $team) {
        if ($armed && $query->connection === $connection && str_starts_with(strtolower($query->sql), 'select')
            && str_contains($query->sql, 'cfbd_fpi_season_heads') && str_contains(strtolower($query->sql), 'for update')) {
            // Reservation also locks Head, but no provider has returned then. Arm only for the commit with an existing attempt.
            if ($connection->table('cfbd_fpi_season_attempts')->count() !== 1) {
                return;
            }
            $armed = false;
            $oldName = $connection->table('cfb_teams')->where('id', $team->id)->value('school');
            DB::connection('fpi_native_peer')->table('cfb_teams')->where('id', $team->id)->update(['school' => 'Native RR Current College']);
            expect($connection->table('cfb_teams')->where('id', $team->id)->value('school'))->toBe($oldName);
        }
    });
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturn(['rows' => [['team' => $team->school, 'fpi' => 10]], 'observed_at' => now()->toIso8601String()]);
    try {
        expect(fpiNativeImport())->toBe(1);
        expect($armed)->toBeFalse()->and($oldName)->toBe('Native RR College')->and(FpiRating::count())->toBe(0);
        expect($connection->table('cfb_teams')->where('id', $team->id)->value('school'))->toBe('Native RR Current College');
        expect($connection->transactionLevel())->toBe(0)->and($connection->getPdo()->inTransaction())->toBeFalse();
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }
});

it('holds actual source rows and Team and football mapping new keys until atomic publication then permits retry', function () {
    $team = Team::factory()->create(['school' => 'Native Range College', 'division' => 'FBS']);
    $mapping = CfbdTeamMapping::create(['sport' => 'cfb', 'cfbd_team_id' => 903, 'cfbd_team_name' => 'Range Alias', 'espn_team_name' => $team->school]);
    $affiliation = TeamSeasonAffiliation::create(['team_id' => $team->id, 'season' => 2026, 'subdivision' => 'FBS', 'source' => 'synthetic-native-range']);
    $peer = DB::connection('fpi_native_peer');
    $checks = [];
    $armed = true;
    $dispatcher = FpiRating::getEventDispatcher();
    FpiRating::setEventDispatcher(clone $dispatcher);
    FpiRating::saving(function () use (&$armed, &$checks, $peer, $team, $mapping, $affiliation) {
        if (! $armed) {
            return;
        }$armed = false;
        $operations = [
            'existing_team' => fn () => $peer->table('cfb_teams')->where('id', $team->id)->update(['school' => 'Intervening Name']),
            'existing_mapping' => fn () => $peer->table('cfbd_team_mappings')->where('id', $mapping->id)->update(['cfbd_team_name' => 'Intervening Alias']),
            'existing_affiliation' => fn () => $peer->table('cfb_team_season_affiliations')->where('id', $affiliation->id)->update(['subdivision' => 'FCS']),
            'new_team' => fn () => $peer->table('cfb_teams')->insert(['espn_id' => 'fpi-native-new', 'abbreviation' => 'FPINEW', 'school' => 'New Native Team']),
            // Mapping has no foreign key: this insert tests the declared football index range, not an FK parent block.
            'new_football_mapping' => fn () => $peer->table('cfbd_team_mappings')->insert(['sport' => 'cfb', 'cfbd_team_id' => 9999, 'cfbd_team_name' => 'New Native Alias']),
        ];
        foreach ($operations as $name => $operation) {
            $peer->beginTransaction();
            try {
                $operation();
                $checks[$name] = ['blocked' => false];
            } catch (QueryException $error) {
                $checks[$name] = ['blocked' => (int) ($error->errorInfo[1] ?? 0) === 1205, 'sql' => $error->getSql(), 'code' => (int) ($error->errorInfo[1] ?? 0)];
            } finally {
                $peer->rollBack();
            }
        }
    });
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturn(['rows' => [['team' => $team->school, 'fpi' => 12.345]], 'observed_at' => now()->toIso8601String()]);
    try {
        expect(fpiNativeImport())->toBe(0, Artisan::output());
        expect($armed)->toBeFalse()->and(array_keys($checks))->toBe(['existing_team', 'existing_mapping', 'existing_affiliation', 'new_team', 'new_football_mapping']);
        foreach ($checks as $name => $check) {
            expect($check['blocked'])->toBeTrue($name);
            expect($check['code'])->toBe(1205);
        }
        expect($checks['existing_team']['sql'])->toContain('cfb_teams');
        expect($checks['existing_mapping']['sql'])->toContain('cfbd_team_mappings');
        expect($checks['existing_affiliation']['sql'])->toContain('cfb_team_season_affiliations');
        expect($checks['new_team']['sql'])->toContain('cfb_teams');
        expect($checks['new_football_mapping']['sql'])->toContain('cfbd_team_mappings');
        expect(round((float) DB::table('cfb_fpi_ratings')->value('fpi'), 3))->toBe(12.345);
        expect($peer->table('cfbd_team_mappings')->insert(['sport' => 'cfb', 'cfbd_team_id' => 9999, 'cfbd_team_name' => 'New Native Alias']))->toBeTrue();
        expect($peer->table('cfbd_team_mappings')->where('cfbd_team_id', 9999)->count())->toBe(1);
        expect((int) DB::table('cfbd_fpi_season_heads')->value('latest_applied_sequence'))->toBe(1);
    } finally {
        FpiRating::setEventDispatcher($dispatcher);
    }
});

it('refuses replacement of its physical PDO before commit and leaves no output transaction', function () {
    $team = Team::factory()->create(['school' => 'Native PDO College', 'division' => 'FBS']);
    $connection = DB::connection();
    $original = $connection->getPdo();
    $replacement = DB::connection('fpi_native_peer')->getPdo();
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturnUsing(function () use ($connection, $replacement, $team) {
        $connection->setPdo($replacement);

        return ['rows' => [['team' => $team->school, 'fpi' => 10]], 'observed_at' => now()->toIso8601String()];
    });
    try {
        expect(fpiNativeImport())->toBe(1)->and($original->inTransaction())->toBeFalse()->and($replacement->inTransaction())->toBeFalse();
    } finally {
        $connection->setPdo($original);
    }
    expect(FpiRating::count())->toBe(0)->and((int) DB::table('cfbd_fpi_season_heads')->value('latest_applied_sequence'))->toBe(0);
    // No failure receipt may be authored through the changed physical connection.
    expect(DB::table('cfbd_fpi_season_attempts')->value('outcome'))->toBeNull();
});

it('keeps an expired real-cache response out of the current native applied origin', function () {
    $team = Team::factory()->create(['school' => 'Native Cache College', 'division' => 'FBS']);
    config(['services.collegefootballdata.api_key' => 'synthetic-native-fpi-aba']);
    $first = true;
    $inner = null;
    Http::fake(function () use (&$first, &$inner, $team) {
        if ($first) {
            $first = false;
            $this->travel(121)->seconds();
            $inner = fpiNativeImport();

            return Http::response([['team' => $team->school, 'fpi' => 10]]);
        }

        return Http::response([['team' => $team->school, 'fpi' => 20]]);
    });
    expect(fpiNativeImport())->toBe(1)->and($inner)->toBe(0);
    expect(fpiNativeImport())->toBe(0, Artisan::output());
    Http::assertSentCount(2);
    expect((float) DB::table('cfb_fpi_ratings')->sole()->fpi)->toBe(20.0);
    expect(DB::table('cfbd_fpi_season_attempts')->orderBy('sequence')->pluck('outcome')->all())->toBe(['expired', 'applied', 'applied']);
    expect((int) DB::table('cfbd_fpi_season_heads')->value('latest_cache_origin_attempt_id'))->toBe(3);
    expect((int) DB::table('cfbd_fpi_season_heads')->value('latest_applied_sequence'))->toBe(3);
});
