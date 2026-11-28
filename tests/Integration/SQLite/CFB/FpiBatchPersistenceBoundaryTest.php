<?php

use App\Models\CFB\FpiRating;
use App\Models\CFB\Team;
use App\Models\CfbdTeamMapping;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use App\Services\CollegeFootballData\Fpi\FpiSeasonCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class);
require_once __DIR__.'/../../../Support/FpiSeasonFixture.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T12:00:00-06:00'));
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    activateSyntheticFpiSeasonScope();
});
afterEach(function () {
    $this->travelBack();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

function fpiBatchTwoRows(): array
{
    $a = Team::factory()->create(['school' => 'Batch First College', 'division' => 'FBS']);
    $b = Team::factory()->create(['school' => 'Batch Last College', 'division' => 'FBS']);
    $first = FpiRating::factory()->create(['team_id' => $a->id, 'season' => 2026, 'week' => 3, 'fpi' => 5])->fresh();
    $last = FpiRating::factory()->create(['team_id' => $b->id, 'season' => 2026, 'week' => 3, 'fpi' => 6])->fresh();

    return [$a, $b, $first, $last];
}

function fakeFpiBatchRows(array $rows): void
{
    test()->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturn([
        'rows' => $rows, 'observed_at' => now()->toIso8601String()]);
}

it('does not acknowledge an earlier rating changed by a later row observer', function (string $mutation) {
    [$a,$b,$first,$last] = fpiBatchTwoRows();
    $before = DB::table('cfb_fpi_ratings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $dispatcher = FpiRating::getEventDispatcher();
    FpiRating::setEventDispatcher(clone $dispatcher);
    FpiRating::saved(function ($row) use ($b, $first, $mutation) {
        if ((int) $row->team_id === $b->id) {
            $q = DB::table('cfb_fpi_ratings')->where('id', $first->id);
            $mutation === 'delete' ? $q->delete() : $q->update(['fpi' => 99]);
        }
    });
    fakeFpiBatchRows([['team' => $a->school, 'fpi' => 10], ['team' => $b->school, 'fpi' => 20]]);
    try {
        expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(1);
        expect(DB::table('cfb_fpi_ratings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
    } finally {
        FpiRating::setEventDispatcher($dispatcher);
    }
})->with(['delete', 'rewrite']);

it('does not acknowledge a team binding changed by the last rating observer', function () {
    [$a,$b,$first,$last] = fpiBatchTwoRows();
    $a->update(['cfbd_team_id' => null]);
    CfbdTeamMapping::create(['cfbd_team_id' => 99, 'cfbd_team_name' => 'Batch Provider Alias', 'espn_team_name' => $a->school, 'sport' => 'cfb']);
    $before = $first->getAttributes();
    $dispatcher = FpiRating::getEventDispatcher();
    FpiRating::setEventDispatcher(clone $dispatcher);
    FpiRating::saved(function ($row) use ($a, $b) {
        if ((int) $row->team_id === $b->id) {
            DB::table('cfb_teams')->where('id', $a->id)->update(['cfbd_team_id' => 123]);
        }
    });
    fakeFpiBatchRows([['team' => 'Batch Provider Alias', 'fpi' => 10], ['team' => $b->school, 'fpi' => 20]]);
    try {
        expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(1);
        expect($a->fresh()->cfbd_team_id)->toBeNull()->and($first->fresh()->getAttributes())->toBe($before);
    } finally {
        FpiRating::setEventDispatcher($dispatcher);
    }
});

it('does not follow a rewritten existing FPI primary key to acknowledge persistence', function () {
    $a = Team::factory()->create(['school' => 'Pinned Key College', 'division' => 'FBS']);
    $rating = FpiRating::factory()->create(['team_id' => $a->id, 'season' => 2026, 'week' => 3, 'fpi' => 5])->fresh();
    $before = $rating->getAttributes();
    $id = $rating->id;
    $dispatcher = FpiRating::getEventDispatcher();
    FpiRating::setEventDispatcher(clone $dispatcher);
    FpiRating::saving(function ($row) use ($id) {
        $row->id = $id + 100;
    });
    fakeFpiBatchRows([['team' => $a->school, 'fpi' => 10]]);
    try {
        $exit = Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]);
        expect(DB::table('cfb_fpi_ratings')->pluck('id')->all())->toBe([$id]);
        expect($exit)->toBe(1)->and($rating->fresh()->getAttributes())->toBe($before);
    } finally {
        FpiRating::setEventDispatcher($dispatcher);
    }
});

it('does not follow a model table change to another store after the literal rating vanished', function () {
    $a = Team::factory()->create(['school' => 'Literal Store College', 'division' => 'FBS']);
    $rating = FpiRating::factory()->create(['team_id' => $a->id, 'season' => 2026, 'week' => 3, 'fpi' => 5])->fresh();
    $before = $rating->getAttributes();
    $connection = $rating->getConnection();
    if ($connection->getDriverName() === 'mysql') {
        $connection->statement('CREATE TABLE synthetic_fpi_alternate LIKE cfb_fpi_ratings');
    } else {
        $connection->statement('CREATE TABLE synthetic_fpi_alternate AS SELECT * FROM cfb_fpi_ratings WHERE 0');
    }
    $dispatcher = FpiRating::getEventDispatcher();
    FpiRating::setEventDispatcher(clone $dispatcher);
    FpiRating::saving(fn ($row) => $row->setTable('synthetic_fpi_alternate'));
    FpiRating::saved(function ($row) use ($connection) {
        $literal = $connection->table('cfb_fpi_ratings')->where('id', $row->id)->first();
        $connection->table('synthetic_fpi_alternate')->insert((array) $literal);
        $connection->table('cfb_fpi_ratings')->where('id', $row->id)->delete();
    });
    fakeFpiBatchRows([['team' => $a->school, 'fpi' => 10]]);
    try {
        expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(1);
        expect($rating->fresh()->getAttributes())->toBe($before);
    } finally {
        FpiRating::setEventDispatcher($dispatcher);
        $connection->getSchemaBuilder()->dropIfExists('synthetic_fpi_alternate');
    }
});

it('accepts an unrelated current Elo update while preserving the same FPI source identity', function () {
    $a = Team::factory()->create(['school' => 'Unrelated Elo College', 'division' => 'FBS', 'elo_rating' => 1500]);
    test()->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturnUsing(function () use ($a) {
        $this->travel(1)->seconds();
        $a->update(['elo_rating' => 1600]);

        return ['rows' => [['team' => $a->school, 'fpi' => 10]], 'observed_at' => now()->toIso8601String()];
    });
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--require-complete' => true]))->toBe(0);
    expect((float) $a->fresh()->elo_rating)->toBe(1600.0)->and((float) FpiRating::where('team_id', $a->id)->sole()->fpi)->toBe(10.0);
});

it('refuses activation review metadata changed during an already reserved acquisition', function () {
    $a = Team::factory()->create(['school' => 'Review Epoch College', 'division' => 'FBS']);
    test()->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturnUsing(function () use ($a) {
        DB::table('cfbd_fpi_season_heads')->update(['activation_reason' => 'Synthetic changed review epoch']);

        return ['rows' => [['team' => $a->school, 'fpi' => 10]], 'observed_at' => now()->toIso8601String()];
    });
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026]))->toBe(1);
    expect(FpiRating::count())->toBe(0);
});

it('does not acknowledge terminal head counters rewritten after the final raw update', function () {
    $a = Team::factory()->create(['school' => 'Terminal Counter College', 'division' => 'FBS']);
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $connection->setEventDispatcher(clone $dispatcher);
    $armed = true;
    $connection->listen(function ($query) use (&$armed, $connection) {
        if ($armed && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'cfbd_fpi_season_heads') && str_contains($query->sql, 'latest_applied_sequence')) {
            $armed = false;
            $connection->table('cfbd_fpi_season_heads')->update(['latest_applied_sequence' => 0]);
        }
    });
    fakeFpiBatchRows([['team' => $a->school, 'fpi' => 10]]);
    try {
        expect(Artisan::call('cfb:import-fpi', ['--season' => 2026]))->toBe(1);
        expect(FpiRating::count())->toBe(0);
        expect($armed)->toBeFalse();
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }
});

it('preserves the complete nonintervening multirow FPI publication', function () {
    [$a,$b,$first,$last] = fpiBatchTwoRows();
    fakeFpiBatchRows([['team' => $a->school, 'fpi' => 10], ['team' => $b->school, 'fpi' => 20]]);
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(0);
    expect((float) $first->fresh()->fpi)->toBe(10.0)->and((float) $last->fresh()->fpi)->toBe(20.0);
    expect(DB::table('cfbd_fpi_season_attempts')->value('outcome'))->toBe('applied');
});

it('does not let an expired acquisition poison a newer applied snapshot through the real source cache', function () {
    config(['services.collegefootballdata.api_key' => 'synthetic-real-cache-contract']);
    Http::preventStrayRequests();
    $a = Team::factory()->create(['school' => 'Cache Sequence College', 'division' => 'FBS']);
    $first = true;
    $innerExit = null;
    $innerValue = null;
    Http::fake(function () use (&$first, &$innerExit, &$innerValue, $a) {
        if ($first) {
            $first = false;
            $this->travel(121)->seconds();
            $innerExit = Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]);
            $innerValue = FpiRating::where('team_id', $a->id)->value('fpi');

            return Http::response([['team' => $a->school, 'fpi' => 10]]);
        }

        return Http::response([['team' => $a->school, 'fpi' => 20]]);
    });
    $outerExit = Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]);
    expect($innerExit)->toBe(0)->and((float) $innerValue)->toBe(20.0)->and($outerExit)->toBe(1);
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(0);
    expect((float) FpiRating::where('team_id', $a->id)->sole()->fpi)->toBe(20.0);
    Http::assertSentCount(2);
});

it('preserves the applied source observation and original cache expiry across explicit weekly imports', function () {
    config(['services.collegefootballdata.api_key' => 'synthetic-fpi-cache-ttl']);
    Team::factory()->create(['school' => 'Cache TTL College', 'division' => 'FBS']);
    Http::fake(fn () => Http::response([['team' => 'Cache TTL College', 'fpi' => 20]]));
    $observed = now()->startOfSecond();
    $this->travelTo($observed);
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(0);
    $first = DB::table('cfbd_fpi_season_heads')->sole();
    $firstKey = $first->latest_cache_origin_key;
    $this->travel(20)->minutes();
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 4, '--require-complete' => true]))->toBe(0);
    $second = DB::table('cfbd_fpi_season_heads')->sole();
    expect($second->latest_cache_origin_key)->not->toBe($firstKey);
    Http::assertSentCount(1);
    expect(FpiRating::orderBy('week')->get()->every(fn ($row) => $row->updated_at->equalTo($observed)))->toBeTrue();
    expect(DB::table('cfbd_fpi_season_attempts')->orderBy('sequence')->pluck('source_observed_at')->unique()->count())->toBe(1);
    $this->travel(41)->minutes();
    expect(Cache::get($firstKey))->toBeNull()
        ->and(Cache::get($second->latest_cache_origin_key))->toBeNull();
    $exit = Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 5, '--require-complete' => true]);
    expect($exit)->toBe(0, Artisan::output());
    Http::assertSentCount(2);
});

it('does not acknowledge a failure against a mutated reservation tuple', function () {
    Team::factory()->create(['school' => 'Failure Owner College', 'division' => 'FBS']);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturnUsing(function () {
        DB::table('cfbd_fpi_season_attempts')->update(['sequence' => 99]);
        throw new RuntimeException('Synthetic provider callback failure after local tuple drift.');
    });
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(1);
    expect(FpiRating::count())->toBe(0);
    expect((int) DB::table('cfbd_fpi_season_heads')->value('issued_sequence'))->toBe(1);
    expect((int) DB::table('cfbd_fpi_season_heads')->value('latest_terminal_sequence'))->toBe(0);
    expect(DB::table('cfbd_fpi_season_attempts')->value('outcome'))->toBeNull();
});

it('does not bless observer-mutated in-memory team identity as an intended binding plan', function (string $field) {
    $team = Team::factory()->create(['school' => 'Captured Team College', 'division' => 'FBS', 'cfbd_team_id' => null]);
    $before = DB::table('cfb_teams')->where('id', $team->id)->first();
    $captured = null;
    $teamDispatcher = Team::getEventDispatcher();
    $dispatcher = clone $teamDispatcher;
    Team::setEventDispatcher($dispatcher);
    Team::retrieved(function ($model) use ($team, &$captured) {
        if ((int) $model->id === $team->id) {
            $captured = $model;
        }
    });
    FpiRating::saved(function ($rating) use ($team, $field, &$captured) {
        expect($captured)->toBeInstanceOf(Team::class);
        $value = $field === 'school' ? 'Unexpected Renamed College' : 123;
        $captured->setAttribute($field, $value);
        DB::table('cfb_teams')->where('id', $team->id)->update([$field => $value]);
    });
    fakeFpiBatchRows([['team' => $team->school, 'fpi' => 10]]);
    try {
        expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(1);
        expect(DB::table('cfb_teams')->where('id', $team->id)->first())->toEqual($before);
        expect(FpiRating::count())->toBe(0);
    } finally {
        Team::setEventDispatcher($teamDispatcher);
    }
})->with(['school', 'cfbd_team_id']);

it('pins every intended rating before the first persistence observer can change a later model', function () {
    [$a,$b,$first,$last] = fpiBatchTwoRows();
    $before = DB::table('cfb_fpi_ratings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $captured = null;
    $dispatcher = FpiRating::getEventDispatcher();
    FpiRating::setEventDispatcher(clone $dispatcher);
    FpiRating::retrieved(function ($model) use ($last, &$captured) {
        if ((int) $model->id === $last->id) {
            $captured = $model;
        }
    });
    FpiRating::saved(function ($model) use ($first, &$captured) {
        if ((int) $model->id === $first->id) {
            expect($captured)->toBeInstanceOf(FpiRating::class);
            $captured->fpi = 999;
        }
    });
    fakeFpiBatchRows([['team' => $a->school, 'fpi' => 10], ['team' => $b->school, 'fpi' => 20]]);
    try {
        expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(1);
        expect(DB::table('cfb_fpi_ratings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
    } finally {
        FpiRating::setEventDispatcher($dispatcher);
    }
});

it('rechecks matched retained ratings after a later write without refreshing their source clocks', function (string $kind, string $mutation) {
    [$a,$b,$first,$last] = fpiBatchTwoRows();
    $observed = now()->subMinutes(10);
    DB::table('cfb_fpi_ratings')->where('id', $first->id)->update(['updated_at' => $kind === 'newer' ? now() : now()->subMinutes(20), 'offense' => null, 'defense' => null, 'special_teams' => null, 'fpi_rank' => null]);
    DB::table('cfb_fpi_ratings')->where('id', $last->id)->update(['updated_at' => now()->subMinutes(20)]);
    $before = DB::table('cfb_fpi_ratings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $dispatcher = FpiRating::getEventDispatcher();
    FpiRating::setEventDispatcher(clone $dispatcher);
    FpiRating::saved(function ($row) use ($last, $first, $mutation) {
        if ((int) $row->id === $last->id) {
            $query = DB::table('cfb_fpi_ratings')->where('id', $first->id);
            $mutation === 'delete' ? $query->delete() : $query->update(['fpi' => 99]);
        }
    });
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturn([
        'rows' => [['team' => $a->school, 'fpi' => $kind === 'newer' ? 99 : 5], ['team' => $b->school, 'fpi' => 20]], 'observed_at' => $observed->toIso8601String()]);
    try {
        expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(1);
        expect(DB::table('cfb_fpi_ratings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
    } finally {
        FpiRating::setEventDispatcher($dispatcher);
    }
})->with(['newer', 'unchanged'])->with(['delete', 'rewrite']);

it('preserves matched newer and unchanged stored ratings without updating their observation timestamps', function (string $kind) {
    [$a,$b,$first,$last] = fpiBatchTwoRows();
    $observed = now()->subMinutes(10);
    DB::table('cfb_fpi_ratings')->where('id', $first->id)->update(['updated_at' => $kind === 'newer' ? now() : now()->subMinutes(20),
        'offense' => null, 'defense' => null, 'special_teams' => null, 'fpi_rank' => null]);
    DB::table('cfb_fpi_ratings')->where('id', $last->id)->update(['updated_at' => now()->subMinutes(20)]);
    $before = DB::table('cfb_fpi_ratings')->where('id', $first->id)->first();
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturn([
        'rows' => [['team' => $a->school, 'fpi' => $kind === 'newer' ? 99 : 5], ['team' => $b->school, 'fpi' => 20]], 'observed_at' => $observed->toIso8601String()]);
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(0, Artisan::output());
    expect(DB::table('cfb_fpi_ratings')->where('id', $first->id)->first())->toEqual($before);
    expect((float) $last->fresh()->fpi)->toBe(20.0)->and(FpiRating::count())->toBe(2);
})->with(['newer', 'unchanged']);

it('does not report optional empty acquisition healthy after a newer busy acquisition reserved its season', function () {
    Team::factory()->create(['school' => 'Empty Superseded College', 'division' => 'FBS']);
    Sleep::fake(true, true);
    $inner = null;
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturnUsing(function () use (&$inner) {
        $inner = Artisan::call('cfb:import-fpi', ['--season' => 2026]);

        return ['rows' => [], 'observed_at' => now()->toIso8601String()];
    });
    try {
        expect(Artisan::call('cfb:import-fpi', ['--season' => 2026]))->toBe(1);
        expect($inner)->toBe(1)->and(FpiRating::count())->toBe(0);
        $head = DB::table('cfbd_fpi_season_heads')->sole();
        expect((int) $head->issued_sequence)->toBe(2)->and((int) $head->latest_terminal_sequence)->toBe(2)
            ->and((int) $head->latest_cache_origin_sequence)->toBe(0)->and($head->latest_cache_origin_attempt_id)->toBeNull()
            ->and((int) $head->latest_applied_sequence)->toBe(0);
        expect(DB::table('cfbd_fpi_season_attempts')->orderBy('sequence')->pluck('outcome')->all())->toBe(['superseded', 'failed']);
        expect(DB::table('cfbd_fpi_season_attempts')->whereNotNull('source_cache_key')->count())->toBe(0);

    } finally {
        Sleep::fake(false);
    }
});

it('shares truthful unavailable empty observations across optional and required imports without renewing source TTL', function () {
    config(['services.collegefootballdata.api_key' => 'synthetic-empty-cache']);
    Http::fake(fn () => Http::response([]));
    $observed = now()->startOfSecond();
    $this->travelTo($observed);
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3]))->toBe(0);
    $this->travel(20)->minutes();
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 4, '--require-data' => true]))->toBe(1);
    Http::assertSentCount(1);
    expect(FpiRating::count())->toBe(0);
    expect(DB::table('cfbd_fpi_season_attempts')->orderBy('sequence')->pluck('outcome')->all())->toBe(['unavailable', 'unavailable']);
    expect(DB::table('cfbd_fpi_season_attempts')->orderBy('sequence')->pluck('error_code')->all())->toBe(['empty_source', 'empty_source']);
    $head = DB::table('cfbd_fpi_season_heads')->sole();
    expect((int) $head->latest_applied_sequence)->toBe(0)->and((int) $head->latest_cache_origin_sequence)->toBe(2);
    expect(DB::table('cfbd_fpi_season_attempts')->pluck('source_observed_at')->unique()->count())->toBe(1);
    $keys = DB::table('cfbd_fpi_season_attempts')->pluck('source_cache_key');
    $this->travel(41)->minutes();
    foreach ($keys as $key) {
        expect(Cache::get($key))->toBeNull();
    }
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 5]))->toBe(0);
    Http::assertSentCount(2);
});

it('cannot author an accepted empty cache origin through failure finish or an unstaged commit', function (string $mode) {
    $coordinator = app(FpiSeasonCoordinator::class);
    $context = $coordinator->begin(2026, 3);
    if ($mode === 'finish') {
        $coordinator->finish($context, 'unavailable', 'empty_source');
    } else {
        expect(fn () => $coordinator->commit($context, [], CarbonImmutable::now(), fn () => ['result' => 0, 'bindings' => []]))->toThrow(RuntimeException::class, 'not staged');
    }
    $head = DB::table('cfbd_fpi_season_heads')->sole();
    expect((int) $head->latest_cache_origin_sequence)->toBe(0)->and($head->latest_cache_origin_attempt_id)->toBeNull()
        ->and((int) $head->latest_applied_sequence)->toBe(0)->and(FpiRating::count())->toBe(0);
})->with(['finish', 'commit']);

it('does not mistake absent Team alias column names for stored provider team identities', function (string $alias) {
    Team::factory()->create(['school' => 'Only Actual School College', 'division' => 'FBS']);
    fakeFpiBatchRows([['team' => $alias, 'fpi' => 10]]);
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--require-data' => true]))->toBe(1);
    expect(FpiRating::count())->toBe(0);
})->with(['location', 'display_name', 'short_display_name', 'name']);
