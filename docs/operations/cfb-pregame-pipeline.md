# CFB pregame pipeline

Scope: operator reference for the current checkout, verified against command signatures and configuration on November 17, 2026. Production deployment, active release, and enabled gates must be checked in the active environment; see [production access](production-access.md).

## Preconditions and side effects

The pipeline repairs stored source data, refreshes metrics/market/personnel inputs, calls providers, and publishes forecasts. It is an explicitly authorized maintenance operation, not a step for answering an analysis question. Use a verified season/week/game scope and durable configured `CFB_SOURCE_DISK` and `CFB_MODEL_DISK` disks. Inspect the selected approved release and existing artifacts; this guide does not authorize activation or promotion.

## Operating flow


The existing scheduled pregame command now checks and repairs relevant completed games, calculates EPA/drives, refreshes Elo and metrics, refreshes prices/weather, and generates each upcoming game independently. It records a forecast as complete only when a fresh published prediction exists. Results retain source, artifact and prediction identifiers.

Drive normalizer v5 and EPA reconstruction use the hash-verified accepted ESPN summary scoring ledger when available. Every scoring play must match the accepted play order, the scoring team, and both final scores. Non-scoring cumulative snapshots can contain premature points or pre-conversion rollbacks; they are not scoring events. Reconstruction operates on copies, preserving archived raw plays. Missing or inconsistent ledger evidence remains blocked, and legacy archives without a ledger retain the original score checks. Touchdown descriptions containing a conversion remain scrimmage plays. Changes to the accepted boxscore ledger or normalizer version invalidate normalized stage reuse.

A missing odds feed does not stop score forecasts. Indispensable shared membership/rating/metric failures still stop the run; unreliable team personnel or incomplete historical boxscores block affected games with a recorded reason. Release 1.7.1 separates current core forecasts from optional play reconstruction: each historical rebuild runs in a bounded worker, incomplete play history excludes local EPA metrics and the possession challenger, and the published input-quality flags prevent betting qualification. Older releases still require complete play history. These failures remain visible and are not relabeled as healthy. The ledger covers repair, normalization and forecast stages; shared refreshes retain existing cache/command controls. It is not yet a fully queued stage graph with worker heartbeats.

`cfb:audit-forecast-freshness --season=2026 --week=6` checks the entire FBS/unknown-membership weekly board, not a sample. It fails if any upcoming forecast is missing, older than six hours, unsafe, or captured before the current team metrics. Games already started retain their original pregame publications as archives; they must never be regenerated retroactively.

For urgent whole-week recovery, `cfb:run-pregame-pipeline --season=2026 --week=6 --days-forward=8 --core-only` skips optional play reconstruction while preserving required source repair, current ratings/metrics/personnel, isolated publication, and the approved release's quality exclusions. This does not certify missing play evidence as complete. The normal pipeline continues attempting normalization, and subsequent refreshed forecasts use validated play evidence when available.

The weekly orchestrator runs shared refresh commands in separate processes. Team metrics refresh only the required teams in batches of at most ten, avoiding a full-season temporary graph alongside the resident weekly job. Any failed batch stops publication; each game's forecast follows all required batches.

Scheduled pregame refreshes use `--queued` and run on the existing `sync` bulk worker rather than the web/app worker. The season-unique queued job records its actual completion or partial/failure outcome in `cfb:pregame:worker-status`, exposed by `cfb:pipeline-status`. Dispatch alone does not create a generation heartbeat. Urgent queued recovery adds `--queued` to the scoped `--core-only` command above.

When ESPN team-yard totals conflict with its passing/rushing categories, `cfb:sync-cfbd-team-boxscore --game=ID` can import a separate CFBD team-stat revision. Acceptance requires the exact final event, both mapped team identities and scores, complete required categories, valid conversion counts, and independently reconciled yards. The raw CFBD payload and imported attributes are archived with CFBD provenance. Only the core team-box requirement can use this evidence; ESPN player boxes and plays retain their original quarantine, so play-derived inputs and betting qualification remain held. `--games=ID,ID` scopes queued recovery to affected forecasts without touching other teams' metrics.

The existing hourly refresh cadence remains. The proposed ten-minute freshness objective has not been measured or claimed. Live scoring remains on its existing schedule. Offline training is deliberately not placed on the 512 MB application worker.

## Inspect and recover

Check command options before execution:

```bash
php artisan cfb:run-pregame-pipeline --help
php artisan cfb:pipeline-status --limit=50
php artisan cfb:audit-forecast-freshness --help
```

For an authorized recovery, replace the example season/week with the target scope. Review the dry run before queueing work:

```bash
php artisan cfb:run-pregame-pipeline --season=2026 --week=6 --days-forward=8 --dry-run
php artisan cfb:run-pregame-pipeline --season=2026 --week=6 --days-forward=8 --resume --queued
php artisan cfb:audit-forecast-freshness --season=2026 --week=6
```

Use `--game=ID` or `--games=ID,ID` to restrict recovery. `--core-only` skips optional play reconstruction while retaining the selected release's required quality checks; it does not establish full play coverage or betting eligibility. Stop on source identity/score mismatch, incomplete required history, failed metric batches, or unsafe publication. Inspect the recorded stage and worker outcome rather than assuming successful dispatch means successful generation.

## Immutable forecast record

Canonical event → immutable input snapshot → approved calculation release → successful calculation run → published prediction revision → final result revision → evaluation revision. Confirm the active read source before reporting performance. Legacy compatibility imports are not native verified calculation runs. A changed pregame input produces a new revision; never regenerate an original pregame forecast after kickoff to repair a historical score.

For canonical activation, use the existing cutover readiness command with the target season/week and review its full result before changing read/write flags. Generation, evaluation, and release registration mutate records and require their own authorized scope. Forecast promotion is separate from priced-market betting approval.

## Source repair and rollback

Keep original source archives and hashes. Official corrections must match exact event/team/score identity and reconcile reported categories. An accepted correction does not erase quarantined provider evidence; changed source evidence invalidates dependent normalization and forecasts. Missing values remain unavailable.

On failure, stop the affected publication/recovery scope, retain the last valid immutable revision, and inspect recorded errors. Roll back the code/configuration through the hosting workflow if required; do not delete published predictions, snapshots, decisions, or settlements. Recheck freshness, quality flags, and public API source metadata after recovery.

## Synchronous signal training memory

`cfb:train-football-signals` temporarily raises a smaller finite CLI memory limit to 512 MB for synchronous historical reconstruction. It preserves a larger or unlimited caller setting and restores the original limit when training succeeds or throws. This does not apply to the queued/cached-artifact fast paths. Use an explicit PHP CLI limit for larger offline work; do not rely on this command to configure persistent worker memory.

## Current source assessments and bounded scoring exports

Current readiness requires an observed assessment receipt for the current game identity/status/scores and a matching complete, applied projection. A non-null historical `accepted_at` alone is insufficient. Raw source bytes retain their hash identity; changed validation or context appends a new assessment, including A/B/A observations in the same second. Old accepted rows without observed context remain unknown and require explicitly scoped reassessment; do not manufacture an earlier availability time or run an automatic historical backfill. Official gamebook repairs additionally retain exact primary-source/configured-reference/stat fingerprints. CFBD team-only acceptance does not certify ESPN players or plays.

Drive projections now retain play and boxscore assessment receipt IDs. Receipt/context drift is checked under the game lock before replacing drives or writing EPA. Existing normalized records without those receipts must be rebuilt through an authorized bounded operation before scoring export; merely retaining the same raw source hash is insufficient.

`cfb:export-scoring-training` scans at most 100 games by default, accepts `--max-games=1..1000`, and processes metadata in chunks of at most 50 games. Zero/unbounded scope is rejected. Use `--after-game-id` and the output manifest's last scanned ID for explicit resumable shards. The manifest reports `complete`, `limit_hit`, `has_more`, `max_games`, and `chunk_size`; truncated or resumed shards return exit 2 and are not complete training datasets. Both Laravel and direct Python training refuse an explicitly incomplete scan manifest. Combining shards requires a separately reviewed complete manifest, identity/duplicate/availability verification, and aggregate resource scope; no implicit assembler was added.

New export rows retain exact source assessment receipts/context hashes. Training carries this evidence unchanged into evaluation and refit artifacts. Live challenger admission rejects missing provenance or changed source/context/receipt evidence before simulation. This does not promote a model or alter calibration/approval policy: an unavailable/stale optional challenger preserves the released baseline. Old artifacts lacking receipt provenance cannot be certified by an accepted timestamp alone. Full archive authenticity, model validation and prospective performance remain separate gates.

Challenger training proofs also retain a separate game-feature hash for date/time, season/type/week, neutral/venue facts and linked canonical kickoff. These fields affect cutoff/features but are broader than source-assessment identity/status/score context. A changed hash or an old artifact without it prevents live challenger admission; this narrow guard is not full equivalence across all model inputs.

## Export publication and immutable retry

A nonempty scoring export is registered only after artifact writes succeed or existing exact bytes are verified. Data keys use the data hash; new manifest keys additionally include their own SHA-256, so different manifest bytes do not overwrite a shared manifest. Exact matching legacy manifest keys remain readable/retry-compatible. The persisted registry winner must match the current publication before success is reported; a competing different winner fails without deleting either export's objects.

Empty scans retain their local manifest and exit 2 without remote dataset/registry publication. On partial publication failure, local immutable output and already written shared objects remain available for inspection; do not blindly delete content-addressed objects. Retry only with a compatible existing registry/manifest identity. Existing-object hash reads consume at most the expected local byte length plus one, require exact size/hash and close resources. Adapter transport buffering/timeouts and atomic physical visibility remain separate contracts; these checks do not claim universal object-store atomicity. See [the scoped publication evidence](../contracts/cfb-export-publication.md).


## Scoring artifact resource admission

The reviewed candidate scoring-artifact reader uses a bounded application stream read and refuses JSON above 16 MiB, one million lexical tokens or the decoder's depth-64 setting. Integrity is verified on the same admitted bytes before structural inspection/decode. Source availability must remain a real explicit-offset instant for both recorded-time and research loads; research permission does not manufacture a source clock. These are admission ceilings, not model parameters or permission to promote a challenger. Oversized or malformed artifacts require producer diagnosis and reviewed regeneration or a separately validated limit change; do not truncate, relabel or bypass admission.

These limits were verified with synthetic boundary/cleanup controls in a separate candidate overlay. They do not certify existing production artifact sizes, storage-adapter buffering, total process RSS or worker capacity. Local trainer manifest/output reads remain a separate resource-review gate. The change is not deployed by this documentation, and the earlier frozen whole-suite checkpoint excludes this later overlay.


Artifact creation cutoffs use the existing application-wall timestamp carrier before SQL comparison. UTC/offset request instants must not be compared as raw UTC wall strings against legacy stored creation times. An unrepresentable legacy cutoff is refused; this does not add UTC6 precision or certify database session configuration. Synthetic exact-row controls distinguish a model created after the requested instant from one already available.


### Offline trainer admission — November 27 private candidate

The training command admits at most 2 MiB of manifest JSON, 100,000 lexical tokens and depth 32. It stages the exact admitted manifest with a SHA-256 receipt, removes unrelated inherited credentials from the child environment, and rechecks the staged bytes after successful child exit. Artifact admission uses the reader's 16 MiB / 1,000,000-token / depth-64 ceilings and requires a matching dataset hash plus holdout and promotion-report arrays before archival. Staged manifests are removed on success and failure. Registration remains challenger-only with independent validation and prospective shadow required.

The source-bound scoped verification passed 56 tests / 189 assertions using a fake child. This does not validate a scientific fit, bound total stdout/stderr capture or operating-system memory, sandbox a same-UID child, or close archive/database atomicity and evaluation-sidecar retention. Python's existing preexisting-output refusal and exclusive evaluation-sidecar creation remain intact; retain the sidecar referenced by each real artifact. Production source-shape, dependency and fit acceptance remain release gates.


## Stage receipt generations — private candidate, November 28

A stage keeps the existing input-hash/version identity, 600-second cache lock, completed-result resume and failure cooldown. Each actual work reservation now advances the persisted unsigned-integer generation and pins the literal receipt ID. Terminal publication compares that exact ID, key, generation and running state. A superseded worker returns `busy` with `receipt_generation_superseded`; it cannot report completion or replace the newer receipt. Cache expiry alone, without a newer SQL reservation, does not refuse completion.

Inspect the newer receipt and the actual publication/source evidence before explicitly retrying. The receipt fence does not roll back a callback's provider requests, forecasts, external side effects or other outputs, and it does not authorize a repeat operation. No model settings, approval rules, stage version or provider budgets change.

Existing caller-owned transactions remain supported without committing the caller's transaction. Their nested reservation is not durable before work; this slice does not certify inherited lock ordering or cross-process publication atomicity. Simultaneous first-row reservations can fail on a uniqueness/deadlock conflict before work; this is not a deadlock-free cache-plus-SQL ownership protocol. The full existing generation domain is retained through 4,294,967,295. Exhaustion returns a noncomplete `busy` receipt result before work; do not delete history or reset counters to conceal it.

See the source-bound before proof (private verification record retained separately) and the final scoped receipt report for executed controls and their exact source epochs. Production deployment and arbitrary callback-output correctness remain separate gates.
