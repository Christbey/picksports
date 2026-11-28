<?php

namespace App\Console\Commands\Queue;

use App\Jobs\ESPN\CFB\FetchGameDetails as FetchCfbGameDetails;
use App\Jobs\ESPN\NFL\FetchGameDetails as FetchNflGameDetails;
use App\Models\CFB\Game as CfbGame;
use App\Models\NFL\Game as NflGame;
use App\Support\Queue\BoundedReadyQueueScripts;
use Illuminate\Console\Command;
use Illuminate\Queue\RedisQueue;
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
        $notifyKey = $queueKey.':notify';
        try {
            $snapshot = $redis->eval(BoundedReadyQueueScripts::snapshot(), 1, $queueKey);
        } catch (\Throwable) {
            $this->error('Unable to inspect the ready queue; no jobs removed.');

            return self::FAILURE;
        }
        $rawPayloads = $this->validatedSnapshot($snapshot);
        if ($rawPayloads === null) {
            $this->error('Ready queue snapshot exceeds safety bounds or is invalid; no jobs removed.');

            return self::FAILURE;
        }
        $records = [];
        $seenUuids = [];
        foreach ($rawPayloads as $raw) {
            $payload = json_decode($raw, true);
            $jobClass = data_get($payload, 'displayName');
            if (! is_string($jobClass) || ! array_key_exists($jobClass, self::TARGETS)) {
                continue;
            }
            $uuid = data_get($payload, 'uuid');
            $serialized = data_get($payload, 'data.command');
            try {
                if (! is_string($uuid) || $uuid === '' || ! is_string($serialized)
                    || (isset($seenUuids[$uuid]) && $seenUuids[$uuid] !== $raw)) {
                    throw new \UnexpectedValueException;
                }
                $job = @unserialize($serialized, ['allowed_classes' => [$jobClass]]);
                if (! $job instanceof $jobClass || ! is_string($job->uniqueId()) || $job->uniqueId() === '') {
                    throw new \UnexpectedValueException;
                }
            } catch (\Throwable) {
                $this->error('A target job payload is malformed or has ambiguous identity; no jobs removed.');

                return self::FAILURE;
            }
            $seenUuids[$uuid] = $raw;
            $records[] = ['raw' => $raw, 'class' => $jobClass, 'event_id' => $job->uniqueId()];
        }
        $removePayloads = [];
        $report = [];
        foreach (self::TARGETS as $jobClass => $gameClass) {
            $jobs = collect($records)->where('class', $jobClass);
            $games = $jobs->isEmpty() ? collect() : $gameClass::query()
                ->whereIn('espn_event_id', $jobs->pluck('event_id')->unique()->values()->all())
                ->get(['espn_event_id', 'game_date'])
                ->keyBy(fn ($game): string => (string) $game->espn_event_id);
            $unmapped = $stale = 0;
            foreach ($jobs as $job) {
                $game = $games->get($job['event_id']);
                if ($game === null || $game->game_date === null) {
                    $unmapped++;

                    continue;
                }
                $gameDate = $game->game_date->toDateString();
                if ($gameDate < $lowerDate || $gameDate > $upperDate) {
                    $removePayloads[] = $job['raw'];
                    $stale++;
                }
            }
            $report[] = [class_basename($gameClass), $jobs->count(), $stale, $unmapped];
        }

        $this->line("Preserving game-detail jobs dated {$lowerDate} through {$upperDate}.");
        $this->table(['Sport game model', 'Ready jobs', 'Outside window', 'Unmapped'], $report);

        if ($removePayloads === []) {
            $this->info('No stale football game-detail jobs found.');

            return self::SUCCESS;
        }

        if (! $this->option('execute')) {
            $this->warn('Dry run only. Re-run with --execute to remove the reported jobs.');

            return self::SUCCESS;
        }

        try {
            $result = $redis->eval(BoundedReadyQueueScripts::remove(), 2, $queueKey, $notifyKey, ...$removePayloads);
        } catch (\Throwable) {
            $this->error('Ready queue pruning failed; inspect the queue before retrying.');

            return self::FAILURE;
        }
        if (! is_array($result) || ($result[0] ?? null) !== 'ok' || ! is_int($result[1] ?? null)
            || $result[1] < 0 || $result[1] > count($removePayloads)) {
            $this->error('Ready queue changed or exceeded safety bounds; no successful prune reported.');

            return self::FAILURE;
        }
        $this->info('Removed '.$result[1].' stale ready job(s); unique locks retained until natural expiry.');

        return self::SUCCESS;
    }

    /** @return list<string>|null */
    private function validatedSnapshot(mixed $snapshot): ?array
    {
        if (! is_array($snapshot) || ($snapshot[0] ?? null) !== 'ok' || ! is_int($snapshot[1] ?? null)
            || ! is_int($snapshot[2] ?? null) || ! is_array($snapshot[3] ?? null)
            || $snapshot[1] !== count($snapshot[3]) || $snapshot[1] > BoundedReadyQueueScripts::MAX_JOBS) {
            return null;
        }
        $bytes = 0;
        foreach ($snapshot[3] as $raw) {
            if (! is_string($raw) || strlen($raw) > BoundedReadyQueueScripts::MAX_PAYLOAD_BYTES) {
                return null;
            }
            $bytes += strlen($raw);
            if ($bytes > BoundedReadyQueueScripts::MAX_TOTAL_BYTES) {
                return null;
            }
        }

        return $bytes === $snapshot[2] ? array_values($snapshot[3]) : null;
    }
}
