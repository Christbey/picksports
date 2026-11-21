# NBA research rollout

Prepared November 20, 2026. **Preparation only: no production deployment, migration, configuration change, research ingestion, or paid AI call was performed.** Start with a manually operated research pilot; AI ownership and automation are separate approval gates.

## Verified production target

Read-only Laravel Cloud metadata and a read-only MySQL transaction verified:

| Item | Observed value |
| --- | --- |
| Application / environment | `picksports` / `production` |
| Environment ID | `env-a27d3483-e0d2-445f-9993-e61fffd04683` |
| Primary URL | `https://picksports.app` |
| Release root / timezone | `/var/www/html` / `America/Chicago` |
| Deployed commit | `b176298f6e39b20f0f64dc5cd236af3f0331da69` |
| Deployment | `depl-a3466994-544b-4e20-8ddb-78fb4291d7dd` |
| Database | MySQL 8.4.7-7, InnoDB, 16 KB pages |
| Deployment behavior | Pushes to `main` deploy automatically; deploy command is `php artisan migrate --force` |

The [redacted preflight evidence](nba-rollout-preflight-2026-11-20.json) was collected at November 20, 10:03 p.m. Central. [The reusable script](../../scripts/nba-rollout-preflight.php) reads cached configuration and database metadata using a read-only transaction. It does not bootstrap application jobs. Recheck the deployed commit, staged settings, migration ledger and candidate migration filenames immediately before release.

All five shared tables and both migration ledger entries are absent. Existing NBA and NFL canonical prediction reads/pipelines are enabled. Existing NFL research and daily AI review remain enabled. New shared flags are absent and new code is not deployed. This verifies compatibility prerequisites, **not successful production execution of the new migrations**.

## Release and migration gates

1. Review the [isolated candidate](nba-release-candidate.md), based directly on the verified deployed SHA. The original workspace contains unrelated changes; its local HEAD is not the release candidate. Do not push `main` until the release is approved because it automatically deploys.
2. Verify a recoverable database backup/PITR point and the owning operator. Read-only Cloud metadata now confirms an available production-cluster snapshot `db-snapshot-a34493f8-7566-4cdc-81a0-d30215e9bfb6`, created November 19 at 10:35:56 p.m. Central (November 20 04:35:56 UTC). Snapshot availability is verified; restore testing, PITR capability, acceptable recovery age and the owning operator remain unverified. Recheck immediately before deployment. Do not restore production as a routine application rollback.
3. Re-run the preflight against the exact candidate filenames. The only expected pending migrations are:
   - `2026_11_20_230100_create_prediction_review_work_tables`
   - `2026_11_21_010000_create_shared_research_storage`
4. Check every staged Cloud setting, not only this feature's variables. A deploy applies staged settings together. Keep all new feature flags disabled for the initial schema/code deployment. Confirm all other sport research/review flags are absent or false before enabling global flags later.
5. Approve and deploy that candidate. The existing Cloud deploy command runs **all** pending migrations. Stop if any additional migration appears; do not blindly approve a broad migrate. No separate production migration command has been run.
6. Verify migration ledger entries, five tables, indexes and foreign keys, unchanged existing NFL rows, API health, scheduler heartbeat and NFL research freshness. Smoke-test disabled feature paths before activation.

Disposable SQLite migration tests cover up/down/up and preservation of existing NFL evidence. Actual candidate migrations also passed 27 checks on isolated MySQL 8.4.6; see [verification](nba-rollout-mysql-validation.json). This is the same 8.4 series, with minimal parent-key fixtures, not a production clone. Production parent IDs are unsigned BIGINT/InnoDB, matching the additive FKs; the widest new composite index fits the observed MySQL page limit. MySQL DDL is not transactional: if a table exists without its migration ledger entry, stop and reconcile the partial migration. Never blindly rerun or drop it.

The five tables are `analysis_work_items`, `prediction_review_revisions`, `research_sources`, `research_documents`, and `game_research_assessments`. They retain provenance, reservations and audit history. Production rollback retains all five tables; do not run migration downs.

## Source readiness

| Source | Verified use | Pilot restriction |
| --- | --- | --- |
| [NBA injury report](https://www.nba.com/players/injury-report) | Official report index and linked PDF host `ak-static.cms.nba.com` | Select a fresh dated PDF, extract reviewed UTF-8 text, preserve original publication/observation times |
| [NBA today's lineups](https://www.nba.com/players/todays-lineups) | Projected lineups | Not confirmed-lineup evidence; fetched page had no usable dated rows |
| [NBA teams directory](https://www.nba.com/teams) | Official team links | Directory alone supplies no current rotation or travel claims |
| Rotation / rest and travel | No article catalog verified | Show missing coverage; do not manufacture completeness |

The pilot can truthfully have partial availability coverage. It cannot claim complete coverage of availability, lineup, rotation, and rest/travel. There is no automatic source polling or PDF text extractor in this implementation. A generic `--fetch` of a PDF is not an extraction pipeline. Do not ingest an old sample report as current evidence.

Before ingestion, prepare a reviewed packet: fresh report URL, raw PDF SHA-256, extraction tool/version, UTF-8 text, exact-excerpt claim JSON, source publication timestamp and original observation timestamp. Keep the sidecar with the operational evidence. Enforce publication timestamps operationally even though the general ingestion interface permits omission. NBA freshness is 120 minutes; ingestion time must not replace source observation time.

Register a team-specific source for each participating team and scope each document to the canonical event. An unassigned league source has `team_side=game` and does not establish both-team coverage. Use exact host allowlists. Claims must be grounded in the supplied text; no prediction adjustments or betting approvals are produced by source admission.

## Activation steps — execute only after approval

Use an authorized Laravel Cloud CLI session. The local key is named `LARAVEL_CLOUD_API_TOKEN`; the CLI expects `LARAVEL_CLOUD_TOKEN`. Map it privately through the process environment. Never print it, source the whole environment file, or put it in shell history. Commands below assume that mapping already exists.

```bash
CLOUD_ENV='env-a27d3483-e0d2-445f-9993-e61fffd04683'
cloud environment:variables "$CLOUD_ENV" --action=set --key=SPORTS_RESEARCH_ENABLED --value=true --no-interaction
cloud environment:variables "$CLOUD_ENV" --action=set --key=NBA_RESEARCH_ENABLED --value=true --no-interaction
```

These are **staged changes**, not immediate activation. First verify that the candidate code is already deployed, the intended main commit remains unchanged, and no unrelated settings are staged. Then redeploy:

```bash
cloud deploy picksports production --no-interaction
```

This deploys the current configured branch, not a pinned SHA. Coordinate a release window and verify the deployed commit afterward. Build already runs `php artisan optimize`; do not use a one-off Cloud command's `config:cache` as a substitute for redeploying all instances. [Cloud environment documentation](https://laravel.com/cloud/docs/environments) and [deployment documentation](https://laravel.com/cloud/docs/deployments) describe staging and deployment behavior.

For this research-only stage retain:

```dotenv
PREDICTION_REVIEW_ENABLED=false
PREDICTION_REVIEW_NBA_ENABLED=false
NFL_SHARED_RESEARCH_ENABLED=false
PREDICTION_REVIEW_NFL_ENABLED=false
GAME_ANALYSIS_SCHEDULE_ENABLED=false
GAME_ANALYSIS_PUBLISHER_ENABLED=false
```

Absence of these keys uses the same false defaults. Preserve every existing NFL setting, especially `NFL_RESEARCH_PIPELINE_ENABLED`, `AI_NFL_GAME_CONTEXT_RESEARCH_ENABLED`, and `AI_DAILY_PREDICTION_ANALYSIS_ENABLED`. Do not overwrite the entire environment variable set.

### Admit one fresh event packet

The observed candidate was NBA game `1705`, event `13363`, PHI home team `23`, MIL away team `17`, season `2027`, scheduled November 21 at noon Central. **Re-resolve these IDs, status and start time before using them**; this is a dated example, not a standing pilot assignment. Canonical timestamps are stored in application timezone; the raw stored noon is not noon UTC.

Run the following template inside the approved Cloud command environment after staging the reviewed files in that same command/container. Replace every angle-bracket placeholder with reviewed packet values; do not expect temporary files to persist across Cloud commands.

```bash
php artisan sports:ingest-research-document nba nba-official-injuries-phi \
  --register-source --register-only --enable-source --team-id=23 \
  --url='<fresh-official-pdf-url>' --allowed-host=ak-static.cms.nba.com
php artisan sports:ingest-research-document nba nba-official-injuries-phi \
  --event-id=13363 --url='<fresh-official-pdf-url>' \
  --file=/tmp/nba-phi-report.txt --claims=/tmp/nba-phi-claims.json \
  --observed-at='<original-ISO8601-time-with-offset>' \
  --published-at='<report-ISO8601-time-with-offset>'
```

Repeat with the verified away team and its own excerpts. Registration is once per source key; do not pass `--register-source` again for an existing key. Inspect the existing catalog before making changes. For the PDF workflow use `--file`, not `--fetch`. URL overrides are now validated and fetch/store use the same URL when fetching text sources.

Create one assessment without a provider call:

```bash
php artisan tinker --execute='echo json_encode(app(App\Services\Research\GameResearchService::class)->assess(App\Models\NBA\Game::findOrFail(1705), "nba"));'
php artisan sports:report-game-analysis --sport=nba
```

Inspect authenticated admin GET `/api/v2/sports/nba/games/1705/analysis/research` and the admin game-page panel. Verify source IDs, immutable hashes, exact excerpts, team scope, freshness, explicit missing topics, and unchanged canonical prediction/approval values. Public users must not receive admin evidence. Stop for stale, contradictory, wrongly scoped or ungrounded evidence.

### Separate paid AI canary

Only after reviewing research results, approve these two staged settings and redeploy the same reviewed compatible release:

```bash
cloud environment:variables "$CLOUD_ENV" --action=set --key=PREDICTION_REVIEW_ENABLED --value=true --no-interaction
cloud environment:variables "$CLOUD_ENV" --action=set --key=PREDICTION_REVIEW_NBA_ENABLED --value=true --no-interaction
cloud deploy picksports production --no-interaction
```

**This transfers NBA review ownership immediately after deployment. It is not a shadow flag.** The legacy daily NBA review is skipped and shared review reads become authoritative. NFL shared review remains disabled. Leave both automation flags false.

Reconcile only the selected event through `PredictionReviewCoordinator::reconcileGame`, supplying the assessment evidence as the normal refresh job does; inspect the resulting item before processing. Create only the selected work item without executing its provider call:

```bash
php artisan tinker --execute='$game = App\Models\NBA\Game::findOrFail(1705); $evidence = app(App\Services\Research\GameResearchService::class)->forGame($game, "nba"); echo json_encode(app(App\Services\Predictions\Review\PredictionReviewCoordinator::class)->reconcileGame($game, "nba", $evidence));'
php artisan sports:run-prediction-reviews --sport=nba --limit=1 --dry-run
php artisan sports:run-prediction-reviews --sport=nba --limit=1 --inline
php artisan sports:report-game-analysis --sport=nba
```

The runner selects the earliest due NBA item, so confirm the dry-run item is the approved canary and that no other NBA work is due. Do not run inline if that identity differs. A successful dispatch is not a completed review. Inspect revision lineage, current input hash, freshness, unknown versus measured usage, reservation release and NFL health.

Defaults admit at most $5/day application, $1/day NBA, $0.25/event with a $0.05 estimated reservation; these are admission accounting limits, not a provider billing guarantee. Retain defaults. NBA and NFL share the OpenAI circuit breaker: a quota/rate failure can pause NFL calls. Do not clear the shared circuit or raise budgets to force completion.

### Automation gate

After a successful manual canary and capacity review, separately approve staging `GAME_ANALYSIS_SCHEDULE_ENABLED=true` and `GAME_ANALYSIS_PUBLISHER_ENABLED=true`, then redeploy. Reconfirm every other sport's shared flags is false first. Reconciliation runs every 15 minutes, review reconciliation at minutes 2/17/32/47, and review dispatch each minute. It does not guarantee every prediction has a completed AI review or fetch new research documents automatically.

Production's existing default worker timeout is 120 seconds; shared jobs now cap at 90 seconds, provider timeout is 30 seconds, review lease 180 seconds and Redis retry-after 2100 seconds. Test these bounds before release. Existing sync/NFL worker settings are unchanged. Monitor default-queue latency, missed deadlines, spend admission, source freshness, and the existing NFL scheduler/research heartbeat. Sustained fresh NBA coverage requires an approved source acquisition operation; enabling schedules alone does not provide it.

## Exact feature rollback

Stop new NBA admission by staging these settings, then redeploy the same compatible reviewed release:

```bash
cloud environment:variables "$CLOUD_ENV" --action=set --key=NBA_RESEARCH_ENABLED --value=false --no-interaction
cloud environment:variables "$CLOUD_ENV" --action=set --key=PREDICTION_REVIEW_NBA_ENABLED --value=false --no-interaction
cloud environment:variables "$CLOUD_ENV" --action=set --key=GAME_ANALYSIS_SCHEDULE_ENABLED --value=false --no-interaction
cloud environment:variables "$CLOUD_ENV" --action=set --key=GAME_ANALYSIS_PUBLISHER_ENABLED --value=false --no-interaction
cloud deploy picksports production --no-interaction
```

The automation flags were absent at baseline. Disable them only while this pilot owns their use; if another sport has subsequently adopted them, disable NBA's capability flags instead. If no other sport uses shared research/review, also stage `SPORTS_RESEARCH_ENABLED=false` and `PREDICTION_REVIEW_ENABLED=false` before that redeploy. Explicit false restores baseline behavior; removing only newly introduced variables in the Cloud dashboard and redeploying later restores exact baseline absence.

Verify deployed SHA/config, no new NBA admissions, normal NFL reads/writers/heartbeat, and stale NBA legacy reviews remain hidden. The local NBA input-hash guard prevents old legacy reviews resurfacing after ownership rollback. Keep that guard in any code rollback release.

Flag changes take effect with deployment, and already admitted provider calls can finish. Wait for owned NBA attempts/leases to settle and verify their accounting; do not kill shared workers, purge Redis/default queues, reset budgets, clear circuits, or disable old NFL flags. Retain source documents, assessments, review revisions, work ledger and all tables. Never use `migrate:rollback` for feature rollback.

For a code defect, prepare a reviewed compatible revert commit with these flags disabled and deploy it through the normal release process. The installed Cloud CLI has no verified pinned-commit rollback option; `cloud deploy` deploys current `main`. Do not claim it restores an arbitrary old deployment or redeploy pre-guard code blindly.

## Validation and remaining gates

The focused rollout suite passed **76 tests / 396 assertions**. Tests cover migration preservation, exact URL provenance, NBA legacy freshness after ownership rollback, canonical timezone boundaries, research grounding and NFL compatibility, and job/worker lease bounds. Existing broad verification is recorded in the [implementation audit](../multi-sport-research-review-plan.md).

Still required before activation: approved candidate commit and release scope; approved recovery point and restore ownership; migration success on Cloud; fresh reviewed two-team source packet; admin/public smoke checks; manual paid canary approval and shared-provider headroom; and a source-refresh/capacity operation before automation. No production readiness claim depends on unperformed writes.

## Agent checklist evidence

Three agents independently worked through [migration and release](nba-rollout-migration-checklist.md), [source admission](nba-rollout-source-checklist.md), and [activation and rollback](nba-rollout-activation-checklist.md). These distinguish completed local/read-only checks from production execution gates.

A fresh read-only Cloud metadata check confirmed the deployed release remains `depl-a3466994-544b-4e20-8ddb-78fb4291d7dd`, the production schema belongs to cluster `db-a280d080-a70b-4a0d-88de-ce496ac6753f`, and every shared research/review sport flag plus both automation flags remains absent. No variables were staged or changed. The snapshot list was read without creating or restoring snapshots.

Agent completion results: migration/release suite **26 tests / 111 assertions**; activation/rollback suite **58 tests / 340 assertions**. Suites overlap and must not be added as unique test totals. Added an NBA-only rollback regression with the global gate still enabled. No application behavior changes were required by these two audits. The scheduler reference now documents all three opt-in shared schedules and their existing heartbeat hook.

Source audit completed **28 tests / 151 assertions**, plus **5 new NBA source-contract tests / 16 assertions**. Added regressions cover event-only coverage, unrelated team scope, old publication timestamps, publication expiry and source revocation. The [packet manifest template](examples/nba-research-packet.template.json) is deliberately incomplete and must not be ingested as evidence. All checklist links, JSON and code fences were validated.

Current decision: local/read-only preparation checks are complete. Production execution remains gated on the exact candidate release, accepted recovery point/owner, deployment approval and post-deploy evidence. Research activation additionally needs a fresh reviewed source packet; paid review and automation retain their separate gates.


## Isolated candidate preparation

The [release candidate scope and verification](nba-release-candidate.md) supersedes dirty-workspace test results for the release decision. The [latest read-only production preflight](nba-rollout-preflight-latest.json) observed the original release unchanged, both shared tables/migrations absent, and exactly two candidate migrations pending. Revalidate immediately before deployment.
