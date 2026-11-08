# Laravel Cloud Queue Workers

## Queue contract

Production uses two Redis queues with independent workers. Do not combine them
in a single `--queue=default,sync` process: a long bulk import would consume the
only process and delay live score, detail, grading, and notification work.

| Queue | Workload | Job timeout | Process recycling |
| --- | --- | ---: | --- |
| `default` | Live scoreboards, game details, plays, grading, narratives, notifications | 120 seconds | 500 jobs or 3,600 seconds |
| `sync` | Serialized scheduled commands, ESPN teams, players, schedules, depth charts, backfills | 1,800 seconds | 100 jobs or 3,600 seconds |

The executable contract is mirrored in `queue.worker_contracts`. The Redis
reservation lease must remain greater than the longest job timeout. The current
2,100-second lease leaves a five-minute recovery margin above the 1,800-second
bulk timeout.

Bulk ESPN jobs retry no more than three times and use 30-, 120-, and 300-second
backoff delays. This keeps a provider outage from becoming a tight retry loop.

## Laravel Cloud processes

Configure two independent background processes using the Redis connection.

Production runs one live worker and one bulk worker as separate processes on the existing Flex 1 GiB worker cluster. The web instance has no queue worker. Keep both worker counts at one; heavy scheduled commands share the bulk consumer, so scheduled calculations cannot overlap one another.

```bash
php artisan queue:work redis --queue=default --sleep=1 --tries=3 --backoff=15 --timeout=120 --max-jobs=500 --max-time=3600 --memory=384
```

Bulk worker, one process on the same existing worker cluster:

```bash
php artisan queue:work redis --queue=sync --sleep=1 --tries=3 --backoff=30 --timeout=1800 --max-jobs=100 --max-time=3600 --memory=768
```

Keep only the scheduler dispatcher on the web application process. Scheduled
commands are wrapped by `ops:dispatch-scheduled` and execute through
`RunScheduledCommand` on the existing Redis workers. Live scoreboard/detail
dispatchers and CFB live betting use `default`; other scheduled work uses `sync`.
CFB `--queued` commands also run off web compute and retain their existing bounded batch jobs and completion heartbeats.

Dispatchers run in the foreground and do not spawn concurrent calculation
processes on web compute. A unique lock coalesces the same pending/running
command for up to 24 hours; it is released on completion or terminal failure.
After each scheduled bulk command, the worker exits cleanly and Cloud restarts it, releasing command instances and cached graphs before the next command. Scheduled commands have one attempt and a 1,700-second bulk timeout (110 seconds
for live dispatch), below worker timeouts and the Redis lease. Nonzero exit codes
fail the job. Worker completion records success/failure, duration and peak PHP
memory; dispatch alone never records successful completion. Command output goes
to worker logs rather than accumulating in a scheduler child or output buffer.

Do not run a second scheduler or legacy Forge worker. Do not increase replicas,
PHP memory limits or instance RAM to compensate for oversized reads. Monitor
queue age after rollout because serialized work can expose slow commands.

## Deployment order

1. Deploy the application code before enabling the `sync` process so all bulk
   jobs carry their queue assignment and retry policy.
2. Start the `sync` worker and confirm it can reserve and complete one bulk job.
3. Replace the shared `default` worker with the dedicated live worker.
4. Run `php artisan queue:restart` after deployments that change job code.
5. Confirm both queues have consumers before dispatching any historical
   backfill.

Never run an unbounded historical backfill during a live game window. Queued
historical scoreboards and details route to `sync`, but they can still delay
other bulk work if thousands are dispatched at once.

## Operational checks

Alert on these conditions:

- oldest `default` job older than five minutes;
- oldest `sync` job older than thirty minutes;
- any failed job or repeated worker restart;
- `default` ready depth above 200;
- `sync` ready depth growing across two consecutive scheduler cycles;
- Redis eviction or memory above 70 percent.

Scale the `default` worker horizontally first when live latency rises. Scale the
`sync` worker only when its oldest-job age grows while Redis and ESPN provider
limits remain healthy. Do not solve provider throttling by adding workers.
