<?php

use App\Jobs\ESPN\NFL\FetchGameDetails;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Queue;

it('atomically removes matching ready payloads and their anonymous notify tokens', function () {
    $staleUuid = '00000000-0000-4000-8000-000000000001';
    $freshUuid = '00000000-0000-4000-8000-000000000002';
    $staleJob = new FetchGameDetails('401000001');
    $freshJob = new FetchGameDetails('401000002');
    [$homeTeam, $awayTeam] = Team::factory()->count(2)->create();

    Game::factory()->create([
        'espn_event_id' => $staleJob->uniqueId(),
        'game_date' => now()->subDays(8),
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);
    Game::factory()->create([
        'espn_event_id' => $freshJob->uniqueId(),
        'game_date' => now(),
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);

    $payload = fn (string $uuid, object $job): string => json_encode([
        'uuid' => $uuid,
        'displayName' => $job::class,
        'data' => ['command' => serialize($job)],
    ], JSON_THROW_ON_ERROR);

    $redis = Mockery::mock();
    $redis->shouldReceive('lrange')
        ->once()
        ->with('queues:default', 0, -1)
        ->andReturn([
            $payload($staleUuid, $staleJob),
            $payload($freshUuid, $freshJob),
            json_encode(['uuid' => 'unrelated', 'displayName' => 'App\\Jobs\\Unrelated'], JSON_THROW_ON_ERROR),
        ]);
    $redis->shouldReceive('eval')
        ->once()
        ->withArgs(function (
            string $lua,
            int $keyCount,
            string $queueKey,
            string $notifyKey,
            string ...$uuids,
        ) use ($staleUuid): bool {
            expect($keyCount)->toBe(2)
                ->and($queueKey)->toBe('queues:default')
                ->and($notifyKey)->toBe('queues:default:notify')
                ->and($uuids)->toBe([$staleUuid])
                ->and($lua)->toContain("redis.call('ltrim', KEYS[2], trimCount, -1)")
                ->and($lua)->toContain('math.min(#removed, notifyCount)');

            return true;
        })
        ->andReturn([$staleUuid]);

    $queue = Mockery::mock(RedisQueue::class);
    $queue->shouldReceive('getConnection')->once()->andReturn($redis);
    $queue->shouldReceive('getQueue')->once()->with('default')->andReturn('queues:default');
    Queue::shouldReceive('connection')->once()->with('redis')->andReturn($queue);

    $this->artisan('queue:prune-stale-football-game-details', [
        '--lookback-days' => 7,
        '--days-forward' => 1,
        '--execute' => true,
    ])
        ->expectsOutput('Removed 1 stale ready job(s) and released their unique locks.')
        ->assertSuccessful();
});
