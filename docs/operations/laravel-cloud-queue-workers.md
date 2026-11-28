# Laravel Cloud queue workers

Source reconciliation: November 27, 2026. This guide describes the reviewed source contract and the retained one-live/one-bulk worker baseline. It does not verify the current hosting fleet, process liveness, Redis configuration, enabled alarms or workload capacity. Confirm the active target and dated evidence through [production access](production-access.md) and the connection register (private verification record retained separately) before any release mutation.

## Worker contracts and provisioning

`config/queue.php` declares the following worker contracts. The managed process commands below consume Redis, but the `default` and `sync` contract entries do not force a connection on every job. Ordinary jobs without an explicit connection follow the effective `QUEUE_CONNECTION`; verify that setting and each job's overrides before assuming these consumers receive its work. The scheduled-command wrapper and NBA source queue explicitly select Redis. A queue named `sync` is not Laravel's synchronous `sync` connection. Do not combine live and bulk queues in one worker process.

| Queue | Worker fallback timeout | Tries / backoff | Recycling | Memory flag |
| --- | ---: | --- | --- | ---: |
| `default` | 120 seconds | 3 / 15 seconds | 500 jobs or 3,600 seconds | 384 MiB |
| `sync` | 1,800 seconds | 3 / 30 seconds | 100 jobs or 3,600 seconds | 768 MiB |
| `nba-sources` | 600 seconds | 1 / 0 seconds | 100 jobs or 3,600 seconds | 384 MiB |

The `nba-sources` contract explicitly requires provisioning before bridge activation; its declaration does not provision or activate it. The default/sync retained deployment baseline is one process each, historically described on the existing Flex 1 GiB worker cluster, with no queue worker on web compute. This source review does not reverify that platform sizing or actual worker counts. Keep that baseline until a separately reviewed capacity/concurrency change is justified. Worker memory flags are recycling limits, not a proof that both processes fit the cluster under every workload.

The database/Redis reservation defaults are 2,100 seconds, above the 1,800-second worker fallback. Verify effective environment overrides and each job's explicit timeout; an explicit job timeout can override the worker fallback. ESPN-specific retry policies are owned by those jobs, not universally established by the process flags.

The following commands configure managed processes; they are mutations, not read-only health checks. Use only through the authorized release workflow after verifying the target:

```bash
php artisan queue:work redis --queue=default --sleep=1 --tries=3 --backoff=15 --timeout=120 --max-jobs=500 --max-time=3600 --memory=384
php artisan queue:work redis --queue=sync --sleep=1 --tries=3 --backoff=30 --timeout=1800 --max-jobs=100 --max-time=3600 --memory=768
```

## Scheduler dispatch and completion

The reviewed `$attachCommandHeartbeat` adapter in `routes/console.php` rewrites its attached scheduled commands to `ops:dispatch-scheduled`, shell-quotes the inner command/name/optional heartbeat URL, disables background execution for that dispatcher and records dispatch failure separately. This is a scoped adapter contract, not a statement that every scheduled event uses it: the NBA source job is scheduled directly and has its own activation/worker boundary.

`DispatchScheduledCommand` refuses a recursive dispatcher prefix, queues `RunScheduledCommand` and returns dispatch success. Dispatch success does not establish successful business work. The job explicitly uses Redis. ESPN commands without `--sync` whose scheduled names identify Live Scoreboard or Sync Game Details, and the exact `cfb:sync-live-betting` command, select `default`; other wrapped commands select `sync`. Scheduled job timeouts are 110 seconds/default and 1,700 seconds/sync, below the fallback worker timeouts and reservation defaults. The base job has one attempt; newly dispatched jobs in the explicitly named execution groups below instead carry a bounded retry deadline for lock contention and a one-exception limit.

`RunScheduledCommand` implements `ShouldBeUnique`. Its identity includes the scheduled name and exact command line; the maximum uniqueness lease is 86,400 seconds. Laravel acquires the lock through the configured cache repository and releases it on normal completion or terminal failure. This coalesces identical name/command dispatches while the lease holds; it is not a global lock over different commands, different names or separately queued child jobs. Shared atomic cache/lock configuration and expiry behavior remain deployment prerequisites. Scheduler `onOneServer` and `withoutOverlapping` guards do not replace that job-level boundary.

The job calls Artisan with a `ConsoleOutput`, so command output is streamed rather than accumulated in an Artisan output buffer. A nonzero exit throws. The worker records exit code, duration and process peak PHP memory on the ordinary success path; `failed()` records terminal failure. For command lines containing `--queued`, the wrapper returns without marking pipeline completion: bounded child jobs own completion/heartbeat evidence. Optional external success-heartbeat HTTP failures are reported separately. This review did not execute a command or contact a heartbeat endpoint.

In `finally`, a `sync` job sets the worker's `shouldQuit` flag, so that worker exits after the wrapped command; the hosting supervisor must restart it. The single bulk-consumer baseline serializes wrapped jobs consumed by that process, not all database work or every child job system-wide. No second scheduler or legacy-host duplicate consumer should be introduced as recovery. Do not raise replicas, PHP memory or cluster RAM to conceal oversized reads.

## Deployment and recovery gates

Verify code/queue assignments, managed process configuration, supervisor restart behavior, shared cache locks and effective reservation/job timeouts before enabling or changing consumers. Confirm both active queues have consumers before any separately authorized bounded backfill. Never dispatch unbounded historical work during a live window; separate queues do not eliminate bulk delays.

Use authorized release procedures for process replacement/recycling and `queue:restart`. A documented command or source contract is not proof of a deployed release, completed job, fresh source observation or healthy queue.

## Monitoring and conditional future scaling

The historical suggestions—default age above 5 minutes/depth above 200, sync age above 30 minutes or growing across two cycles, failures/restarts, Redis memory above 70 percent or evictions—are unvalidated proposed thresholds. They are not measured SLOs or enabled alerts. Establish workload/headroom measurements, operator ownership and alert-delivery rehearsal under the readiness scope (private verification record retained separately) before adopting them.

If measured live queue latency eventually warrants additional capacity, propose a separate reviewed change: establish memory/database/provider headroom, shared locks, per-command and child-job concurrency safety, rollback and comparison evidence first. A possible future live-worker increase is conditional, not current operational advice or permission. Bulk scaling additionally invalidates the one-consumer serialization assumption and requires a replacement concurrency policy before approval. Provider throttling is not resolved by adding workers. No scaling or production configuration change was performed for this documentation reconciliation.

## Execution-group retry qualification — November 28, 2026

This dated source correction qualifies the earlier one-attempt description. `RunScheduledCommand` retains name-plus-exact-command pending uniqueness, and separately locks execution for two narrowly defined groups: the NFL pregame pipeline and canonical-cutover readiness verbs share `nfl-canonical-generation-readiness`; MLB `mlb:generate-predictions` jobs share a group only when their complete command strings are identical, even when scheduled names differ. The current daily and post-odds MLB generation entries use the same command string. These locks do not serialize all commands, source refreshes or child jobs.

For newly constructed grouped jobs, the deadline is fixed at construction to the existing 86,400-second uniqueness window, and `maxExceptions=1`. The pinned Laravel worker admits released contention attempts while that deadline is valid, despite the base `tries=1`; a normal command exception remains terminal under the one-exception limit. Busy execution releases the queued job for 30 seconds before running its command. Direct invocation without a queued job refuses when busy. Old serialized payloads default to no new deadline and retain their original attempt policy; this correction does not certify an in-flight payload migration.

An acquired execution lock uses the job timeout plus 60 seconds (normally 170 or 1,760 seconds), and refuses a lease that is not below the effective Redis reservation interval. The lock is released in `finally` on success or exception through its acquired owner token. Cache-lock ownership, expiry, queue reservations and supervisor operation remain deployment prerequisites; abrupt process death relies on lease expiry rather than executing `finally`. No actual production lock, heartbeat, worker topology or recovery latency was exercised by this documentation correction.

The retained one-live/one-bulk baseline and conditional future scaling guidance above remain unchanged. The new groups close only the named worker-execution overlap boundary; they do not authorize more replicas or establish capacity for every other job, provider request, database writer or child process. Scaling still requires a separately reviewed measured concurrency/capacity plan. Scheduler dispatch mutexes and pending-job uniqueness are separate from worker execution locks; neither dispatch acknowledgement nor a group lock proves completed business work.
