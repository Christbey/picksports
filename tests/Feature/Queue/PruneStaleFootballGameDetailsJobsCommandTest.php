<?php

use App\Jobs\ESPN\NFL\FetchGameDetails;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function queuePrunerPayload(mixed $uuid, mixed $serialized): string
{
    return json_encode(['uuid' => $uuid, 'displayName' => FetchGameDetails::class,
        'data' => ['command' => $serialized]], JSON_THROW_ON_ERROR);
}

function queuePrunerMock(array $snapshot): object
{
    $redis = Mockery::mock();
    $redis->shouldReceive('eval')->once()->withArgs(fn (string $script, int $count, string $key): bool => $count === 1 && $key === 'queues:default')->andReturn($snapshot);
    $queue = Mockery::mock(RedisQueue::class);
    $queue->shouldReceive('getConnection')->once()->andReturn($redis);
    $queue->shouldReceive('getQueue')->once()->with('default')->andReturn('queues:default');
    Queue::shouldReceive('connection')->once()->with('redis')->andReturn($queue);

    return $redis;
}

function queuePrunerSnapshot(array $raw): array
{
    return ['ok', count($raw), array_sum(array_map(strlen(...), $raw)), $raw];
}

it('passes exact stale payload multiplicities and reports actual removed count without releasing locks', function () {
    $stale = new FetchGameDetails('401000001');
    [$home, $away] = Team::factory()->count(2)->create();
    Game::factory()->create(['espn_event_id' => $stale->uniqueId(), 'game_date' => now()->subDays(8),
        'home_team_id' => $home->id, 'away_team_id' => $away->id]);
    $raw = queuePrunerPayload('same-uuid', serialize($stale));
    $redis = queuePrunerMock(queuePrunerSnapshot([$raw, $raw, '{"displayName":"unrelated"}']));
    $redis->shouldReceive('eval')->once()->withArgs(fn (string $script, int $count, string $key, string $notify, string ...$payloads): bool => $count === 2 && $key === 'queues:default' && $notify === 'queues:default:notify' && $payloads === [$raw, $raw])
        ->andReturn(['ok', 2]);
    $this->artisan('queue:prune-stale-football-game-details', ['--execute' => true])
        ->expectsOutput('Removed 2 stale ready job(s); unique locks retained until natural expiry.')
        ->assertSuccessful();
});

it('does not look up games or execute mutation after a rejected bounded snapshot', function (array $snapshot) {
    queuePrunerMock($snapshot);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $this->artisan('queue:prune-stale-football-game-details', ['--execute' => true])->assertFailed();
    expect($queries)->toBeEmpty();
})->with([
    'count cap' => [['queue_count', 1001, 0]],
    'total bytes' => [['queue_bytes', 17, 4194305]],
    'single payload' => [['payload_bytes', 1, 262145]],
    'malformed reply' => [['ok', 1, 1, [['not-a-string']]]],
]);

it('fails closed on malformed target serialized data or uuid type before game lookup', function (mixed $uuid, string $serialized) {
    queuePrunerMock(queuePrunerSnapshot([queuePrunerPayload($uuid, $serialized)]));
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $this->artisan('queue:prune-stale-football-game-details', ['--execute' => true])->assertFailed();
    expect($queries)->toBeEmpty();
})->with([
    'broken serialization' => ['uuid', 'broken serialized job'],
    'array uuid' => [['invalid'], serialize(new FetchGameDetails('401000001'))],
    'numeric uuid' => [123, serialize(new FetchGameDetails('401000001'))],
]);

it('refuses ambiguous duplicate uuids rather than collapsing different payloads', function () {
    queuePrunerMock(queuePrunerSnapshot([
        queuePrunerPayload('duplicate', serialize(new FetchGameDetails('401000001'))),
        queuePrunerPayload('duplicate', serialize(new FetchGameDetails('401000002'))),
    ]));
    $this->artisan('queue:prune-stale-football-game-details', ['--execute' => true])->assertFailed();
});
