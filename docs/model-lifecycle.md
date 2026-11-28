# Trusted Model Lifecycle

This workflow keeps model research, promotion, betting decisions, and public
recommendations separate. A trained artifact cannot affect a public prediction
unless it has passed chronological evaluation and has been explicitly promoted.

## Provenance Contract

Every training row must have:

- a final game and stable target hash
- a prediction feature snapshot
- a model run with configuration and code hashes
- `features_available_at <= game_start_at`
- either observed pregame timing or verified historical reconstruction
- a binary result for win-probability calibration

Every registered model has:

- a training run UUID
- configuration, code, and dataset hashes
- an immutable artifact path and SHA-256 hash
- an evaluation report path and SHA-256 hash
- an explicit challenger, eligible, promoted, retired, or invalidated status

Use `sports:report-model-lineage {artifact}` to print the full chain.
Admins can review the same lineage and live feedback at
`/admin/nfl-model-monitoring` and `/admin/mlb-model-monitoring`.

## Immutable Data And Deployment Manifests

Raw provider inputs and derived datasets live in private object storage. A
provider source-file record identifies the provider and dataset, content hash,
byte size, content type, compression, and immutable object key. Its import
manifest records timing, options, status, imported/skipped/error row counts,
and the source-file hash. Importing the same content again reuses the archived
source object instead of silently replacing it.

Large historical play partitions can be archived without loading the full
result into memory:

```bash
php artisan ml:export-historical-plays mlb 2025
php artisan ml:export-historical-plays nfl 2025 --format=jsonl --disk=ml-s3
php artisan ml:export-historical-plays nba 2025 --format=parquet
```

JSONL is the portable default. Parquet is produced only by a configured Python
runtime with PyArrow; requesting it without that dependency fails rather than
writing JSON data with a `.parquet` suffix. Each export manifest captures the
sport/season partition, actual encoding, row count, schema and schema hash,
data and manifest SHA-256 hashes, source table and maximum included source ID,
and private object URI. Object keys are content addressed. Repeated exports are
idempotent, and a hash mismatch is treated as immutable-object corruption.
Export never deletes or mutates source MySQL rows.

### Historical play coverage versus current integrity — November 28 private source clarification

The finalized-data completeness check may count an MLB game's archived plays from the immutable SQL export record's writer-attested `verified_game_play_counts`, aggregate agreement with its recorded row count, and current object/sidecar existence plus matching object byte size. Those counts describe the rows observed by the export writer. The writer verifies data and manifest hashes when storing or reusing its content-addressed objects; the routine completeness reader does not stream/hash the current Parquet body or read/hash the sidecar on every health check.

This is historical export coverage with a current existence/size check, not a fresh current-byte integrity, restore, or replay-readiness certificate. Same-size object corruption or sidecar changes can remain invisible to this narrow reader. Separate authorized readback/checksum and restore acceptance remains an open operational gate; the routine report must not be cited as having performed it. No automatic source-row deletion, integrity scan, re-export, provider call or model operation was added by this clarification.


Derived feature datasets should add a feature manifest containing feature
names, types, nullability, timing semantics, availability cutoff, and the code
and configuration hashes. Every snapshot and trained artifact must reference
that exact feature-manifest hash. Artifact inventories similarly record the
model run, dataset and feature hashes plus every file's path, content type,
size, and SHA-256 hash.

Evaluation manifests use chronological windows and preserve train/test start
and end timestamps, row and game counts, metrics, and baseline deltas. A
deployment record separately identifies the artifact, environment and cohort,
activation and retirement timestamps, actor and reason, rollout or shadow
configuration, and rollback target. Model promotion is an eligibility decision;
it is not public recommendation publication.

## NBA Workflow

```bash
php artisan sports:backfill-snapshot-provenance --sport=nba
php artisan nba:report-training-readiness --minimum-rows=100
php artisan nba:export-training-data --path=storage/app/ml/nba_trusted_training_data.csv
php artisan nba:split-snapshot-dataset \
  --input=storage/app/ml/nba_trusted_training_data.csv \
  --output-dir=storage/app/ml/nba-trusted-splits
php artisan nba:train-win-probability-calibration-model \
  --input-dir=storage/app/ml/nba-trusted-splits
php artisan nba:evaluate-win-probability-calibration-rolling \
  --input=storage/app/ml/nba_trusted_training_data.csv \
  --artifact-id=<artifact-uuid> \
  --min-train-size=90 \
  --test-window-size=30 \
  --step-size=30
php artisan sports:evaluate-model-promotion <artifact-uuid>
```

The final promotion command is an evaluation only. Add `--promote` only after
reviewing the report. A promoted calibration artifact changes live output only
when `NBA_WIN_PROBABILITY_CALIBRATION_ENABLED` and
`NBA_WIN_PROBABILITY_CALIBRATION_APPLY_TO_LIVE_OUTPUT` are both enabled.

Without promotion, the artifact remains a shadow challenger. Baseline and
challenger probabilities are stored side by side.

## NFL Workflow

Historical profiles are resumable and distinguished in snapshot lineage:

The NFL and WNBA historical prediction commands reject `--regrade` before generation or database work. Clearing grades would discard correction provenance. Use `sports:repair-legacy-prediction-grades <sport> --game=<reviewed-id>` for a local dry-run selection, then the same explicit scope with `--apply` for audited repair (maximum 100 game IDs). This repair does not regenerate forecasts or call providers. Review reconstruction scope separately from grade repair; the examples below generate historical predictions and snapshots.

```bash
php artisan nfl:backfill-historical-predictions \
  --from-season=2009 \
  --to-season=2025 \
  --season-type=2 \
  --profile=elo-only \
  --only-missing-profile

php artisan nfl:backfill-historical-predictions \
  --from-season=2009 \
  --to-season=2025 \
  --season-type=2 \
  --profile=full-historical \
  --only-missing-profile

php artisan nfl:export-training-data \
  --profile=elo-only \
  --path=storage/app/ml/nfl_elo_only_training_data.csv

php artisan nfl:export-training-data \
  --profile=full-historical \
  --from-season=2017 \
  --to-season=2025 \
  --feature-version=nfl-pregame-ml-v3 \
  --path=storage/app/ml/nfl_full_historical_training_data_v3.csv

php artisan nfl:compare-historical-profiles

php artisan nfl:register-historical-profile-artifact

php artisan sports:evaluate-model-promotion <profile-artifact-uuid> \
  --report=storage/app/ml/reports/nfl_historical_profile_comparison.json \
  --promote

php artisan nfl:split-snapshot-dataset \
  --input=storage/app/ml/nfl_full_historical_training_data.csv \
  --output-dir=storage/app/ml/nfl-full-historical-splits

php artisan nfl:train-win-probability-calibration-model \
  --input-dir=storage/app/ml/nfl-full-historical-splits

php artisan nfl:evaluate-win-probability-calibration-rolling \
  --input=storage/app/ml/nfl_full_historical_training_data.csv \
  --artifact-id=<artifact-uuid> \
  --min-train-size=1000

php artisan sports:evaluate-model-promotion <artifact-uuid>
```

The real tabular training and DigitalOcean runtime are documented in
`docs/nfl-ml-digitalocean.md`. A completed Python run is registered as one
immutable bundle:

```bash
php artisan nfl:register-tabular-model-run /artifacts/<model-run-id> \
  --dataset=storage/app/ml/nfl_full_historical_training_data.csv
```

NFL rolling evaluation uses complete held-out seasons by default. The optional
`--row-windows` flag restores fixed-size expanding windows for research.

The registered full-history profile always runs as a shadow challenger for
future games. Promotion makes its outputs eligible for tracking decisions but
does not replace the public NFL prediction. Each pregame snapshot stores both
sets of spread, total, and win-probability outputs with `active_source` fixed to
`baseline`.

```bash
php artisan nfl:generate-predictions \
  --season=2026 \
  --from-date=2026-08-06 \
  --to-date=2026-08-06
php artisan sports:record-shadow-bet-decisions \
  --sport=nfl \
  --artifact=<profile-artifact-uuid>
php artisan sports:settle-bet-decisions --sport=nfl
php artisan sports:report-model-feedback <profile-artifact-uuid>
```

## MLB Workflow

MLB uses game-week chronological windows so the current season can supply
training and live-shadow evidence without waiting for several complete seasons.

```bash
php artisan sports:backfill-market-quotes --sport=mlb --season=2026 --dry-run
php artisan sports:backfill-market-quotes --sport=mlb --season=2026
php artisan mlb:backfill-historical-predictions \
  --season=2025 \
  --profile=trusted-core-v1 \
  --only-missing-profile
php artisan mlb:export-training-data \
  --season=2025 \
  --season=2026 \
  --path=storage/app/ml/mlb_trusted_training_data.csv

cd ml/mlb
python3.11 -m venv .venv
source .venv/bin/activate
pip install --requirement requirements.lock.txt
pip install --no-deps --editable .
picksports-mlb-ml validate \
  --input ../../storage/app/ml/mlb_trusted_training_data.csv
picksports-mlb-ml evaluate-rolling \
  --input ../../storage/app/ml/mlb_trusted_training_data.csv \
  --output ../../storage/app/ml/mlb_rolling_evaluation.json
picksports-mlb-ml train \
  --input ../../storage/app/ml/mlb_trusted_training_data.csv \
  --output-dir /artifacts
```

Register the completed immutable run, then leave it in private shadow mode:

```bash
php artisan mlb:register-tabular-model-run /artifacts/<model-run-id> \
  --dataset=storage/app/ml/mlb_trusted_training_data.csv
php artisan mlb:run-tabular-shadow --artifact=<artifact-uuid>
php artisan sports:evaluate-model-promotion <artifact-uuid>
```

`MLB_ML_SHADOW_ENABLED=true` enables scheduled inference. Leave
`MLB_ML_SHADOW_ARTIFACT_ID` empty when `MLB_ML_SHADOW_AUTO_SELECT=true` so the
database-backed shadow cohort can advance automatically. The initial pass runs
after the morning baseline.
Additional passes run after each in-season odds cycle so the model probability,
exact market line, quote, and decision share a reproducible pregame horizon.
All resulting decisions remain private and tracking-only.

### MLB F3/F5 workflow

F3 and F5 use official inning line scores and separate three-class models for
away win, tie, and home win. The period artifact is deliberately independent
from the full-game artifact:

```bash
php artisan mlb:backfill-period-history --from-season=2021 --to-season=2025
# New period registration training is unavailable; no weekly training job is scheduled.
php artisan mlb:run-period-shadow
php artisan mlb:report-period-model-performance
```

The manual `mlb:train-period-models` default reports the unsupported registration
capability before scanning sources or launching Python. `--no-register` retains
its existing research-only source checks; current reconstructed historical rows
still fail those checks. Neither retrospective export nor a worker declaration
supplies the missing period training-source proof. Retained artifact reads and
period inference remain available subject to their existing admission checks.

The decision layer compares conditional home/away probabilities with the
captured two-way quote, but calculates expected value from absolute win and
loss probabilities so the modeled tie remains a push. Period artifacts have
no promoted markets at registration. Promotion requires stable chronological
windows plus enough settled live quote decisions with positive ROI and CLV.

## Weekly MLB And NFL Automation

The in-season scheduler runs the complete challenger lifecycle:

- MLB: Monday at `06:40` Central Time
- NFL: Tuesday at `12:40` Central Time, after Monday games and readiness jobs

The scheduled command uses the sport's configured ML Python binary. The
actual hosting/runtime identity must be verified for the selected deployment. Training is isolated in a child process
with a bounded timeout and thread count; it never receives database
credentials because the Python package reads only the immutable export.

The coordinator delegates Python execution and initial manifest lineage checks to
`App\Services\ML\LocalTrainingExecutor`. The executor receives the existing
training options and dataset identity, preserves the subprocess timeout and CPU
limits, and returns the verified run directory. Dataset preparation, cycle status,
registration, promotion evaluation, and cleanup remain owned by the coordinator.
Local execution remains the default. Optional remote transfer is disabled by
default; see [remote model training](operations/remote-model-training.md) for
worker credentials, private inputs/uploads, client operation and deployment gates.

Prepared cycles now retain their dataset path/hash, training fingerprint, and
resolved training specification. `model_training_attempts` records each attempt's
owner token, separate Python run UUID, attempt number, fixed lease, terminal
status, and completion receipt. The default limit is three attempts per cycle
(`ML_TRAINING_MAXIMUM_ATTEMPTS`). The lease is the configured Python timeout plus
600 seconds for registration; no automatic retry loop or lease renewal is added.

Recovery is explicit:

```bash
php artisan sports:train-weekly-model-challenger nfl --resume=<cycle-uuid>
```

Resume reuses the prepared dataset and settings, verifies the dataset and
code/schema fingerprint, and skips prior-challenger promotion and reconstruction.
It refuses active owners, changed inputs, exhausted cycles, and legacy cycles
without a frozen preparation. A crashed owner becomes abandoned after its lease
expires; each new attempt trains into a separate run directory. A successful
cycle returns its stored receipt on resume, even after local cleanup.

Completion locks the cycle and latest attempt, rejects expired or superseded
owners, and commits registration, offline evaluation, shadow selection, cycle
completion, and the attempt receipt in one database transaction. Identical
completion hashes return the receipt without repeating registration; conflicting
hashes are rejected. Failures from stale owners cannot overwrite a newer cycle.
The completion hash covers all returned run files. Existing bundle validation
still verifies artifact contents and lineage before registration.

Python execution occurs outside database locks. Completion includes the existing
storage writes while holding the cycle lock; storage is not transactional, so a
process crash may leave private unreferenced objects. Database registration rolls
back and a recovered attempt uses a fresh Python run UUID. Cleanup after a
committed receipt is best effort; unfinished cycle directories are excluded from
age-based pruning to retain recovery inputs. Operators must review disk usage
and terminal failures; this change adds no background recovery monitor.

The additive `2026_11_26_010000_create_model_training_attempts.php` migration must
precede new training code in any future deployment. Drain old training processes
before switching code: old binaries do not enforce attempt ownership. Rollback
retains the attempt table and audit history. Local SQLite tests exercise recovery
and receipt semantics; they do not establish MySQL multi-process concurrency or
Mac performance. No production migration or activation is implied.

Each `sports:train-weekly-model-challenger` cycle:

1. settles the prior active challenger's promotion evaluation;
2. reconstructs missing trusted current-season NFL rows when applicable;
3. exports to a new atomic dataset and fails when no rows qualify;
4. hashes the dataset, schema, package source, and training configuration;
5. skips an exact duplicate fingerprint;
6. trains with bounded CPU threads and a four-hour timeout;
7. verifies the returned run ID, artifact type, and dataset hash;
8. registers the immutable artifact, dataset, and evaluation report;
9. evaluates the new artifact without bypassing any promotion gate; and
10. assigns an offline-eligible challenger to the private shadow cohort.

The Python training stage keeps chronological evaluation untouched, then
performs a separate deployment refit through the latest settled training row.
This allows the deployed challenger to learn from the current season without
leaking those rows into its reported held-out metrics.

Every automation cycle is recorded as a `weekly_training_cycle` model run with
its current stage, dataset hash, fingerprint, artifact ID, timestamps, and
terminal status. Failed cycles retain a private `failure.json` in
`storage/app/ml/automated-training/<sport>/<cycle-id>`.

`MLB_ML_AUTO_PROMOTE_ENABLED` and `NFL_ML_AUTO_PROMOTE_ENABLED` control whether
the previously selected challenger may be promoted after every offline and live
gate passes. Promotion still cannot publish a pick directly: model outputs flow
through private `bet_decisions`, price, edge, uncertainty, risk, and bankroll
rules. Set either flag to `false` to keep automated training and shadowing while
requiring manual promotion.

Use these commands for an immediate run or a safe test:

```bash
php artisan sports:train-weekly-model-challenger mlb
php artisan sports:train-weekly-model-challenger nfl
php artisan sports:train-weekly-model-challenger nfl --no-promote --retain-workdir
```

## Promotion Policy

The default policy requires:

- at least three chronological windows
- the challenger to beat baseline in at least 60% of windows
- a positive average primary-metric improvement
- a positive average log-loss improvement for win probability
- no held-out window to exceed the configured regression ceiling
- market-specific eligibility for win probability, spread, and total
- at least 25 live pregame-safe observations and 10 settled shadow decisions
  before actual promotion

Live requirements count distinct games per artifact and market. Repeated
pregame snapshots for one matchup cannot inflate promotion evidence.

All normalized reports use `baseline - challenger`, so a positive delta means
the challenger is better. Legacy Laravel reports are converted at the boundary.

`sports:evaluate-model-promotion` marks a passing artifact
`promotion_eligible`; only an explicit `--promote` evaluation makes it eligible
for decision-time use. The weekly coordinator may issue that explicit
evaluation for the selected challenger when the sport's auto-promotion flag is
enabled. Whether a promoted artifact can change a live public output remains
controlled separately, and MLB/NFL automated outputs stay private.

## Decisions And Settlement

Shadow outputs flow into immutable, tracking-only decisions:

```bash
php artisan sports:record-shadow-bet-decisions --sport=nba
php artisan sports:settle-bet-decisions --sport=nba
php artisan sports:record-shadow-bet-decisions --sport=nfl
php artisan sports:settle-bet-decisions --sport=nfl
php artisan sports:record-shadow-bet-decisions --sport=mlb
php artisan sports:settle-bet-decisions --sport=mlb
php artisan sports:report-model-feedback <artifact-uuid>
php artisan nfl:materialize-signal-observations --season=2026
php artisan nfl:grade-signal-observations --season=2026
php artisan nfl:report-signal-grades --from-season=2021 --to-season=2026
```

NFL signal grading is incremental and bounded. The scheduled command selects
only finalized observations with missing outcome grades, an NFL result change,
or a new/changed settlement. Updating live odds on `nfl_games` does not make a
historical grade stale. Defaults are 250 rows per database batch and 1,000
observations per run; the hard ceiling is 5,000. Use `--refresh` only for an
intentional, bounded rebuild, for example:

```bash
php artisan nfl:grade-signal-observations --season=2026 --limit=1000 --batch-size=250
php artisan nfl:grade-signal-observations --season=2026 --refresh --limit=1000
```

If the command reports pending work remains, let the next scheduled run drain
it or repeat the same bounded command. Do not use an unbounded full-season
refresh in Laravel Cloud.

A tracking bet requires that promotion was already active at decision time, the
feature snapshot was pregame safe, the quote existed at decision time, and edge
met the configured threshold. Historical promotion cannot create a
retrospective bet.

Decisions are never public. Settlements retain actual profit, counterfactual
shadow profit, closing-line value, calibration error, and no-bet reasons. NBA
and NFL decision and settlement jobs run after their scheduled prediction and
grading stages.

### NFL canonical operations

Use [NFL canonical operations](operations/nfl-canonical-operations.md) for
current readiness and recovery procedures. The former Week 1/Week 2 Cloud
incident report and its release-specific activation commands have been retired.
Confirm the active release, configuration, and model comparison before changing
reader or writer gates; readiness is not model validation or permission to
promote. Never backdate a release or create a pregame forecast after kickoff.

Canonical NFL input snapshots freeze the selected pregame quotes and canonical
evaluation grades ATS/total comparisons from those immutable inputs. Mutable
`nfl_games.odds_data` is only the current market container and must not be used
to reconstruct a historical line. A canonical NFL market input is available
only when the provider `last_update` timestamp is within
`NFL_PREGAME_MARKET_MAXIMUM_QUOTE_AGE_MINUTES` and the same captured market has
complete, priced home/away spread and over/under total pairs. That provider
timestamp is persisted in each quote's metadata and is preferred for freshness;
when the feed omits it, the persisted ingestion `captured_at` is the
authoritative conservative observation proxy. The readiness report applies the
same freshness and pair-coverage contract to every upcoming
regular-season or postseason slate game as of that immutable canonical snapshot's capture
time; a valid frozen input does not become invalid merely because wall-clock
time later exceeds the quote-age window. Separately, readiness rejects canonical forecasts older than the configured six-hour forecast limit.
Successful fresh fetches of unchanged NFL markets periodically create a new
immutable observation without replacing the older entry record.

NFL release `1.2.0` (`nfl-pregame-v3`) freezes the existing deterministic
recommendation calculation into `recommendation_context` on the canonical
input snapshot. The calculation runs without publishing a mutable legacy row
or feature snapshot. It retains existing historical calibration evidence and
current-pregame rules. True EPA remains recommendation evidence; it is not
newly applied to the separate canonical primary forecast or labeled calibrated.

Readiness reads true-EPA and recommendation dispositions from that immutable
context and native decisions tied to the exact prediction, calculation run and
input snapshot. Every slate game must have either `true_epa.applied` or an
explicit `analysis_layer.eligibility.status=hold` with `missing_true_epa`.
An explicit hold is auditable but blocks cutover; quarantine, missing context,
missing dispositions, stale forecasts and unreleased candidates also remain
blocking. Legacy records and colliding IDs cannot supply missing native evidence.

The ordered production chain runs canonical generation/readiness followed by
bounded research assessment/readiness using stored markets. The true-EPA
preflight runs before native input capture. Registry predict/full generation
uses the same command; neither active path invokes legacy generation. Unchanged
fresh inputs can reuse immutable revisions. Research baselines use frozen native
recommendation evidence when the canonical pipeline is enabled and retain the
original missing-baseline hold when that evidence is unavailable.

The pipeline runs at 10:20, 12:20, 16:20 and 20:20, plus hourly at :20 from
06:00–23:00 when kickoff is within 24 hours. Odds refresh is separate at :10
of 06:00, 10:00, 14:00, 18:00 and 22:00. Weather refreshes remain separate.
Stages stay in the scheduler process; a generation/readiness hold does not
suppress the bounded later steps, but the overall pipeline still fails. The
independent 10:50 sentinel remains enabled even when canonical writes are
disabled and shares the pipeline overlap mutex. Existing signal-grading cadence
and provider refresh ownership remain unchanged.

Native generation records a disposition for each market: `model_no_bet` when
analysis and a real paired quote are available but no official candidate exists,
otherwise `model_hold` with evidence gaps. Both have `is_bet=false`. Complete
no-bet observations retain counterfactual W-L semantics; held observations stay
excluded from settlement. Native decisions store run/input-snapshot lineage
without populating the legacy feature-snapshot foreign key. Explicit historical
and legacy commands retain their original identities and recorders.

Immediately after generation, an eligible
`official_candidate` with an exact, already-observed pregame quote creates at
most one private tracking decision per prediction and market. AI availability
is not a prerequisite. The quote must still be within the configured maximum
age and have a same-book, two-sided counterpart for the selected market. These
decisions set `is_bet=true` for W-L and
model-profit tracking but retain `is_tracking_only=true`, `is_public=false`,
and `explanation.execution_status=not_recorded`; they are not evidence that a
sportsbook wager was executed. Settlement metadata reports those concepts
separately as `tracked_bet` and `actual_bet_placed`. NFL signal reports show
tracked model selections as W-L-P plus model ROI, even when no wager was
executed; `actual_settlement_sample` and `actual_roi` remain separate JSON
fields and only include non-tracking wagers.

After scores are final, let the scheduler settle and grade incrementally or run
the bounded sequence manually:

```bash
php artisan sports:settle-bet-decisions --sport=nfl
php artisan nfl:materialize-signal-observations --season=2026
php artisan nfl:grade-signal-observations --season=2026 --limit=1000 --batch-size=250
php artisan nfl:report-signal-grades --season=2026 --json
```

## Research and promotion state

Inspect the selected artifact and its retained evaluation/observation records on the approved target. Historical research packets and artifact identities are retained privately. No stored result in documentation establishes current promotion, prospective evidence or betting approval.

## Native output admission and publication ordering

Native prediction market outputs reject non-finite numeric values at construction. Nullable values retain their unavailable meaning; probability and confidence range checks still apply.

A draft cannot be published after a higher revision of the same event and phase has already been published, including when that higher revision was subsequently withdrawn. Generate a new revision to replace a withdrawn forecast; publishing an older draft must not roll the selected forecast backward. Calling publication for the already-published revision remains idempotent. These source-level safeguards do not replace MySQL concurrency verification.

Canonical team-game evaluation accepts the shared ESPN final-status contract (`STATUS_FINAL` and `STATUS_FULL_TIME`). Complete scores, a final clock and the existing minimum elapsed-time gate remain required. Finalization rediscovery and canonical evaluation use the same status list.

## Native calculation attempt recovery

The run's idempotency key and one-prediction-per-run constraint remain stable. `calculation_run_attempts` records each owned execution separately, including failures and abandoned leases. The recovery policy in `config/prediction_lifecycle.php` defaults to three attempts, a 300-second lease and a 60-second failed-attempt cooldown. A later invocation may retry after cooldown or reclaim an expired lease; active owners and attempts beyond the cap are refused. There is no internal retry loop or lease renewal.

Claims, output persistence and failure recording lock event → run → attempt. Results require the exact latest owner token and an unexpired lease; a late worker cannot overwrite a recovered attempt. Terminal attempt receipts are retained. Existing successful runs remain reusable after kickoff, but new pregame work and results require a current future event start. `effectiveAt` selects the release window and does not grant a historical execution bypass. Frozen snapshot evidence cutoffs retain their distinct input-timing meaning.

Publication now locks the same canonical event before candidate/current prediction rows, keeping generation and publication serialization consistent. It retains monotonic publication admission and rejects an older draft after a newer revision has been published.

Before deploying recovery, apply the additive attempt migration and drain/recycle old application/queue/scheduler processes: old binaries do not enforce attempt fencing. Keep the table/audit during rollback and quiesce active work before changing binaries. Unaudited legacy running recovery is disabled by default with `PREDICTION_LIFECYCLE_ALLOW_LEGACY_RUNNING_RECOVERY=false`; enable it only after the old worker drain is verified. Missing timestamps on legacy running rows require reviewed repair rather than automatic reclamation. See [recovery implementation and verification gates](contracts/calculation-recovery.md) for imported legacy receipts, retry bounds, actual MySQL race tests and remaining release gates.

Publication monotonic admission and revision allocation use locking/current reads. Under MySQL REPEATABLE READ, an ordinary lookup can establish a snapshot before waiting for the event lock; a later ordinary existence check could miss a publication committed while it waited. The publisher uses the locked event for kickoff checks and a current locking read for newer published revisions.

## Durable publication effects

Publication writes unique `prediction_publication_effects` receipts inside its transaction, alongside the selected forecast. Cache invalidation is always admitted; analysis and canonical narrative admission freeze the existing sport, trigger and enabled-feature gates. Re-publishing wakes existing receipts and never creates retroactive work that was disabled at admission. Existing published history is not automatically backfilled.

The after-commit callback is only a prompt wake-up. `sports:reconcile-publication-effects --limit=100` runs every minute to recover missing callbacks, failed dispatch and expired owners. Each claim has an expiring owner token and a bounded attempt count; late workers cannot update newer receipts. Defaults are8 attempts,300-second lease and60-second initial retry with capped exponential backoff. An expired final queued/running attempt becomes terminal `exhausted`; operators must review the retained reason and receipt. Disabled or superseded work is cancelled; analysis additionally rechecks the pregame deadline and supported game status. Handlers execute outside database transactions and keep existing review/research budget and admission controls.

Cache work runs immediately through the durable ownership path. Other effects use the supported default queue through a90-second wrapper with one broker attempt; database receipts own retries. Delivery is at least once: process death after a handler effect but before receipt completion may repeat that handler. A completed receipt means the handler returned, not a provider approval or exactly-once external call. Queue/scheduler liveness and exhausted-receipt monitoring remain release gates.

Apply additive migration `2026_11_25_030000_create_prediction_publication_effects.php` before new publisher/worker code, and drain old callback-only workers during coordinated rollout. Rollback retains the table and receipts; destructive down migration is refused, and repair must proceed forward. This is the fourth local pending migration against the observed356-row production ledger (360 source migrations). The earlier real Cloud clone drill applied only the preceding three to359; it does not prove this later migration or full application recovery. No live code/schema/feature activation followed from these local changes. See [implementation, regression evidence and limits](contracts/publication-effects.md).


## November 27 private candidate: current write and live-data boundaries

### MLB shadow artifact selection

`ShadowArtifactSelector` routes persisted MLB identities through the qualified tabular selection coordinator. Activation and deactivation update selection metadata. They require a standalone supported primary MySQL connection, current protected training/source evidence and a matching artifact owner. The coordinator locks source rows, the complete MLB artifact range, bounded current cohort state and predetermined target records before committing selection changes. Its metadata is detached from caller references; failed or cancelled saves roll back the transaction.

Database sport/family/status comparisons use the same SQL predicates as the readers, including the configured collation. Malformed or stale caller identity cannot select an unrelated cohort. A qualified replacement retires matching active peers; protected training-run bytes remain unchanged. Unsupported databases, period artifacts, stale source/owner/status or unqualified records refuse before selection changes. Investigate the current source and registration receipts rather than adding markers or changing scientific approval to force qualification. Period provenance, promotion, report attachment, archive and final inference-output transactions have separate acceptance gates.

The final selector scope passed eight native MySQL tests / 172 assertions and thirteen portable tests / 42 assertions on their recorded source. The native fixture used an opaque synthetic bundle for registration, without fitting or loading a model. This establishes selection consistency, not predictive quality or deployed-worker acceptance.

### MLB period captures

Owned canonical period snapshots reject ordinary, quiet, numeric and delete instance changes using current bounded source classification. Legacy descriptive rows remain mutable, while existing rows cannot acquire canonical identity through an instance update. The guard selects only ID and at most sixteen classification bytes, excluding the captured feature body. New inserts and trusted bulk SQL remain separate boundaries; a canonical marker alone does not establish protected training provenance. Reconstructed historical period training remains refused. Scoped SQLite verification passed twelve tests / 94 assertions; native MySQL verification separately passed three tests / 86 assertions.

### Nullable basketball live totals and display

The shared WNBA/WCBB live action retains a missing baseline total as null in newly computed projections and snapshots. Known totals, genuine zero, spread and win-probability calculations retain their existing mathematics. The reviewed card adapters preserve missing priors as null, show unavailable markets explicitly and label retained pregame values when a live value is unavailable. Existing card live-probability gates remain unchanged. Historical snapshots are retained.

These PHP and UI changes have separate 42-test / 168-assertion and 606-test frontend evidence, plus fresh quality/main/SSR build receipts. WCBB period-format qualification and other adapters remain separate review questions. All work in this section follows the second frozen full checkpoint and requires subsequent combined-source verification; this section records a private candidate, not a deployment.


## Calculation release transition acknowledgements

Approval, retirement and invalidation now acknowledge a transition only after the model save returns true and a primary read confirms the requested lifecycle fields. A cancelled save or changed requested field raises an error inside the local transaction, preserving the previous row. The manager reloads the current release from its supplied connection and literal release-table/key context; feature-schema and dataset lookups use that connection. These checks do not change approval criteria, released model parameters or historical release definitions.

The post-ninth private repair passed 33 portable tests/187 assertions at 128 MiB and the same new feature file after-only on MySQL 8.4.6 (8 tests/17 assertions, 382 migration rows/269 tables). The alternate-connection positive uses an explicitly minimal independent SQLite store, not full alternate-schema parity. Native cleanup verified unchanged source/dependencies/assets/environment and no grant or instrumentation changes. Concurrent component changes, alias/PDO replacement, arbitrary unrelated observer definition changes and production rollout remain separate boundaries.

## CBB roster observation immutability — November 27, 2026

`CBB\RosterSnapshot` and `CBB\RosterEntry` use the shared immutable-model guard for persisted instance writes, including quiet save/update/delete and numeric mutation. New observations, including quiet creation, remain allowed; original mutation error messages and `knownAt` selection are retained. Persisted no-op saves and deleting unsaved instances are refused by this existing guard. Raw query builders, administrators, cascades and privileged instance-state changes remain separate trusted boundaries.

Private repair evidence (private verification record retained separately): 74 tests/775 assertions including roster ingestion and player API compatibility; the same15 boundary cases passed MySQL with49 assertions. This is a later private source change excluded from Full9, without production activation or a general historical-safety/concurrency certificate.
