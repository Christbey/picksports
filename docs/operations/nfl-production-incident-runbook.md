# NFL canonical production incident runbook

## Confirmed incident

Laravel Cloud production had the following effective configuration during NFL
Week 1:

```text
prediction_lifecycle.canonical_pipeline.nfl=false
prediction_lifecycle.canonical_reads.nfl=false
nfl.predictions.true_epa.enabled=true
nfl_research.enabled=true
```

The disabled canonical pipeline prevented the 08:35 evaluator and 10:05
generator from running. Legacy predictions and research could still run, which
made general data readiness appear healthier than the canonical lifecycle.
This is a production configuration incident, not evidence that canonical Week 1
predictions were generated and later lost.

NFL readiness now fails closed when the effective canonical pipeline, true-EPA
preflight, or research pipeline is disabled. It also fails when there are zero
eligible regular/postseason events or zero safe canonical predictions. Canonical
reads are reported but are intentionally not a readiness prerequisite. The
ordered pregame pipeline and an independent 10:50 sentinel expose disabled
configuration as a failure. Both use the same overlap mutex. The generator
verifies readiness at the end of its own run, preventing a check of a partial
slate. A missing input or failed game must not prevent other games being
processed; the overall command still fails until every required game is ready.

## Pre-deploy Week 1 repair

Take a production database snapshot before writes. Then capture the current
incident state with the Laravel Cloud CLI:

```bash
cloud command:run production --cmd="php artisan nfl:report-canonical-cutover-readiness --season=2026 --json --fail-on-not-ready"
```

Before deployment, the expected result is a nonzero exit with
`canonical_pipeline_enabled=false` and
`canonical_pipeline_disabled` in `readiness_blockers`. Preserve that output in
the incident record.

Repair only factual Week 1 results and explicitly historical model records:

```bash
cloud command:run production --cmd="php artisan espn:sync-nfl-games-scoreboard --from-date=2026-09-10 --to-date=2026-09-14 --sync"
cloud command:run production --cmd="php artisan espn:sync-nfl-game-details --lookback-days=7 --days-forward=0 --limit=32 --sync"
cloud command:run production --cmd="php artisan nfl:backfill-historical-predictions --season=2026 --from-date=2026-09-10 --to-date=2026-09-14 --season-type=2 --profile=full-historical --only-missing --regrade"
cloud command:run production --cmd="php artisan nfl:grade-predictions --season=2026"
cloud command:run production --cmd="php artisan sports:settle-bet-decisions --sport=nfl"
cloud command:run production --cmd="php artisan nfl:materialize-signal-observations --season=2026"
cloud command:run production --cmd="php artisan nfl:grade-signal-observations --season=2026 --limit=1000 --batch-size=250"
cloud command:run production --cmd="php artisan nfl:research-pipeline --grade --grade-limit=250 --grade-batch-size=50"
```

`--only-missing` prevents overwriting legacy Week 1 predictions that genuinely
existed. The historical profile labels any newly reconstructed snapshots as
reconstructions. None of these commands creates a canonical pregame prediction
or a retrospective bet decision.

Do not backdate a calculation release, publish canonical Week 1 rows after
kickoff, or recreate absent Week 1 decisions from final outcomes. Those records
must remain an explicit Week 1 coverage gap. W-L analysis can use the graded
legacy/historical prediction records, but it must not be presented as a record
of released wagers.

Verify after repair:

- Every Week 1 regular-season game has final status plus home and away scores.
- Existing legacy predictions have `graded_at`; missing ones created by the
  historical command carry reconstruction lineage.
- Only decisions that existed before kickoff can settle.
- Week 1 canonical count remains unchanged; missing canonical rows remain
  documented as the incident gap.
- Re-running both grading commands reports zero work unless a result,
  settlement, or pending player-prop actual changed.

## Week 2 activation

Set these Laravel Cloud environment values before the deployment:

```dotenv
PREDICTION_LIFECYCLE_NFL_CANONICAL_PIPELINE=true
PREDICTION_LIFECYCLE_NFL_CANONICAL_READS=false
NFL_TRUE_EPA_ENABLED=true
NFL_TRUE_EPA_BACKFILL_BEFORE_GENERATION=true
NFL_PREGAME_MARKET_MAXIMUM_QUOTE_AGE_MINUTES=60
NFL_RESEARCH_PIPELINE_ENABLED=true
NFL_RESEARCH_GRADING_BATCH_SIZE=50
NFL_RESEARCH_GRADING_MAX_PER_RUN=250
NFL_RESEARCH_GRADING_RETRY_AFTER_MINUTES=55
NFL_SIGNAL_GRADING_BATCH_SIZE=250
NFL_SIGNAL_GRADING_MAX_PER_RUN=1000
```

Deploy code and the additive migrations, then rebuild cached configuration:

```bash
cloud command:run production --cmd="php artisan migrate --force"
cloud command:run production --cmd="php artisan config:cache"
```

Run readiness immediately. It should still fail until safe Week 2 rows exist,
but it must report all configuration fields as enabled and must not contain any
`*_disabled` blocker:

```bash
cloud command:run production --cmd="php artisan nfl:report-canonical-cutover-readiness --season=2026 --json --fail-on-not-ready"
```

Register the release with the actual current timestamp while it is still before
the first target kickoff. Never use a Week 1 timestamp:

```bash
cloud command:run production --cmd="php artisan nfl:register-calculation-release --release-version=1.1.0 --replace-active --effective-at=<CURRENT-PREKICKOFF-ISO-8601> --actor=production-incident-remediation --reason='Enable verified NFL canonical v2 lifecycle after Week 1 configuration incident.'"
```

`--replace-active` atomically retires any effective approved NFL pregame
release at the same timestamp that v1.1.0 becomes active. Do not register the
new release without this option when an older release exists: calculation
selection intentionally fails if two releases are active for the same instant.
Readiness scopes cutover coverage to v1.1.0, so the immutable Week 1 incident
gap remains reported in the incident record without blocking factual Week 2
activation.

Refresh production inputs and generate in the same order used by the scheduler:

```bash
cloud command:run production --cmd="php artisan espn:sync-nfl-pregame-metadata --season=2026 --days-forward=8 --limit=32"
cloud command:run production --cmd="php artisan nfl:sync-game-weather --season=2026 --days-back=0 --days-forward=8 --force"
cloud command:run production --cmd="php artisan nfl:research-pipeline --days-forward=7 --limit=8 --ingest-only"
cloud command:run production --cmd="php artisan nfl:research-pipeline --days-forward=7 --no-ingest --limit=4"
cloud command:run production --cmd="php artisan nfl:run-pregame-pipeline --season=2026 --days-forward=8"
cloud command:run production --cmd="php artisan nfl:report-canonical-cutover-readiness --season=2026 --json --fail-on-not-ready"
```

The final readiness command must exit zero and assert:

- `canonical_pipeline_enabled=true`
- `true_epa_enabled=true`
- `true_epa_backfill_enabled=true`
- `research_pipeline_enabled=true`
- `eligible_event_count > 0`
- `safe_published_event_count = eligible_event_count`
- `slate_event_count > 0`
- `true_epa_disposition_event_count = slate_event_count` (applied or explicit
  `missing_true_epa` hold; a held generation run still exits nonzero)
- `true_epa_explicit_hold_event_count=0` (holds remain visible but block
  readiness until EPA is backfilled and predictions are regenerated)
- `immutable_pregame_market_event_count = slate_event_count`
- `spread_quote_coverage_event_count = slate_event_count`
- `total_quote_coverage_event_count = slate_event_count`
- `missing_true_epa_disposition_game_ids=[]`
- `missing_immutable_pregame_market_game_ids=[]`
- `missing_spread_quote_game_ids=[]`, `missing_total_quote_game_ids=[]`
- `recorded_disposition_event_count = slate_event_count`, with moneyline,
  spread and total dispositions backed by immutable pregame feature snapshots
- `missing_disposition_game_ids=[]`
- `missing_safe_prediction_count=0`
- `unsafe_published_revision_count=0`
- `duplicate_published_event_count=0`
- `missing_evaluation_count=0` for final events with safe predictions
- `configuration_blockers=[]`, `coverage_blockers=[]`, and
  `ready_for_cutover=true`

Confirm `schedule:list` contains the bounded production paths:

```bash
cloud command:run production --cmd="php artisan schedule:list"
```

- `NFL: Pregame Pipeline` at 10:20, 12:20, 16:20 and 20:20; additionally
  hourly at :20 from 06:00 through 23:00 when a kickoff is within 24 hours
- One ordered odds -> legacy -> canonical/readiness run with the same eight-day
  horizon and regular-season plus postseason eligibility
- `NFL: Canonical Cutover Readiness Sentinel` at 10:50
- Weather at 06:05, 10:05, 14:05, 18:05 and 22:05
- Signal grading at :05/:20/:35/:50, limited to 1,000 observations in 250-row batches
- bounded research grading at minute 55 with `--grade-limit=250` and
  `--grade-batch-size=50`

The canonical generator command must include `--verify-readiness`. The pipeline
and 10:50 sentinel must both be foreground events and expose the same
`nfl-canonical-generation-readiness` overlap mutex.

Keep `PREDICTION_LIFECYCLE_NFL_CANONICAL_READS=false` for this release. The
canonical calculator is a separate rules model; it does not apply true EPA,
weather, sourced research or all position-specific legacy adjustments. Readiness
proves lifecycle integrity, not forecasting superiority. The richer legacy
forecast remains the live authority while canonical predictions collect an
immutable comparison record. Promote canonical reads only after explicit model
comparison and release review, not merely a passing readiness check.

Research ingestion rotates through eight teams and review through four games
per scheduled run. On activation, repeat bounded ingestion/review until the
whole slate has an attempted disposition. A provider cooldown or missing source
must remain visible as a hold; it must never produce fabricated analysis. Check
document URLs, observed timestamps, revision status, holds, and actual game
coverage, not command exit status alone.

Full and data validation include `validation_nfl_research_coverage`. Missing or
stale research and broken revision evidence fail within 24 hours of kickoff and
warn farther out. Fresh partial reports and explicit data holds remain warnings;
a model pass with complete evidence is not an operational failure. The check
uses batched database reads and never calls a research provider. When an older
revision's evidence expires, the research pipeline records a new immutable
revision linked to refreshed evidence, even if the model conclusion is unchanged.

The signal grading checkpoint migration bootstraps incrementally. Repeated
bounded grading runs should drain pending observations and then report no work.
Corrected scores, partial grade rows and changed settlements reopen affected
observations. NFL ties retain spread/total grading and leave binary winner
accuracy metrics null.

## Rollback

If generation or API smoke tests fail:

1. Keep or restore `PREDICTION_LIFECYCLE_NFL_CANONICAL_READS=false`; this returns
   reads to the legacy path without deleting audit data.
2. Set `PREDICTION_LIFECYCLE_NFL_CANONICAL_PIPELINE=false` only to stop new
   canonical writes. Expect the independent readiness sentinel to fail and
   alert until the incident is resolved.
3. Leave research enabled unless it is the failing subsystem. To stop new web
   research, set `NFL_RESEARCH_PIPELINE_ENABLED=false`; existing evidence and
   revisions remain immutable and readable.
4. Do not delete or mutate published predictions, feature snapshots, market
   snapshots, decisions, or settlements. Roll back application code and
   environment configuration instead.
5. The migration is additive. Do not run its `down()` in production during an
   incident; the new result timestamp and indexes are safe to leave in place.
