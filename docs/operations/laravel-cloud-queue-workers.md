# Laravel Cloud Queue Workers

## Queue contract

Production uses two Redis queues with independent workers. Do not combine them
in a single `--queue=default,sync` process: a long bulk import would consume the
only process and delay live score, detail, grading, and notification work.

| Queue | Workload | Job timeout | Process recycling |
| --- | --- | ---: | --- |
| `default` | Live scoreboards, game details, plays, grading, narratives, notifications | 120 seconds | 500 jobs or 3,600 seconds |
| `sync` | ESPN teams, players, team schedules, depth charts, historical backfills | 1,800 seconds | 100 jobs or 3,600 seconds |

The executable contract is mirrored in `queue.worker_contracts`. The Redis
reservation lease must remain greater than the longest job timeout. The current
2,100-second lease leaves a five-minute recovery margin above the 1,800-second
bulk timeout.

Bulk ESPN jobs retry no more than three times and use 30-, 120-, and 300-second
backoff delays. This keeps a provider outage from becoming a tight retry loop.

## Laravel Cloud processes

Configure two independent background processes using the Redis connection.

Live worker, Flex 512 MB, one replica normally and two replicas during live NFL
or high-overlap multi-sport windows:

```bash
php artisan queue:work redis --queue=default --sleep=1 --tries=3 --backoff=15 --timeout=120 --max-jobs=500 --max-time=3600 --memory=384
```

Bulk worker, Flex 1 GB, one replica:

```bash
php artisan queue:work redis --queue=sync --sleep=1 --tries=3 --backoff=30 --timeout=1800 --max-jobs=100 --max-time=3600 --memory=768
```

Keep the scheduler on the web application process. Do not run a second
scheduler or legacy Forge worker after Cloud workers are enabled.

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
