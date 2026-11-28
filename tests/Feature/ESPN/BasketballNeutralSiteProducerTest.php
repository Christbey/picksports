<?php

use App\Models\SportEvent;
use App\Models\SportEventProviderMapping;
use App\Models\WNBA\Game;
use App\Services\GameFinalizationDispatcher;
use App\Services\Sports\Exceptions\SportEventIdentityConflict;
use App\Services\Sports\Exceptions\SportEventIdentityRetry;
use App\Services\Sports\SportEventIdentitySynchronizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Http::preventStrayRequests();
});

function basketballNeutralFixture(string $sport, mixed $neutral = true, bool $present = true): array
{
    $namespace = strtoupper($sport);
    $teamClass = 'App\\Models\\'.$namespace.'\\Team';
    $home = $teamClass::factory()->create(['espn_id' => '901']);
    $away = $teamClass::factory()->create(['espn_id' => '902']);
    $competition = ['id' => '409999001', 'date' => now()->addDay()->utc()->toIso8601String(),
        'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
        'competitors' => [
            ['homeAway' => 'home', 'team' => ['id' => '901'], 'score' => null],
            ['homeAway' => 'away', 'team' => ['id' => '902'], 'score' => null],
        ]];
    if ($present) {
        $competition['neutralSite'] = $neutral;
    }
    $event = ['id' => '409999001', 'uid' => 'synthetic-neutral-'.$sport,
        'date' => $competition['date'], 'season' => ['year' => 2026, 'type' => 2],
        'week' => ['number' => 1], 'status' => $competition['status'], 'competitions' => [$competition]];

    return [$home, $away, $event];
}

function basketballNeutralProducer(string $sport, string $path, array $event): object
{
    $namespace = strtoupper($sport);
    $serviceClass = 'App\\Services\\ESPN\\'.$namespace.'\\EspnService';
    $service = Mockery::mock($serviceClass);
    if ($path === 'scoreboard') {
        $service->shouldReceive('getScoreboard')->andReturn(['events' => [$event]]);
        $live = Mockery::mock();
        $live->shouldReceive('execute')->andReturnNull();
        $class = 'App\\Actions\\ESPN\\'.$namespace.'\\SyncGamesFromScoreboard';

        return new $class($service, $live);
    }
    if ($path === 'games') {
        $service->shouldReceive('getGames')->andReturn(['items' => [$event]]);
        $class = 'App\\Actions\\ESPN\\'.$namespace.'\\SyncGames';

        return app($class, ['espnService' => $service]);
    }
    if ($path === 'schedule') {
        $service->shouldReceive('getSchedule')->andReturn(['events' => [$event]]);
        $class = 'App\\Actions\\ESPN\\'.$namespace.'\\'.($sport === 'cbb' ? 'SyncTeamSchedule' : 'SyncGamesFromSchedule');

        return new $class($service);
    }
    $service->shouldReceive('getGame')->andReturn(['header' => ['id' => $event['id'], 'competitions' => $event['competitions']]]);
    $dependencies = [];
    foreach (['SyncPlayerStats', 'SyncTeamStats', 'SyncPlays'] as $name) {
        $class = 'App\\Actions\\ESPN\\'.$namespace.'\\'.$name;
        $mock = Mockery::mock($class);
        $mock->shouldReceive('execute')->andReturn(0);
        $dependencies[] = $mock;
    }
    $class = 'App\\Actions\\ESPN\\'.$namespace.'\\SyncGameDetails';

    return new $class($service, ...$dependencies);
}

function executeBasketballNeutralProducer(object $producer, string $path): mixed
{
    return match ($path) {
        'scoreboard' => $producer->execute(now()->addDay()->utc()->format('Ymd')),
        'games' => $producer->execute(2026, 2, 1),
        'schedule' => $producer->execute('901', 2026),
        'details' => $producer->execute('409999001'),
    };
}

function basketballNeutralProducerPaths(): array
{
    return [
        ['nba', 'scoreboard'], ['cbb', 'scoreboard'], ['wnba', 'scoreboard'], ['wcbb', 'scoreboard'],
        ['nba', 'games'], ['cbb', 'games'], ['wnba', 'games'], ['wcbb', 'games'],
        ['cbb', 'schedule'], ['wcbb', 'schedule'],
        ['nba', 'details'], ['cbb', 'details'], ['wnba', 'details'], ['wcbb', 'details'],
    ];
}

function basketballNeutralExisting(string $sport, Model $home, Model $away, array $event, string $status = 'STATUS_SCHEDULED'): Model
{
    $class = 'App\\Models\\'.strtoupper($sport).'\\Game';
    $canonical = SportEvent::factory()->create(['sport' => $sport, 'neutral_site' => true]);
    $start = CarbonImmutable::parse($event['date'])->utc();

    return $class::factory()->create(['espn_event_id' => $event['id'], 'sport_event_id' => $canonical->id,
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'neutral_site' => true,
        'game_date' => $start->toDateString(), 'game_time' => $start->format('H:i:s'),
        'season' => 2026, 'season_type' => '2', 'status' => $status, 'home_score' => 7, 'away_score' => 4]);
}

it('persists an observed neutral flag through the actual producer into game and canonical event', function (string $sport, string $path) {
    [$home, $away, $event] = basketballNeutralFixture($sport);
    $gameClass = 'App\\Models\\'.strtoupper($sport).'\\Game';
    if ($path === 'details') {
        $canonical = SportEvent::factory()->create(['sport' => $sport, 'neutral_site' => false]);
        $gameClass::factory()->create(['espn_event_id' => $event['id'], 'sport_event_id' => $canonical->id,
            'home_team_id' => $home->id, 'away_team_id' => $away->id, 'status' => 'STATUS_SCHEDULED']);
    }
    executeBasketballNeutralProducer(basketballNeutralProducer($sport, $path, $event), $path);
    $game = $gameClass::query()->where('espn_event_id', $event['id'])->sole();
    expect($game->sportEvent?->neutral_site)->toBeTrue()
        ->and($game->getAttribute('neutral_site'))->toBeTrue();
})->with(basketballNeutralProducerPaths());

it('declines malformed explicit neutral observations before creating any game or event facts', function (string $sport) {
    [, , $event] = basketballNeutralFixture($sport, 'false');
    $producer = basketballNeutralProducer($sport, 'scoreboard', $event);
    expect(executeBasketballNeutralProducer($producer, 'scoreboard'))->toBe(0)
        ->and(SportEvent::query()->count())->toBe(0);
    $gameClass = 'App\\Models\\'.strtoupper($sport).'\\Game';
    expect($gameClass::query()->count())->toBe(0);
})->with(['nba', 'cbb', 'wnba', 'wcbb']);

it('reuses the persisted ESPN event identity on repeated women basketball games imports', function (string $sport) {
    [, , $event] = basketballNeutralFixture($sport);
    $producer = basketballNeutralProducer($sport, 'games', $event);
    expect(executeBasketballNeutralProducer($producer, 'games'))->toBe(1);
    expect(executeBasketballNeutralProducer($producer, 'games'))->toBe(1);
    $gameClass = 'App\\Models\\'.strtoupper($sport).'\\Game';
    expect($gameClass::query()->where('espn_event_id', $event['id'])->count())->toBe(1)
        ->and(SportEvent::query()->where('sport', $sport)->count())->toBe(1);
})->with(['wnba', 'wcbb']);

it('persists explicit false as a correction to a known neutral observation', function (string $sport, string $path) {
    [$home, $away, $event] = basketballNeutralFixture($sport, false);
    $game = basketballNeutralExisting($sport, $home, $away, $event);
    executeBasketballNeutralProducer(basketballNeutralProducer($sport, $path, $event), $path);

    expect($game->fresh()->neutral_site)->toBeFalse()
        ->and($game->fresh()->sportEvent?->neutral_site)->toBeFalse();
})->with(basketballNeutralProducerPaths());

it('preserves known observations when the source omits or nulls its neutral flag', function (string $sport, bool $present) {
    [$home, $away, $event] = basketballNeutralFixture($sport, null, $present);
    $game = basketballNeutralExisting($sport, $home, $away, $event);
    executeBasketballNeutralProducer(basketballNeutralProducer($sport, 'scoreboard', $event), 'scoreboard');

    expect($game->fresh()->neutral_site)->toBeTrue()
        ->and($game->fresh()->sportEvent?->neutral_site)->toBeTrue();
})->with(['nba', 'cbb', 'wnba', 'wcbb'])->with([true, false]);

it('leaves a new absent observation unknown in both persistence forms', function (string $sport) {
    [, , $event] = basketballNeutralFixture($sport, null, false);
    executeBasketballNeutralProducer(basketballNeutralProducer($sport, 'scoreboard', $event), 'scoreboard');
    $class = 'App\\Models\\'.strtoupper($sport).'\\Game';
    $game = $class::query()->sole();
    expect($game->neutral_site)->toBeNull()->and($game->sportEvent?->neutral_site)->toBeNull();
})->with(['nba', 'cbb', 'wnba', 'wcbb']);

it('declines malformed observations before modifying existing game and canonical facts', function (string $sport, string $path) {
    [$home, $away, $event] = basketballNeutralFixture($sport, 'false');
    $game = basketballNeutralExisting($sport, $home, $away, $event);
    $before = $game->fresh()->getAttributes();
    $canonicalBefore = $game->sportEvent->getAttributes();
    if ($sport === 'cbb' && $path === 'details') {
        expect(fn () => executeBasketballNeutralProducer(basketballNeutralProducer($sport, $path, $event), $path))->toThrow(RuntimeException::class);
        $operational = array_flip(['details_attempted_at', 'details_status', 'updated_at']);
        $before = array_diff_key($before, $operational);
        expect($game->fresh()->details_status)->toBe('failed');
    } else {
        executeBasketballNeutralProducer(basketballNeutralProducer($sport, $path, $event), $path);
        $operational = [];
    }

    expect(array_diff_key($game->fresh()->getAttributes(), $operational))->toBe($before)
        ->and($game->sportEvent->fresh()->getAttributes())->toBe($canonicalBefore);
})->with(['nba', 'cbb', 'wnba', 'wcbb'])->with(['scoreboard', 'details']);

it('carries an observed neutral flag through orphaned scoreboard summary repair', function (string $sport) {
    [$home, $away, $event] = basketballNeutralFixture($sport);
    $game = basketballNeutralExisting($sport, $home, $away, $event);
    $game->update(['neutral_site' => false]);
    $game->sportEvent->update(['neutral_site' => false]);
    $namespace = strtoupper($sport);
    $service = Mockery::mock('App\\Services\\ESPN\\'.$namespace.'\\EspnService');
    $service->shouldReceive('getScoreboard')->andReturn(['events' => []]);
    $service->shouldReceive('getGame')->once()->andReturn(['header' => ['id' => $event['id'], 'competitions' => $event['competitions']]]);
    $live = Mockery::mock();
    $live->shouldReceive('execute')->andReturnNull();
    $class = 'App\\Actions\\ESPN\\'.$namespace.'\\SyncGamesFromScoreboard';
    executeBasketballNeutralProducer(new $class($service, $live), 'scoreboard');

    expect($game->fresh()->neutral_site)->toBeTrue()
        ->and($game->fresh()->sportEvent?->neutral_site)->toBeTrue();
})->with(['nba', 'cbb', 'wnba', 'wcbb']);

it('adds WNBA neutral storage as nullable without manufacturing existing observations', function () {
    [$home, $away, $event] = basketballNeutralFixture('wnba');
    expect(Schema::hasColumn('wnba_games', 'neutral_site'))->toBeTrue();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id]);
    expect($game->fresh()->neutral_site)->toBeNull();
});

it('keeps a linked neutral observation unchanged when canonical synchronization fails', function (string $sport, string $path) {
    [$home, $away, $event] = basketballNeutralFixture($sport);
    $game = basketballNeutralExisting($sport, $home, $away, $event);
    $game->update(['neutral_site' => false]);
    $game->sportEvent->update(['neutral_site' => false]);
    $dispatcher = Mockery::mock(GameFinalizationDispatcher::class);
    $dispatcher->shouldNotReceive('dispatchIfFinalizedTransition');
    app()->instance(GameFinalizationDispatcher::class, $dispatcher);
    $connection = $game->getConnection();
    $callbacks = new ReflectionProperty($connection, 'beforeExecutingCallbacks');
    $original = $callbacks->getValue($connection);
    $intercepted = false;
    $connection->beforeExecuting(function (string $sql) use (&$intercepted): void {
        if (preg_match('/^\s*update\s+[`"]?sport_events[`"]?\s/i', $sql)) {
            $intercepted = true;
            throw new RuntimeException('Synthetic canonical observation persistence failure.');
        }
    });
    try {
        expect(fn () => executeBasketballNeutralProducer(basketballNeutralProducer($sport, $path, $event), $path))
            ->toThrow(RuntimeException::class, 'Synthetic canonical observation persistence failure.');
    } finally {
        $callbacks->setValue($connection, $original);
    }
    expect($intercepted)->toBeTrue();
    expect($game->fresh()->neutral_site)->toBeFalse()
        ->and($game->sportEvent->fresh()->neutral_site)->toBeFalse();
})->with(basketballNeutralProducerPaths());

it('rolls back a newly created game when canonical event insertion fails', function (string $sport) {
    [, , $event] = basketballNeutralFixture($sport);
    $class = 'App\\Models\\'.strtoupper($sport).'\\Game';
    $connection = (new $class)->getConnection();
    $callbacks = new ReflectionProperty($connection, 'beforeExecutingCallbacks');
    $original = $callbacks->getValue($connection);
    $intercepted = false;
    $connection->beforeExecuting(function (string $sql) use (&$intercepted): void {
        if (preg_match('/^\s*insert\s+into\s+[`"]?sport_events[`"]?\s/i', $sql)) {
            $intercepted = true;
            throw new RuntimeException('Synthetic canonical creation failure.');
        }
    });
    try {
        expect(fn () => executeBasketballNeutralProducer(basketballNeutralProducer($sport, 'scoreboard', $event), 'scoreboard'))
            ->toThrow(RuntimeException::class, 'Synthetic canonical creation failure.');
    } finally {
        $callbacks->setValue($connection, $original);
    }
    expect($intercepted)->toBeTrue()
        ->and($class::query()->count())->toBe(0)
        ->and(SportEvent::query()->count())->toBe(0)
        ->and(SportEventProviderMapping::query()->count())->toBe(0);
})->with(['nba', 'cbb', 'wnba', 'wcbb']);

it('refuses a mixed connection before local identity mutation', function () {
    [$home, $away, $event] = basketballNeutralFixture('nba');
    $game = basketballNeutralExisting('nba', $home, $away, $event);
    config(['database.connections.synthetic_identity' => config('database.connections.'.config('database.default'))]);
    $game->setConnection('synthetic_identity');
    $called = false;
    expect(fn () => app(SportEventIdentitySynchronizer::class)->mutateAndSync('nba', $game, function () use (&$called): void {
        $called = true;
    }))->toThrow(SportEventIdentityConflict::class);
    expect($called)->toBeFalse();
});

it('declines an explicit mismatched details header before changing observations', function (string $sport) {
    [$home, $away, $event] = basketballNeutralFixture($sport);
    $game = basketballNeutralExisting($sport, $home, $away, $event);
    $game->update(['neutral_site' => false]);
    $game->sportEvent->update(['neutral_site' => false]);
    $event['id'] = 'different-source-event';
    if ($sport === 'cbb') {
        expect(fn () => executeBasketballNeutralProducer(basketballNeutralProducer($sport, 'details', $event), 'details'))->toThrow(RuntimeException::class);
    } else {
        executeBasketballNeutralProducer(basketballNeutralProducer($sport, 'details', $event), 'details');
    }
    expect($game->fresh()->neutral_site)->toBeFalse()
        ->and($game->sportEvent->fresh()->neutral_site)->toBeFalse();
})->with(['nba', 'cbb', 'wnba', 'wcbb']);

it('refuses stale primary references even when both aliases map to one canonical event', function () {
    [$home, $away, $event] = basketballNeutralFixture('nba');
    $game = basketballNeutralExisting('nba', $home, $away, $event);
    $canonical = app(SportEventIdentitySynchronizer::class)->sync('nba', $game);
    SportEventProviderMapping::create(['sport_event_id' => $canonical->id, 'provider' => 'espn', 'provider_event_id' => 'changed-primary']);
    $game->newQuery()->whereKey($game->id)->update(['espn_event_id' => 'changed-primary']);
    $called = false;
    expect(fn () => app(SportEventIdentitySynchronizer::class)->mutateAndSync('nba', $game, function () use (&$called): void {
        $called = true;
    }))->toThrow(SportEventIdentityRetry::class);
    expect($called)->toBeFalse()
        ->and($game->fresh()->espn_event_id)->toBe('changed-primary');
});

it('rolls back a raw callback identity redirect before locking an unapproved event', function () {
    [$home, $away, $event] = basketballNeutralFixture('nba');
    $game = basketballNeutralExisting('nba', $home, $away, $event);
    $replacement = SportEvent::factory()->create(['sport' => 'nba']);
    $oldId = $game->sport_event_id;
    expect(fn () => app(SportEventIdentitySynchronizer::class)->mutateAndSync('nba', $game, function (Model $locked) use ($replacement): void {
        $locked->newQuery()->whereKey($locked->id)->update(['sport_event_id' => $replacement->id]);
    }))->toThrow(SportEventIdentityConflict::class);
    expect($game->fresh()->sport_event_id)->toBe($oldId);
});
