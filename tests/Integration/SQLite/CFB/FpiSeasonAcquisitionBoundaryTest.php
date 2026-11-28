<?php

use App\Models\CFB\FpiRating;
use App\Models\CFB\Team;
use App\Models\CFB\TeamSeasonAffiliation;
use App\Models\CfbdTeamMapping;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use App\Services\CollegeFootballData\Fpi\FpiSeasonCoordinator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
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
    Sleep::fake(false);
    $this->travelBack();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

it('preserves cold fetch coalescing with explicit busy and superseded failures before a fresh retry', function () {
    Http::preventStrayRequests();
    $team = Team::factory()->create(['school' => 'Season Ordering College', 'division' => 'FBS']);
    Sleep::fake(true, true); // Advance the frozen clock for the actual Laravel blocking deadline.
    $calls = 0;
    $innerExit = null;
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->with(2026)
        ->andReturnUsing(function () use (&$calls, &$innerExit) {
            $calls++;
            if ($calls === 1) {
                $innerExit = Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]);

                return ['rows' => [['team' => 'Season Ordering College', 'fpi' => 10]], 'observed_at' => now()->toIso8601String()];
            }

            return ['rows' => [['team' => 'Season Ordering College', 'fpi' => 20]], 'observed_at' => now()->toIso8601String()];
        });
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(1);
    expect($innerExit)->toBe(1)->and($calls)->toBe(1)->and(FpiRating::count())->toBe(0);
    $head = DB::table('cfbd_fpi_season_heads')->sole();
    expect((int) $head->issued_sequence)->toBe(2)->and((int) $head->latest_terminal_sequence)->toBe(2)
        ->and((int) $head->latest_applied_sequence)->toBe(0)->and($head->latest_cache_origin_attempt_id)->toBeNull();
    expect(DB::table('cfbd_fpi_season_attempts')->orderBy('sequence')->pluck('outcome')->all())
        ->toBe(['superseded', 'failed']);
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(0);
    expect((float) FpiRating::where('team_id', $team->id)->sole()->fpi)->toBe(20.0)->and($calls)->toBe(2);
    expect((int) DB::table('cfbd_fpi_season_heads')->value('latest_applied_sequence'))->toBe(3);
    Http::assertNothingSent();
});

it('preserves a supported single complete FPI acquisition', function () {
    Http::preventStrayRequests();
    $team = Team::factory()->create(['school' => 'Single Ordering College', 'division' => 'FBS']);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->with(2026)
        ->andReturn(['rows' => [['team' => 'Single Ordering College', 'fpi' => 12.5]], 'observed_at' => now()->toIso8601String()]);
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(0);
    expect((float) FpiRating::where('team_id', $team->id)->sole()->fpi)->toBe(12.5);
    Http::assertNothingSent();
});

it('commits an owned reservation before the provider boundary', function () {
    $team = Team::factory()->create(['school' => 'Reserved College', 'division' => 'FBS']);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturnUsing(function () {
        expect(DB::transactionLevel())->toBe(0);
        $receipt = DB::table('cfbd_fpi_season_attempts')->sole();
        expect($receipt->outcome)->toBeNull()->and((int) $receipt->sequence)->toBe(1);

        return ['rows' => [['team' => 'Reserved College', 'fpi' => 7]], 'observed_at' => now()->toIso8601String()];
    });
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--require-complete' => true]))->toBe(0);
    expect((float) FpiRating::where('team_id', $team->id)->sole()->fpi)->toBe(7.0);
    expect(DB::table('cfbd_fpi_season_attempts')->value('outcome'))->toBe('applied');
});

it('refuses unavailable activation and inherited transactions before provider IO', function (string $mode) {
    $this->mock(CollegeFootballDataService::class)->shouldNotReceive('getFpiSeasonSnapshot');
    $connection = DB::connection();
    if ($mode === 'missing') {
        $connection->table('cfbd_fpi_season_heads')->delete();
    }
    if ($mode === 'transaction') {
        $connection->beginTransaction();
    }
    try {
        expect(Artisan::call('cfb:import-fpi', ['--season' => 2026]))->toBe(1);
        expect($connection->table('cfbd_fpi_season_attempts')->count())->toBe(0);
        if ($mode === 'transaction') {
            expect($connection->transactionLevel())->toBe(1);
        }
    } finally {
        if ($mode === 'transaction') {
            $connection->rollBack();
        }
    }
})->with(['missing', 'transaction']);

it('refuses stale source ownership before any rating or binding publication', function (string $change) {
    $team = Team::factory()->create(['school' => 'Source College', 'division' => 'FBS', 'cfbd_team_id' => null]);
    $rating = FpiRating::factory()->create(['team_id' => $team->id, 'season' => 2026, 'week' => 3, 'fpi' => 5]);
    $before = $rating->fresh()->getAttributes();
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturnUsing(function () use ($team, $change) {
        match ($change) {
            'owner' => $team->update(['cfbd_team_id' => 999]),
            'mapping' => CfbdTeamMapping::create(['cfbd_team_id' => 200, 'cfbd_team_name' => 'New College', 'espn_team_name' => 'Source College', 'sport' => 'cfb']),
            'team' => Team::factory()->create(['school' => 'Additional College', 'division' => 'FBS']),
            'affiliation' => TeamSeasonAffiliation::create(['team_id' => $team->id, 'season' => 2026, 'subdivision' => 'FCS', 'conference' => null, 'division' => null, 'source' => 'synthetic-observed-test']),
            'config' => config(['cfb.season_affiliations.overrides' => ['Source College' => [['start_season' => 2026, 'subdivision' => 'FCS']]]]),
            'lease' => $this->travel(121)->seconds(),
        };

        return ['rows' => [['team' => 'Source College', 'fpi' => 10]], 'observed_at' => now()->toIso8601String()];
    });
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3]))->toBe(1);
    expect($rating->fresh()->getAttributes())->toBe($before);
    expect(DB::table('cfbd_fpi_season_attempts')->value('outcome'))->toBe($change === 'lease' ? 'expired' : 'failed');
})->with(['owner', 'mapping', 'team', 'affiliation', 'config', 'lease']);

it('preserves optional partial and repeated-team import semantics without a coverage bypass', function () {
    $team = Team::factory()->create(['school' => 'Partial College', 'division' => 'FBS']);
    Team::factory()->create(['school' => 'Missing College', 'division' => 'FBS']);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturn([
        'rows' => [['team' => 'Partial College', 'fpi' => 5], ['team' => 'Partial College', 'fpi' => 8]], 'observed_at' => now()->toIso8601String()]);
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026]))->toBe(0);
    expect(FpiRating::count())->toBe(1)->and((float) FpiRating::where('team_id', $team->id)->sole()->fpi)->toBe(8.0);
});

it('rolls back cancelled or rewritten FPI persistence without acknowledging the proposed values', function (string $mode) {
    $team = Team::factory()->create(['school' => 'Persistence College', 'division' => 'FBS']);
    $rating = FpiRating::factory()->create(['team_id' => $team->id, 'season' => 2026, 'week' => 3, 'fpi' => 5]);
    $before = $rating->fresh()->getAttributes();
    $dispatcher = FpiRating::getEventDispatcher();
    FpiRating::setEventDispatcher(clone $dispatcher);
    FpiRating::saving(function ($row) use ($mode) {
        if ($mode === 'cancel') {
            return false;
        }$row->fpi = 12.3454;
    });
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturn([
        'rows' => [['team' => 'Persistence College', 'fpi' => 12.345]], 'observed_at' => now()->toIso8601String()]);
    try {
        expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true]))->toBe(1);
        expect($rating->fresh()->getAttributes())->toBe($before);
        expect(DB::table('cfbd_fpi_season_attempts')->value('outcome'))->toBe('failed');
    } finally {
        FpiRating::setEventDispatcher($dispatcher);
    }
})->with(['cancel', 'rewrite']);

it('refuses nonfinite numeric source values and invalid observation clocks atomically', function (string $mode) {
    $team = Team::factory()->create(['school' => 'Scalar College', 'division' => 'FBS']);
    $rating = FpiRating::factory()->create(['team_id' => $team->id, 'season' => 2026, 'week' => 3, 'fpi' => 5]);
    $before = $rating->fresh()->getAttributes();
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturn([
        'rows' => [['team' => 'Scalar College', 'fpi' => $mode === 'number' ? INF : 12]],
        'observed_at' => $mode === 'naive' ? '2026-11-27 12:00:00' : ($mode === 'calendar' ? '2026-02-30T12:00:00Z' : now()->toIso8601String())]);
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => 3]))->toBe(1);
    expect($rating->fresh()->getAttributes())->toBe($before);
})->with(['number', 'naive', 'calendar']);

it('preserves noncalendar bookkeeping weeks through the existing signed integer storage capacity', function (int $week) {
    $team = Team::factory()->create(['school' => 'Week College', 'division' => 'FBS']);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturn([
        'rows' => [['team' => 'Week College', 'fpi' => 12.345, 'season' => '2026']], 'observed_at' => now()->toIso8601String()]);
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => $week, '--require-complete' => true]))->toBe(0);
    expect(FpiRating::where('team_id', $team->id)->sole()->week)->toBe($week);
})->with([70000, 2147483647]);

it('refuses malformed week selectors instead of silently selecting another snapshot', function (string $week) {
    $this->mock(CollegeFootballDataService::class)->shouldNotReceive('getFpiSeasonSnapshot');
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--week' => $week]))->toBe(1);
    expect(DB::table('cfbd_fpi_season_attempts')->count())->toBe(0);
})->with(['-1', 'junk', '2147483648']);

it('rechecks lease and owner after row persistence before acknowledging the batch', function (string $change) {
    Team::factory()->create(['school' => 'Final Fence College', 'division' => 'FBS']);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturn([
        'rows' => [['team' => 'Final Fence College', 'fpi' => 11]], 'observed_at' => now()->toIso8601String()]);
    $dispatcher = FpiRating::getEventDispatcher();
    FpiRating::setEventDispatcher(clone $dispatcher);
    FpiRating::created(function () use ($change) {
        if ($change === 'clock') {
            $this->travel(121)->seconds();
        }
        if ($change === 'owner') {
            DB::table('cfbd_fpi_season_attempts')->update(['owner_hash' => str_repeat('0', 64)]);
        }
        if ($change === 'sequence') {
            DB::table('cfbd_fpi_season_heads')->update(['issued_sequence' => 2]);
        }
    });
    try {
        expect(Artisan::call('cfb:import-fpi', ['--season' => 2026, '--require-complete' => true]))->toBe(1);
        expect(FpiRating::count())->toBe(0);
        expect(DB::table('cfbd_fpi_season_attempts')->value('outcome'))->toBe($change === 'clock' ? 'expired' : 'failed');
    } finally {
        FpiRating::setEventDispatcher($dispatcher);
    }
})->with(['clock', 'owner', 'sequence']);

it('refuses cloned reservation instances and failure paths cannot author applied receipts', function () {
    $coordinator = app(FpiSeasonCoordinator::class);
    $context = $coordinator->begin(2026, 0);
    expect(fn () => $coordinator->remaining(clone $context))->toThrow(RuntimeException::class);
    expect(fn () => $coordinator->finish($context, 'applied', 'synthetic'))->toThrow(RuntimeException::class);
    $coordinator->finish($context, 'failed', 'synthetic_no_acquisition');
    expect(DB::table('cfbd_fpi_season_attempts')->value('outcome'))->toBe('failed');
    expect(FpiRating::count())->toBe(0);
});

it('refuses attempts whose fixed lease or raw sequence was altered during acquisition', function (string $field) {
    Team::factory()->create(['school' => 'Fixed Lease College', 'division' => 'FBS']);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->once()->andReturnUsing(function () use ($field) {
        DB::table('cfbd_fpi_season_attempts')->update([$field => $field === 'sequence' ? 1.5 : now('UTC')->addHour()->format('Y-m-d H:i:s.u')]);

        return ['rows' => [['team' => 'Fixed Lease College', 'fpi' => 9]], 'observed_at' => now()->toIso8601String()];
    });
    expect(Artisan::call('cfb:import-fpi', ['--season' => 2026]))->toBe(1);
    expect(FpiRating::count())->toBe(0);
})->with(['sequence', 'expires_at']);
