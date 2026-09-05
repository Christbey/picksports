# CFB Week 1 provenance runbook

This runbook makes the Week 1 public prediction board reproducible. The official record is the latest canonical pregame revision published before each event starts. Legacy `cfb_predictions` rows may continue to exist during the transition, but they are not the public record and must not be used for Week 1 scoring.

## Required lifecycle

```text
Canonical event
→ immutable input snapshot
→ approved calculation release
→ successful calculation run
→ immutable published prediction revision
→ immutable final result revision
→ immutable evaluation revision
```

Every public prediction must resolve through that chain. A later pregame rerun creates and publishes a new revision while preserving the prior revision. Publishing at or after kickoff is rejected. Published, superseded, and withdrawn revisions and their markets cannot be edited.

## One-time production activation

1. Deploy the lifecycle migrations and application code.
2. Keep `PREDICTION_LIFECYCLE_CFB_CANONICAL_READS=false` during the initial shadow run.
3. Set `PREDICTION_LIFECYCLE_CFB_CANONICAL_PIPELINE=true` and ensure the Laravel Cloud scheduler is running on only one environment.
4. Link CFB games to canonical events:

   ```bash
   php artisan sports:backfill-event-identities --sport=cfb --dry-run --isolated
   php artisan sports:backfill-event-identities --sport=cfb --isolated
   ```

5. Register one approved CFB rules release. Use a new semantic version whenever the frozen configuration or deployed calculator code changes:

   ```bash
   php artisan cfb:register-calculation-release \
     --release-version=1.0.0 \
     --actor=production-release \
     --reason="Initial immutable CFB canonical release for 2026 Week 1"
   ```

Do not run `sports:backfill-canonical-predictions` to create the Week 1 official card. That command preserves legacy compatibility rows; native canonical generation creates the auditable release.

## Week 1 pregame sequence

Run after event, team, metric, injury, and odds synchronization:

```bash
php artisan cfb:generate-canonical-predictions --season=2026 --week=1
php artisan cfb:report-canonical-cutover-readiness \
  --season=2026 \
  --week=1 \
  --json \
  --fail-on-not-ready
```

The readiness command must report:

- `ready_for_cutover: true`
- `missing_safe_prediction_count: 0`
- `unsafe_published_revision_count: 0`
- `duplicate_published_event_count: 0`

Rerun generation whenever material pregame inputs change. An identical snapshot and release reuse the existing run and prediction. A changed snapshot creates a new immutable revision and atomically supersedes the former publication.

After the report passes, set `PREDICTION_LIFECYCLE_CFB_CANONICAL_READS=true`, redeploy configuration, and verify that the Week 1 API response includes `meta.prediction_source: canonical`.

## Postgame sequence

After the scoreboard synchronization has stored final scores:

```bash
php artisan cfb:evaluate-canonical-predictions --season=2026 --week=1
php artisan cfb:report-canonical-cutover-readiness \
  --season=2026 \
  --week=1 \
  --json \
  --fail-on-not-ready
```

The evaluator selects the latest canonical revision published no later than kickoff. Repeating evaluation with unchanged results is idempotent. A corrected provider result creates a new immutable result and evaluation revision instead of overwriting history.

## Stop conditions

Do not enable canonical reads, publish a Week 1 performance report, or calculate ROI when any of these conditions is present:

- a Week 1 game lacks a canonical event;
- no single approved calculation release is selectable;
- a published prediction lacks a verified pregame snapshot or successful run;
- a publication timestamp is at or after kickoff;
- a final game has not been synchronized and evaluated;
- the betting decision lacks an immutable market snapshot.
