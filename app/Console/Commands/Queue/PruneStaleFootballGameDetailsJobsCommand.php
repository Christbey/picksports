<?php

namespace App\Console\Commands\Queue;

use App\Jobs\ESPN\CFB\FetchGameDetails as FetchCfbGameDetails;
use App\Jobs\ESPN\NFL\FetchGameDetails as FetchNflGameDetails;
use App\Models\CFB\Game as CfbGame;
use App\Models\NFL\Game as NflGame;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\RedisStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class PruneStaleFootballGameDetailsJobsCommand extends Command
{
    protected $signature = 'queue:prune-stale-football-game-details
        {--lookback-days=7 : Preserve jobs for games this many days in the past}
        {--days-forward=1 : Preserve jobs for games this many days in the future}
        {--execute : Remove the stale ready jobs; otherwise report only}';

    protected $description = 'Remove stale NFL and CFB game-detail jobs without clearing unrelated Redis queue work';

    /**
     * @var array<class-string, class-string>
     */
    private const TARGETS = [
        FetchNflGameDetails::class => NflGame::class,
        FetchCfbGameDetails::class => CfbGame::class,
    ];

    public function handle(): int
    {
        $queue = Queue::connection('redis');
        if (! $queue instanceof RedisQueue) {
            $this->error('The redis queue connection is not backed by RedisQueue.');

            return self::FAILURE;
        }

        $lowerDate = now()->subDays(max(1, (int) $this->option('lookback-days')))->toDateString();
        $upperDate = now()->addDays(max(0, (int) $this->option('days-forward')))->toDateString();
        $redis = $queue->getConnection();
        $queueKey = $queue->getQueue('default');
        $rawPayloads = $redis->lrange($queueKey, 0, -1);
        $jobsByUuid = [];
        $report = [];

        foreach (self::TARGETS as $jobClass => $gameClass) {
            $payloads = collect($rawPayloads)
                ->map(fn (string $raw): array => ['raw' => $raw, 'payload' => json_decode($raw, true)])
                ->filter(fn (array $row): bool => data_get($row, 'payload.displayName') === $jobClass)
                ->values();

            $jobs = $payloads->mapWithKeys(function (array $row) use ($jobClass): array {
                $uuid = data_get($row, 'payload.uuid');
                $serialized = data_get($row, 'payload.data.command');
                if (! is_string($uuid) || ! is_string($serialized)) {
                    return [];
                }

                $job = unserialize($serialized, ['allowed_classes' => [$jobClass]]);

                return $job instanceof $jobClass ? [$uuid => $job] : [];
            });
            $eventIds = $jobs
                ->map(fn ($job): string => (string) $job->uniqueId())
                ->unique()
                ->values();
            $games = $gameClass::query()
                ->whereIn('espn_event_id', $eventIds->all())
                ->get(['espn_event_id', 'game_date'])
                ->keyBy(fn ($game): string => (string) $game->espn_event_id);

            $remove = $jobs->filter(function ($job) use ($games, $lowerDate, $upperDate): bool {
                $game = $games->get((string) $job->uniqueId());
                if ($game === null || $game->game_date === null) {
                    return false;
                }

                $gameDate = $game->game_date->toDateString();

                return $gameDate < $lowerDate || $gameDate > $upperDate;
            });
            foreach ($remove as $uuid => $job) {
                $jobsByUuid[(string) $uuid] = $job;
            }

            $report[] = [
                class_basename($gameClass),
                $payloads->count(),
                $remove->count(),
                $jobs->count() - $games->count(),
            ];
        }

        $this->line("Preserving game-detail jobs dated {$lowerDate} through {$upperDate}.");
        $this->table(['Sport game model', 'Ready jobs', 'Outside window', 'Unmapped'], $report);

        if ($jobsByUuid === []) {
            $this->info('No stale football game-detail jobs found.');

            return self::SUCCESS;
        }

        if (! $this->option('execute')) {
            $this->warn('Dry run only. Re-run with --execute to remove the reported jobs.');

            return self::SUCCESS;
        }

        $lua = <<<'LUA'
local remove = {}
for index = 1, #ARGV do
    remove[ARGV[index]] = true
end

local values = redis.call('lrange', KEYS[1], 0, -1)
redis.call('del', KEYS[1])
local removed = {}

for _, value in ipairs(values) do
    local decoded, payload = pcall(cjson.decode, value)
    if decoded and payload['uuid'] and remove[payload['uuid']] then
        table.insert(removed, payload['uuid'])
    else
        redis.call('rpush', KEYS[1], value)
    end
end

return removed
LUA;

        $removedUuids = $redis->eval($lua, 1, $queueKey, ...array_keys($jobsByUuid));
        $removedJobs = collect($removedUuids)
            ->map(fn (string $uuid) => $jobsByUuid[$uuid] ?? null)
            ->filter()
            ->unique(fn ($job): string => get_class($job).':'.$job->uniqueId())
            ->values();

        $this->releaseUniqueLocks($removedJobs->all());
        $this->info("Removed {$removedJobs->count()} stale ready job(s) and released their unique locks.");

        return self::SUCCESS;
    }

    /**
     * @param  list<object>  $jobs
     */
    private function releaseUniqueLocks(array $jobs): void
    {
        $store = Cache::getStore();
        if ($store instanceof RedisStore) {
            $keys = collect($jobs)
                ->map(fn (object $job): string => $store->getPrefix().UniqueLock::getKey($job))
                ->unique()
                ->values();

            foreach ($keys->chunk(500) as $chunk) {
                $store->connection()->del(...$chunk->all());
            }

            return;
        }

        $uniqueLock = new UniqueLock(app(CacheRepository::class));
        foreach ($jobs as $job) {
            $uniqueLock->release($job);
        }
    }
}
