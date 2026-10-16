# Production storage and memory remediation plan

Status: first write optimization and additive archive support deployed; historical reclamation remains pending. See the dated production rollout section at the end for current evidence.
Evidence date: October 15, 2026, America/Chicago.

## Objectives

Restore reliable writes and authenticated pages, keep sufficient disk and memory headroom, and reduce recurring growth without losing prediction reproducibility, source evidence, or historical analysis capability.

## Verified baseline

Read-only Laravel Cloud infrastructure inspection and production database metadata showed:

- MySQL cluster: `mysql-pro-4gb`, configured storage 27 GB, seven-day scheduled snapshot retention, no configured storage autoscale maximum. Whether autoscaling is enabled or supported must be confirmed separately.
- Database global `read_only=1` at 18:22 Central. Logged error 1290 rejects writes, including the authenticated-request activity update, causing HTTP 500s. Cluster storage alerts reported over 95% usage; the provider's exact read-only trigger remains unverified.
- App: one `flex-512mb` replica, scheduler enabled, plus one live Redis worker on this same instance. Worker command specifies `--memory=384`, 120-second timeout, 500-job/hour recycling.
- Bulk sync: one separate `flex-1gb` instance with one Redis `sync` worker, `--memory=768`, 1,800-second timeout, 100-job/hour recycling.
- Worker memory flags are recycling thresholds, not a combined instance memory budget or protection against a single oversized job.
- Scheduler helpers run multiple commands in background processes. Per-command overlap locks do not cap aggregate concurrency across different commands.
- Available recent log samples did not establish the specific memory failure, affected service, or peak resident memory. Do not claim a confirmed leak or OOM root cause yet.

Approximate table allocations from `information_schema.tables`, including secondary indexes, in decimal GB:

| Dataset | GB |
| --- | ---: |
| NFL injury snapshots plus entries | 3.14 |
| Prediction feature snapshots | 2.67 |
| CFB plays | 2.40 |
| MLB plays | 1.52 |
| Event input snapshots | 1.51 |
| Calculation runs | 1.14 |
| Bet decisions | 0.99 |
| NFL research documents plus revisions | 1.25 |

These estimates do not measure reclaimable bytes, exact row counts, or total cluster disk usage. Binary logs, undo, temporary files, system tablespaces, and other schemas require separate accounting.

Bounded 100-record samples found average event-input JSON of 320 KB, calculation diagnostics of 86 KB, and embedded bet-decision features of 191 KB. Samples are diagnostic, not table-wide savings estimates. Injury writers store both report payloads and entry payloads; bet decisions store a snapshot reference and embedded features. Prediction feature recording creates a new row for each recording, even though a feature hash is available. Injury recording already skips unchanged latest payload hashes; investigate remaining growth before changing that behavior.

Evidence: Cloud commands `comm-a2fdbb52-a380-4e45-98f5-1e974fa69399` (table sizes/read-only state), `comm-a2fdbb78-9c3f-4aec-b18c-80e5ef11f0d2` (bounded JSON samples); instance and background-process list responses. No credentials belong in this document.

## Phase 0 — Restore service and establish headroom

User constraint: existing allocated disk cannot shrink. Do not increase disk or enable automatic upsizing as the default recovery step. Compare supported in-place reclamation and logical migration into a compact replacement cluster first. Any capacity increase needs a measured justification and explicit user approval.

1. Confirm current cluster usage, growth, writable state, and snapshot health in Laravel Cloud. Check provider events for storage protection, maintenance, or failover. At 18:34 Central the server reported `read_only=0`, and the configured disk remained 27 GB; no recovery mutation was made by this investigation. Verify application write health separately before declaring recovery complete.
2. Account for logical data, physical table files, binary logs, undo, redo, temporary files, and other schemas. Check provider-managed cleanup and actual dashboard free space before considering a resize. Do not infer free disk from table statistics or tablespace file length alone.
3. If writes remain blocked, use provider recovery guidance/support; do not force-disable database protections.
4. Temporarily defer nonessential historical imports, training, and replay jobs if they worsen recovery pressure. Preserve live ingestion, grading, and required evidence; record any resulting freshness gaps. Resume in a controlled order.
5. Verify a controlled application write, authenticated homepage/dashboard/API, and new successful pipeline heartbeats. Reads alone do not establish recovery. Do not replay the entire backlog immediately.

Exit: writes accepted, authenticated smoke checks pass, no new read-only exceptions, and enough free space for the next phase. Initial target: disk below 70% after recovery, adjusted for measured growth and maintenance needs.

### Follow-up audit and recovery decision — 18:34 Central

- Production table data: 17.44 GB; secondary indexes: 1.72 GB; combined estimate: **19.16 GB**. The other visible application schema, `card_catalog`, adds approximately 0.11 GB. Account for it in any whole-cluster recovery; do not assume PickSports is the only consumer.
- Reported production `data_free` totals about 0.49 GB. This is an allocation statistic, not a guaranteed filesystem reclamation estimate. It does not justify a claim of several GB of readily removable fragmentation.
- InnoDB uses file-per-table. Physical file lengths exceed table estimates for several large tables: feature snapshots 3.79 GB, MLB plays 3.00 GB, bet decisions 1.75 GB. These differences are candidates for investigation, not promised savings. `ALLOCATED_SIZE` differs substantially from file length; compression/sparse allocation and provider disk accounting must be reconciled before claiming either number is actual billed usage.
- Reading binary log sizes was denied because the existing database account lacks the required replication privilege. Do not grant new privileges or purge logs to work around this. Provider metrics/support must resolve the remaining disk accounting.
- Server reports binary-log expiration 43,200 seconds (12 hours), redo capacity 512 MiB, buffer pool 2 GiB, maximum connections 409. Provider documentation describes roughly eight-day normal log retention plus emergency cleanup above 90%; this differs from the observed expiry setting. Ask support which mechanism applies rather than rewriting retention.
- MySQL uptime was roughly 27.7 days, peak connections 62, current connections 11. These values do not establish database memory exhaustion; a 2 GiB buffer pool on a 4 GB database is not itself proof of a leak. App/worker memory remains a separate investigation.
- A concrete write-amplification candidate exists in `AbstractSyncPlayerInjuries`: each reliable sync deactivates all active team rows, then upserts the returned rows and payloads. Even when snapshot hashing avoids a new history record, this current-state path still writes. Prototype comparison-based updates that write only meaningful changes, retaining source availability, observed freshness, reliability behavior, and removals. Validate equivalent results before deployment.

Evaluate recovery options in this order:

| Path | Gate | Main tradeoff |
| --- | --- | --- |
| Keep current 27 GB and reduce churn | Dashboard shows adequate free space after provider cleanup; growth projections support it | Lowest migration risk; still requires payload-growth fixes |
| Targeted in-place archive/reclamation | Verified archive pilot, supported reader migration, and measured space for the largest selected rebuild | Physical reclamation may temporarily need more room than is available |
| Logical migration into a replacement cluster | Rehearsed compact import fits target with growth/log headroom and temporary dual-cluster cost is accepted | Requires maintenance/write coordination and validated cutover |
| Increase existing disk | Above paths cannot safely restore/maintain service in the available time | Permanent allocation; choose minimum justified capacity with approval |

A snapshot restore is not assumed to compact data or permit a smaller disk. The replacement route requires a measured logical export/import. Prefer preparing exports and transforming archived payloads away from the full production volume. Preserve all row identities, foreign keys, timestamps, semantic hashes, immutable lineage, and both application schemas where needed. Use a dedicated rehearsal target, not an unreviewed production schema rewrite.

For cutover, stop all relevant writers (web mutations, schedulers, workers, and administrative commands), drain or explicitly account for queued/in-flight work, reconcile final changes, verify exact counts/checksums and application behavior, then attach/redeploy against the new cluster. Keep the old source recoverable. Once writes begin on the new cluster, switching back is not safe without reconciling those writes; define that rollback boundary in the rehearsal.

Provider references reviewed October 15, 2026:

- https://laravel.com/cloud/docs/resources/databases/laravel-mysql#editing-and-resizing-database-clusters — disk only increases; CPU/RAM can change in either direction.
- https://laravel.com/cloud/docs/resources/databases/laravel-mysql#binary-logs-and-storage-usage — binary logs share the data volume and provider-managed cleanup can lower reported usage.
- https://laravel.com/cloud/docs/resources/databases/laravel-mysql#restoring-from-backups — snapshot restores create another cluster; no compaction guarantee is stated.

Follow-up evidence command: `comm-a2fdbf98-0a5d-412f-800b-64ec90a5a592`. Outstanding: current dashboard bytes/usage chart, provider log/temp/undo breakdown, cause of the read-only transition/recovery, and peak memory metrics. No new support message has been sent.

## Phase 1 — Measure memory and growth before tuning

### User-provided database metrics — screenshot at 18:36 Central

The last-day database-cluster chart shows **25.5 GiB used of 27 GiB**, approximately **94.4% used and 1.5 GiB free**. The dashboard units are GiB, whereas the earlier table-size report used decimal GB: production table/index estimates of 19.16 GB equal about 17.84 GiB. The roughly 7.66 GiB difference between that estimate and the dashboard includes other schema data, physical allocation differences, database logs, temporary/system files, and measurement timing; it is not a proven amount of waste or reclaimable binary logs.

Database memory appears approximately 77–80% and broadly stable over the displayed day (about 3.1 GiB on the 4 GiB cluster). CPU is mostly low with short spikes. This screenshot does not establish a memory leak, OOM event, or need to increase database RAM; buffer/cache use can account for a stable baseline. It measures database compute, not the 512 MB application or its workers. Check memory composition, pressure, swap if exposed, query latency, and OOM/restart evidence before changing database memory. Prefer app/worker isolation only if its own measurements justify it; the shared-instance configuration remains a risk, not the cause demonstrated by this chart.

At 1.5 GiB free, no rebuild of a multi-GiB table should be proposed without a measured temporary-space budget and provider confirmation. Writable state recovery did not remove the capacity risk. Obtain the provider breakdown before choosing in-place reclamation versus a compact replacement. Do not treat subtracting table estimates from dashboard usage as a complete disk audit.

Suggested outcome scenarios, assuming unchanged 27 GiB capacity: below 85% requires about 2.55 GiB less physical usage; below 80% requires about 3.90 GiB less. These are capacity targets, not achieved or promised savings. Account for growth and transaction logs during any reclamation.

1. Capture 24–72 hours including a representative multi-sport peak: app/service RSS, memory limits, OOM/restart events, worker job identity and peak RSS, scheduler children/concurrency, request latency/errors, queue depth/oldest age, Redis memory/evictions, database memory/connections/temp usage, disk bytes and daily growth.
2. Identify whether the reported memory issue belongs to web compute, live worker, bulk worker, database, or Redis. Correlate timestamps with individual jobs and deployments.
3. Use platform metrics and bounded instrumentation; avoid full-table payload scans or mass historical reruns on production.
4. Reconcile table allocations with total cluster disk, including provider-managed logs and temporary space. Produce a measured growth and savings estimate for each proposed change.

Exit: named memory-heavy workloads, peak concurrency and memory, and table growth rates. Exact compute sizes and savings remain provisional until this gate.

## Phase 2 — Isolate workloads and bound memory

### Additional read-only evidence — 18:38 Central

Production InnoDB table file lengths total **24.863 GB / 23.16 GiB**, rather than the approximately 17.84 GiB from table data/index estimates. Other application table files total about 0.18 GiB. Thus most dashboard usage is consistent with table file lengths; it is incorrect to call the earlier roughly 7.66 GiB difference unaccounted-for logs. File lengths still are not proof of physical blocks used: the reported `ALLOCATED_SIZE` total is much lower, so filesystem/provider accounting remains unresolved. The dashboard is the capacity authority until this discrepancy is explained.

Visible undo files total 64 MiB and the global temporary tablespace is 12 MiB; neither explains several GiB of usage at this observation. Session temporary files, binary logs, and other provider overhead are not fully accounted for. Reading `performance_schema.log_status` was denied; no permissions were changed. Evidence command: `comm-a2fdc10f-1944-4276-b2b2-0e285961567d`.

Instrumented memory is led by the InnoDB buffer pool at about 2.05 GiB, followed by a 64 MiB log buffer and smaller components. The roughly 2 GiB page cache is mostly occupied, which is expected for an active database and does not alone justify more RAM. Cumulative logical read requests versus physical reads imply roughly 99.89% cache hits over server uptime; this is a historical aggregate, not a current latency or working-set measurement. `Innodb_buffer_pool_wait_free=5574` is also cumulative and requires interval deltas before judging current pressure. No buffer-size changes are proposed from these values alone.

### First implementation packages, before historical cleanup

1. **Reduce injury-sync write amplification.** Prototype meaningful-change comparison and targeted activation/deactivation. Keep successful observation freshness separately from source-change timestamps so unchanged provider data does not become falsely stale. Preserve reliable-report gating, empty-report handling, removals, failed/partial fetch semantics, and historical cutoff behavior. Compare row writes/binlog growth before and after a bounded canary; this reduces future churn, not existing table file size.
2. **Make injury archives safe to read.** `NflMatchupPersonnel::outStatus()` currently checks `snapshot.raw_payload` count against entries to determine report completeness. Do not simply null raw report JSON. Define and test a durable verified completeness contract or an archive-aware reader, with payload/checksum validation and identical as-of decisions. Inventory other readers before choosing whether report-level or entry-level raw data is moved first.
3. **Run a non-destructive compression pilot.** Sample bounded, stratified records from older and recent injury, feature, event-input, and decision datasets; measure serialized and compressed bytes, read-back integrity, peak memory, and reader equivalence outside production. Keep production payloads untouched. Report sample limitations; sample compression ratios are not guaranteed physical disk savings.
4. **Choose reclamation only after pilot results.** Reconcile filesystem accounting with provider support, then compare one-table maintenance space requirements with available 1.5 GiB. If safe reclamation cannot fit, rehearse logical export/import to a separate compact cluster rather than enlarging the source by default. Temporary replacement resources and their cost require a concrete reviewed configuration before creation.

The first package has a local implementation described below; the remaining packages are proposals. A retention-only cleanup of small operational tables will not meet the approximately 3.9 GiB target by itself.

### Local implementation — injury current-state writes

`AbstractSyncPlayerInjuries` now reads and locks existing current-state rows for the team, deactivates only injuries absent from a reliable report, upserts only new or changed records, and updates only `updated_at` for unchanged observations. JSON object key order is normalized for comparison while list order and value types remain significant. Freshness updates remain writes; this does not eliminate all binary-log generation or change historical snapshot deduplication.

Validation: 37 tests / 359 assertions passed across `SyncPlayerInjuryWriteEfficiencyTest`, `SyncPlayerInjuriesTest`, `NflMatchupPersonnelTest`, and `SportInjuryEndpointContractTest`, first on SQLite and then on an isolated local MySQL 8.4.6 database (production observed version: 8.4.7-7). The MySQL run passed in 14.30 seconds. The disposable database was removed after validation; no production data was copied into it. The new tests cover timestamp-only unchanged writes, reordered JSON keys, changed status with retained historical snapshots, reliable versus unreliable missing reports, and removal without toggling retained injuries. Formatting and whitespace checks passed. Production query plans, concurrency under real workload, and measured binlog effects still require a bounded canary check. Shared API resources, route filters, frontend consumers, and scheduling were not changed. No migration is required.

This change is local only. It prevents unnecessary payload writes but does not reclaim existing disk. Next delivery gate: review the diff, verify a MySQL canary and freshness metrics, then consider deployment independently of archival or deletion.

### Rollout checklist for the local injury fix

- Existing Cloud inventory exposes production only; no staging environment was found. Local MySQL validation substitutes for database-engine compatibility tests, not a deployed staging replay.
- Review only the injury action and new efficiency tests; do not include unrelated existing workspace changes. The shared action affects NFL, NBA, MLB, and WNBA injury ingestion. No schedule or API change is intended.
- Before deployment, record current free space, database writable state, injury sync duration, errors, and observed/source timestamp behavior. Do not force a provider refresh for validation or replay a backlog.
- During a controlled rollout, observe the next ordinary scheduled injury updates. Check that changed and removed injuries appear correctly and unchanged observations remain fresh. Compare write volume over equivalent normal windows; real binlog savings depend on row-image configuration and workload, so narrower SQL alone does not prove a fixed byte reduction.
- Observe SQL latency, lock waits/deadlocks, and worker peak RSS: comparison adds a team-scoped read of existing current records and decoding of their payloads. It trades some read/CPU/memory work for fewer payload rewrites. Verify that tradeoff before broadening archival work.
- If correctness, freshness, latency, lock pressure, or memory regresses, revert this code change and restart affected workers through the normal deployment procedure. No schema or archived-data rollback is needed.
- Require a separate reviewed deployment that includes only intended changes. This investigation has neither deployed code nor changed worker placement, capacity, or retention.

### Bounded compression feasibility check

Read-only command `comm-a2fdc14d-f233-4900-91e7-8e1de5cb3440` sampled 50 records from each end of the ID ordering for four tables. Selected JSON columns were serialized and compressed per record in memory using gzip level 6; no archives or database changes were written. ID order is not guaranteed chronological for every identifier type. The serialization wraps database-returned JSON strings, so these figures are feasibility indicators rather than final canonical archive encoding benchmarks.

| Selected payloads | Reduction, low-ID sample | Reduction, high-ID sample |
| --- | ---: | ---: |
| Injury report raw JSON | 82.3% | 84.2% |
| Prediction features/outputs/model metadata | 68.9% | 85.0% |
| Event inputs | 56.7% | 89.0% |
| Bet-decision feature/market JSON | 68.7% | 92.9% |

Peak PHP allocation reported by this bounded process was about 58 MiB; this is not container RSS or a guarantee for a full export. The check supports a compressed-object-storage pilot, not enabling compressed MySQL rows or promising equivalent disk reclamation. It has not verified archive read-back, full-table distribution, semantic equivalence, or savings after filesystem reclamation. Next gate: stratify by sport, season, schema/model version and time, test canonical encoding and decompression checksums, and exercise the actual readers on a staging copy before changing writers.

### Decoded-JSON round-trip check — 18:39 Central

Command `comm-a2fdc1a8-9856-441b-9bc5-d52121ca5d92` repeated the bounded ID-endpoint samples with database JSON decoded into values before serialization. For each of 400 payload bundles, gzip decompression reproduced the serialized bytes exactly (SHA-256 match) and decoding reproduced the original PHP JSON value strictly. Changing a compressed byte produced a different compressed-object checksum in all 400 cases. This verifies checksum detection only; it does not test archive-reader failure handling, actual object-storage persistence, or prediction equivalence.

| Dataset | Payload bundles | Raw bytes | Gzip bytes | Reduction |
| --- | ---: | ---: | ---: | ---: |
| Injury reports | 100 | 5,249,136 | 1,010,229 | 80.8% |
| Prediction features/outputs/metadata | 100 | 4,236,993 | 707,527 | 83.3% |
| Event inputs | 100 | 14,400,210 | 1,817,414 | 87.4% |
| Decision feature/market payloads | 100 | 9,343,160 | 766,321 | 91.8% |

The process reported approximately 58 MiB peak PHP allocation. Records were not exported or changed. Encoding preserves numeric zero fractions and avoids escaped Unicode/slashes, but a complete canonicalization contract still needs definition; semantic hashes in existing records must not be recalculated or replaced by archive encoding hashes.

### Reader dependency map and proposed first change

| Surface | Current dependency | Required compatibility |
| --- | --- | --- |
| NFL personnel matching | `NflMatchupPersonnel::outStatus()` reads report JSON to validate completeness | Retain report JSON until verified archive hydration or a tested completeness contract exists |
| CFB frozen replay | `CfbFrozenSpreadEntryComparison` reads event inputs plus decision feature/market fields | Hydrate equivalent inputs and preserve quote IDs, content hash, cutoff and release identity |
| CFB decision performance | `CfbFrozenDecisionPerformance` reads decision feature metadata | Keep compact queried release/canonical IDs accessible without archive fan-out |
| MLB picks | Explanation/grading services read candidate feature fields, including prop IDs | Map candidate and decision relationships separately; do not assume their JSON is interchangeable |
| Prediction recording | Shared feature recorder writes full feature/metadata bundles | Preserve sport/model/schema provenance and lifecycle links across readers |

Recommended first code change is **injury current-state change detection**, before large archive migrations. Its reviewable scope: avoid deactivate/reactivate churn for unchanged active records; preserve partial-fetch reliability behavior, true removals, empty complete reports, observations, and source timestamps. Test changed and unchanged payloads plus missing/partial reports and an as-of personnel regression. Deploy a bounded canary only after local tests, then compare writes and freshness against baseline. This is prevention, not a promise to reclaim existing disk.

The first archive implementation should be additive: a versioned payload manifest and a resolver that supports both inline and verified compressed-object payloads, with bounded decompression and explicit failure on missing/corrupt objects. Introduce readers before switching writers; keep inline originals during the pilot. Add failure-path tests for corrupt checksum, truncated gzip, unsupported version, oversized decompression, unavailable object storage, and preservation of existing hashes/values. No production cleanup belongs in that change.

### Local archive foundation implemented

`CompressedJsonPayloadStorage` now provides explicit archive/read/resolve methods on the existing provider-data disk. It writes private gzip JSON objects under a versioned, hash-derived key; returns a manifest with encoded/compressed checksums and sizes; and verifies read-back before reporting success. Identical serialized content reuses a verified existing object. An existing corrupt object is rejected rather than silently overwritten. Inline arrays, including empty arrays, remain authoritative during compatibility rollout. Missing inline data without an archive reference fails explicitly.

Manifest validation rejects unsupported version/codec/disk, invalid hashes, unexpected object paths, and invalid size bounds. Compressed reads and decompression are bounded with default 8 MiB limits configured in `provider-data.payloads`. This is a per-payload API: callers must still batch source reads and avoid assembling oversized collections before passing them to the service. Archive encoding hashes are separate from existing semantic/provenance hashes; no model hashes are changed.

Validation: combined archive, injury efficiency, historical injury, personnel and injury API tests pass: **52 tests, 385 assertions**. Archive tests use fake local storage and cover exact value round trips, private visibility, reuse, inline fallback, absent/corrupt archives, refused writes, unsupported manifests, compressed/decoded size limits, and truncated gzip. Formatting and whitespace checks pass. Real S3 round-trip and application-reader equivalence remain unverified; no model, migration, production writer, or public API is connected to this foundation yet. Nothing was archived to production or removed from MySQL.

Next package: select a single dataset and introduce a durable nullable archive manifest plus archive-aware hydration under an opt-in compatibility path. Prove existing reader results on a staging copy, including missing/corrupt object behavior, before any writer drops inline JSON. Preserve immutable-model guards through an explicit reviewed migration design. This must be followed by measured physical reclamation; uploading archives alone will not free disk.

### NFL injury report reader integrated locally

The first dataset is report-level NFL injury snapshots. A nullable `payload_archive` JSON column retains the manifest; the model's explicit `reportPayload()` method prefers existing inline data (including empty arrays), preserves legacy null/missing reports, and verifies archives only when inline data is absent. `NflMatchupPersonnel` uses this method for its completeness check. No writer or archival command removes inline JSON, and snapshot UUIDs, observation/source times, entries, and existing provider hashes remain unchanged. Raw attribute serialization is not transparently replaced by an object download.

The migration requires `ALGORITHM=INSTANT` on MySQL for column addition/removal, failing rather than silently rebuilding a large table. Rollback refuses to drop the archive column while any snapshot relies solely on its manifest; restore inline payloads first. Instant DDL still needs metadata locks and a release window; local success does not prove production lock availability or provider support.

Validation: the 56-test suite passed on SQLite and local MySQL 8.4.6 (395 assertions before the added rollback-guard assertion). A subsequent targeted MySQL run passed all three snapshot-archive tests, including refusal of unsafe rollback (9 assertions). Instant down/up was separately rehearsed successfully on the empty isolated MySQL database, which was then removed. Personnel fixtures return identical full results before and after moving a complete report to fake storage; corrupt archives fail explicitly. No actual source payloads were moved, no production migration was run, and no real S3 round-trip is claimed.

Next gate: review and prepare a bounded opt-in archive command with dry-run, checkpointing, verified persistence, and inline retention by default. Its eventual cleanup mode must be separately authorized and must validate references before clearing inline JSON. Do not deploy the migration or command as an automatic historical backfill.

### Bounded injury archive command implemented locally

`nfl:archive-injury-snapshot-payloads` defaults to a read-only preview. It requires an inclusive `--through-id` boundary, supports `--after-id` for resumption, and limits each invocation to 1–100 reports (default 50). Only `--apply` writes objects and manifests; `--apply --dry-run` is rejected. There is **no inline cleanup option**.

The command selects bounded IDs and payload byte lengths before loading one report at a time. Oversized inputs are rejected before upload. Each archive is verified against inline data; a short transaction then locks and rechecks the raw payload, provider hash, and current manifest before persisting the manifest. Network upload/read-back happens outside that transaction. Source changes during verification stop the batch instead of attaching stale references. Existing manifests are verified rather than blindly skipped or overwritten. Persistence does not change snapshot timestamps, provider hash, identity, entries, or inline JSON.

The JSON summary includes `last_successful_id` for apply-mode resumption and a separate `last_previewed_id` for preview. Preview does not advance the apply checkpoint. On failure, the apply checkpoint covers only previously verified and persisted records; retry from that value to include the failed record. An interrupted upload can leave an unreferenced object, but deterministic keys allow reuse on retry; the command does not delete such objects. The checkpoint is emitted to command output, not stored as a new database job record. Operators must retain it and keep the same reviewed upper boundary across invocations.

Usage after reviewed deployment and migration, with actual boundaries substituted:

```text
php artisan nfl:archive-injury-snapshot-payloads --through-id=<reviewed-upper-id> --after-id=0 --limit=10 --dry-run
php artisan nfl:archive-injury-snapshot-payloads --through-id=<same-upper-id> --after-id=0 --limit=10 --apply
php artisan nfl:archive-injury-snapshot-payloads --through-id=<same-upper-id> --after-id=<last-successful-id> --limit=10 --apply
```

Preview is safe to repeat and never uploads archives. Apply retains both copies and can temporarily increase storage and write logs; do not run a large production batch at 94% disk use. No new schedule is registered and no paid provider calls are made. Test fixtures use fake object storage; real managed object-storage compatibility is a separate gate.

Validation: SQLite combined suite passed **67 tests / 445 assertions**. Local MySQL combined run passed 66 tests and exposed one test-only JSON key-order assumption; the assertion was corrected to compare stored pre/post values and the command suite then passed **11 tests / 48 assertions** on MySQL. The disposable database was removed afterward. Tests cover default preview, ID/limit bounds, idempotent repeats, resume checkpoints, partial failure, provenance preservation, oversized input, mismatched archives, and concurrent source changes. No production command, upload, migration, deletion, or deployment has been performed.

### Release review and managed-storage transport pilot

Read-only production command `comm-a2fdef3f-e062-4d33-86f4-c2f1369623cd` confirms the archive class and manifest column are not deployed, provider storage resolves to private S3-compatible disk `s3` with prefix `providers`, the database is writable, and **`binlog_row_image=FULL`**. Therefore a timestamp-only update still can emit the full row into binary logs. The injury optimization removes redundant deactivate/reactivate cycles and narrows application SQL, but cannot eliminate full-row log payloads while freshness shares that row. Do not reduce binlog protection settings merely to improve these measurements. A later separate observation/freshness table can avoid touching large payload rows, but requires reader and freshness-contract changes before adoption.

Managed-storage transport pilot `comm-a2fdef6b-f330-4ba1-8af7-f1369fa2b222` wrote one **synthetic**, private 84-byte gzip object (68 JSON bytes), read it back, matched compressed SHA-256 and decompressed bytes, removed it, and confirmed absence. No sports data or database records were written. This validates the live transport's small private put/read/delete path, not the undeployed archive class, full-size performance, access isolation, or a historical migration. The earlier no-production-upload statements refer to preceding stages; this is the sole deliberate production-storage write so far, and its disposable object is gone.

Release review fixed a manifest durability issue locally: the write prefix is now stored in each manifest, so changing the configured prefix does not break existing archive references. Prefixes and object keys remain validated, preventing traversal. Archive disk changes still require an explicit supported migration/configuration decision; they are not silently redirected to a different disk.

Release packaging must exclude pre-existing changes in `AGENTS.md`, `NflTeamMetricsBoard.vue`, frontend metrics tests, and unrelated outputs/docs. Current branch is `codex/cfb-health-freshness`; do not treat its entire branch diff as a storage-only release. Prepare a separately reviewed change set before deployment. No push, production deployment, migration, capacity change, or actual report archival is authorized by the transport verification alone.

1. Move the live `default` worker off the 512 MB web instance to a dedicated service. Retain the existing separate bulk worker. Size each against measured peak RSS plus runtime overhead and headroom; scale the app temporarily if necessary during recovery.
2. Keep only a lightweight scheduler dispatcher on the app. Move substantial scheduled calculations/imports to explicit workers or a dedicated scheduler service with bounded concurrency. Preserve season guards and ingest → metrics → prediction → grading dependencies.
3. Keep a single active scheduler with distributed locks. Add workload-level concurrency limits across different heavy jobs, not just same-command overlap locks. Start heavy-job concurrency at one until measurements support more.
4. Replace unbounded collection loads with selected columns and bounded keyset batches/streaming. Release per-game graphs and process one game at a time where appropriate. Bound archive upload/download buffering, exports, and training inputs too.
5. Set per-job/process memory and time budgets; recycle workers between jobs. Keep queue retry leases greater than effective job timeouts, verify locks cover runtime, and prevent overlapping retries. Raising PHP memory limits alone is not a fix.
6. Introduce changes by workload, with tests for queue routing, dependencies, retries, and idempotency. Update `SCHEDULER_REFERENCE.md` and the Cloud worker runbook when behavior changes.

Exit: no OOM kills or unexplained restarts through a representative peak; proposed sustained memory target below 70%, peak below 85%; live oldest-job age below five minutes and bulk below thirty minutes without starving live work. Tune targets from baseline.

## Phase 3 — Stop unnecessary storage growth

1. Build a dependency/reader map for each large JSON column before changing its representation. Include API resources, replay, grading, training, research revisions, and audit consumers. Activate the repository's sports-contract-reviewer workflow for these implementation changes.
2. Preserve one immutable payload per canonical content hash plus schema/version. Separate observation/run records and their timestamps from reusable content. Do not collapse distinct observations, decision outcomes, or provenance merely because payload bytes match.
3. Start with injury report/entry duplication, then feature snapshots and embedded bet-decision features. Preserve identity and references; verify which embedded data differs before replacing it with a reference.
4. Make unchanged recording idempotent where semantics allow. Hash canonical meaningful content; exclude volatile capture timestamps from content identity while retaining those timestamps on observation records. Add concurrency-safe uniqueness for the full semantic key, not just a hash that omits model metadata/version.
5. Keep frequently queried values and compact summaries in MySQL. Store bulky immutable raw inputs, detailed diagnostics, and research bodies in private compressed object storage with checksum, schema version, byte count, codec, and object reference. Extend existing archival support rather than introducing a second storage system.
6. Add archive-aware readers before changing writers. Retain bounded caching and avoid making list pages fetch one archive object per row. Object-storage failure must not silently turn historical evidence into missing data.

Exit: equivalent predictions/decisions and historical replay, preserved provenance, verified archive reads, and reduced bytes per new observation/run under a representative workload.

## Phase 4 — Migrate history and reclaim space safely

1. Verify recoverable backup and restoration procedures. Use a staging clone for migration and reclamation rehearsals, including memory and temporary disk consumption.
2. Export eligible payloads in resumable, rate-limited batches. Stream compression and upload. Maintain manifests with source record IDs, checksums, counts, versions, and migration status.
3. Read archives back and verify checksums, counts, referential integrity, replay, API behavior, and representative calculations. Keep a reversible compatibility period with old readers/writers supported as needed.
4. Only after those gates, propose the exact redundant database payloads/rows to remove, measured savings, retention policy, and restoration path. Obtain approval before irreversible historical removal. Do not bypass immutable-model protections with ad hoc SQL.
5. Assess older play-by-play by actual query usage: retain active calculation windows and required historical training/replay inputs in an accessible form. Consider season-partitioned compressed Parquet archives with compatible analytical readers; do not move needed data until reader equivalence is demonstrated.
6. Reclaim physical disk only with measured headroom and a tested maintenance method. Deleting rows may leave reusable table space without reducing disk files. Rebuild selected tables only after provider compatibility, disk overhead, and lock impact are established.

Exit: restoration drill passes, application and replay checks pass, physical disk reduction measured, and no critical historical feature loses coverage. Do not promise a percentage reduction before the pilot.

## Phase 5 — Retention and prevention

- Establish approved policies separately for operational logs, raw provider sources, detailed diagnostics, immutable decision evidence, and analytical datasets. Existing heartbeat retention is 90 days; cache/session/failed-job tables are not the primary storage problem.
- Preserve canonical predictions, decisions, settlements, source availability times, hashes, and artifact lineage. Archive valuable history instead of treating all old rows as disposable.
- Alert on disk at 70/80/90%, projected time to full, abnormal table growth, memory pressure, OOM/restarts, queue age, stale ingestion, and Redis eviction. Route alerts through existing approved channels; configuring new delivery is separate work.
- Require storage/memory budgets for new snapshot or bulk-import features. Track per-run bytes, duplicate-content rate, archive-read failures, and bytes per game/season.
- Review capacity and growth after peak days and after seven days of normal operation. Keep room for maintenance and recovery, not merely steady-state traffic.

## Delivery order and review gates

1. Verify recovered write health and actual disk headroom without resizing; choose the recovery path using the decision table above.
2. Memory attribution and dedicated live-worker isolation.
3. One small archive/deduplication pilot with archive-aware readers and output-equivalence tests.
4. Remaining writers, then verified historical migration.
5. Approved cleanup and physical reclamation.
6. Retention enforcement, alerting, and a representative peak-load acceptance report.

Assign an infrastructure owner for phases 0–2, an application/data owner for phases 2–4, and a reviewer for evidence retention and acceptance. These are proposed roles, not assignments to people. Changes should be small and independently reversible; no broad production migration should precede the pilot.

## Rollback and acceptance

Before removing database payloads, rollback uses the original reader path and retained payloads. After removal, restoration uses verified manifests and archived objects; rehearse that path before approval. Stop migration on checksum mismatches, missing references, replay differences, excessive queue age, disk pressure, or memory regressions. Do not roll back capacity to an unsafe size.

Final acceptance requires healthy authenticated requests, writable database, bounded disk growth, adequate headroom, no OOMs through peak traffic, timely queues, and unchanged prediction/decision/replay results for a representative cross-sport sample.

Reference: MySQL documents additional temporary-space requirements for online schema changes at https://dev.mysql.com/doc/refman/8.0/en/innodb-online-ddl-space-requirements.html.


## Production rollout and reclamation follow-up — October 15, 2026

PR #1 was merged and deployed successfully as commit `72205ea29fcd3b71190bdca77757d2f4ec1c401f`, Cloud deployment `depl-a2fe0483-9b7c-4dd9-9afc-6c71312d555c`. Full PHP 8.4 and 8.5 runs each passed 3,553 tests / 30,215 assertions; API contract, frontend, quality and both ML jobs passed. Production archive support and the instant manifest migration are present; the database is writable. The authenticated dashboard loaded successfully after deployment. This deploy reduces injury-current-state write churn; it has not reclaimed historical disk.

The Cloud dashboard now reports 25.9/27 GiB used, about 1.1 GiB free. Production snapshot upper ID was 20,767. The deployed preview selected ten reports totaling 825,972 inline JSON bytes; no upload occurred. Automatic approval review blocked the ten-report apply because the production-payload transfer requires specific user approval. Approval was requested for private PickSports object storage, verified archival, and clearing only duplicate inline payloads. Do not retry that transfer until approval arrives.

A temporary session-only MINIMAL binlog-mode capability probe was denied by existing database permissions; no session/global setting or privilege was changed. FULL row logging remains in effect. Do not grant privileges or bypass this restriction. Archive-manifest updates and duplicate release both generate logs, so batch byte limits are only one control: check dashboard free space between batches and stop on pressure. Do not attempt a multi-GiB rebuild with 1.1 GiB free.

The follow-up `nfl:manage-injury-snapshot-inline-payloads` command supports explicit `--mode=release` and `--mode=restore`, requires an inclusive ID boundary, verifies archives before any change, defaults to verification-only, and requires `--apply` for writes. Default bounds are ten records and 5 MiB of source JSON, with a 32 MiB hard batch maximum. Failed rows are not advanced past; source/manifest/hash changes stop the batch. No object operation occurs under the row lock. Release keeps the archive and all other attributes; restore rehydrates the same JSON values. It never deletes rows or archive objects and registers no schedule. Physical file reclamation is separate from releasing inline values.

Focused archive, release/restore, and personnel-reader suites passed on SQLite and disposable MySQL 8.4.6: 61 tests / 185 assertions on each. The disposable MySQL database was removed. Production release/restore has not run.
