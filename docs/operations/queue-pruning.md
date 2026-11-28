# Bounded football ready-queue pruning

This is explicitly authorized maintenance, not an ordinary sports-analysis step. Verify the target environment through [production access](production-access.md). Inspect command help and run the report before execution:

```bash
php artisan queue:prune-stale-football-game-details --lookback-days=7 --days-forward=1
php artisan queue:prune-stale-football-game-details --lookback-days=7 --days-forward=1 --execute
```

The command examines only the Redis default ready queue and mapped NFL/CFB game-detail jobs outside the stated date window. Unmapped and unrelated work remains. Hard safety limits are 1000 entries, 256 KiB each and 4 MiB total. Exceeding a snapshot/current budget or finding a malformed/ambiguous target payload fails without reporting a successful prune; do not bypass the caps or clear the queue. Plan a separate reviewed drain/recovery for larger backlogs.

Execution removes exact inspected raw payload multiplicities and trims at most the matching number of anonymous notification tokens. Replaced bytes are retained even if their UUID matches. Delayed/reserved queues and unrelated order are preserved. Unique locks are deliberately retained, preventing removal of a newer owner's lock. Target jobs default to a one-hour unique lifetime, so immediate redispatch may wait for the remaining TTL. Nonstandard/no-expiry locks require separate review; never blindly delete them.

Database date classification is observed before Redis mutation, not atomically across both systems. Inspect scope again after game-date corrections. Redis Lua work is bounded but still blocks its event loop; one preexisting oversized element must be read server-side to inspect its length. On an unexpected Redis error inspect actual queue state before retry. Deployment topology/prefix/cluster compatibility and latency remain operational checks.

See [source and local actual-Redis evidence](../contracts/bounded-queue-pruning.md). No production queue or hosted CI execution was used for that proof.

For local regression verification, install the locked PHP dependencies, the PHP Redis extension, Python 3 and the `redis-server`/`redis-cli` binaries, then run:

```bash
python3 scripts/test-redis-pruning.py
```

This runner first exercises mocked command contracts, then starts its own private, socket-only Redis child with a 32 MiB cap and persistence disabled. It verifies the child PID/run ID/disabled TCP port before testing and shutdown, then removes the owned directory. It does not attach to a configured application Redis server. The dedicated `redis-pruning` CI job uses the same runner; its hosted result remains unverified until CI executes.
