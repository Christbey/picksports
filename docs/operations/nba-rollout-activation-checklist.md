# NBA activation and rollback checklist

Verified November 20, 2026 against the local candidate source. This is preparation evidence, not a production activation. No production deployment, configuration update, database write, source admission or provider request was made during this checklist. The [owning rollout runbook](nba-research-rollout.md) remains the execution procedure.

## Completed checks

| Check | Result and evidence |
| --- | --- |
| Research-only activation | `research.enabled` and `research.sports.nba.enabled` jointly gate shared NBA research; review has separate global/per-sport gates. NBA `GameResearchService::assess` uses stored documents and results, with no paid discovery. |
| Disabled deployment | All new global, sport, scheduler and publisher flags default false in `config/research.php`, `config/prediction_review.php`, and `config/game_analysis.php`. |
| Ownership cutover | `AnalyzeDailyPredictionsWithAiCommand` skips only sports whose shared review gate is enabled. `PredictionResourcePreparer` suppresses that sport's legacy analysis reads. NBA review enablement is an ownership transfer even with automation disabled. |
| Manual canary side effects | The runbook now uses `PredictionReviewCoordinator::reconcileGame` to create the approved item's work without a provider call. It does **not** call `RefreshGameAnalysis::handle`, which assesses research, reconciles work and immediately runs the provider when review is enabled. |
| Canary selection | `sports:run-prediction-reviews --sport=nba --limit=1 --dry-run` is read-only. Inline execution chooses due work by its ordering, not a requested game ID. The operator must confirm the selected item and ensure no competing NBA work before inline execution; stop if identity differs. |
| AI admission | Runner checks sport enablement before claiming work, checks fingerprint/deadline, retains reservations for unknown paid usage, and delegates budgets/circuit checks to admission. One dispatch is not completion. |
| Scheduler wiring | Three shared scheduler entries all check `game_analysis.schedule_enabled`; reconcile every 15 minutes, review reconcile at 2/17/32/47, runner each minute. The publisher hook independently checks `game_analysis.publisher_enabled` after prediction publication commits. |
| NFL isolation | NBA flags do not enable shared NFL research/review. Existing NFL routes and original settings retain ownership. Shared OpenAI circuit, admission budget and default queue remain operational dependencies; this is not complete infrastructure isolation. |
| Public/admin boundary | Shared research GET and retry require admin and prediction disclosure permissions. Tests cover unauthorized access and unauthorized retry. Read paths do not create review work or provider requests. |
| Rollback admission | NBA per-sport false stops runner/coordinator admission even if global review remains enabled. Added a regression asserting no changes to existing work/AI/revision records and no queue or HTTP action. |
| Stale legacy reads after rollback | NBA legacy input hashes are checked against the selected current input; stale saved analysis stays hidden after shared ownership is disabled. Keep this guard in a compatible code rollback. |
| In-flight work | Gate evaluation is at runner admission. A provider call admitted before rollback can complete and persist accounting/revision; the runbook correctly requires waiting for owned attempts/leases rather than claiming instant cancellation. |
| Data preservation | Feature rollback must retain additive schema, source history, assessments, work and revisions. No down migration, queue purge, circuit reset or budget reset is part of rollback. |
| Cloud commands | Installed CLI help/source confirms `environment:variables ENV --action=set --key=NAME --value=VALUE --no-interaction` updates keyed values, and `deploy picksports production --no-interaction` deploys the configured branch. There is no commit/SHA option in installed deploy help. No mutation command was executed. |

## Local validation

Focused suite: **58 tests passed, 340 assertions**, using the configured SQLite in-memory test database, faked queues/AI and blocked stray HTTP. PHP formatting passed for the changed test.

```bash
bash scripts/php85.sh artisan test --compact \
  tests/Feature/Predictions/PredictionReviewExecutionTest.php \
  tests/Feature/Predictions/CurrentPredictionReviewTest.php \
  tests/Feature/Sports/GameAnalysisDispatchTest.php \
  tests/Feature/NBA/LegacyAiReviewFreshnessTest.php \
  tests/Feature/Api/V2/GameAnalysisRetryEndpointTest.php \
  tests/Feature/Api/V2/GameResearchSummaryEndpointTest.php \
  tests/Feature/Queue/GameAnalysisWorkerContractTest.php
vendor/bin/pint tests/Feature/Predictions/PredictionReviewExecutionTest.php
```

The added regression exercises NBA-only rollback with the global review gate left on. No application behavior was changed by this checklist.

## Still required at the production boundary

- [ ] Review and approve an exact candidate release commit; compare its deployed SHA after each rollout stage. Do not push `main` as an incidental preparation action because push-to-deploy is enabled.
- [ ] Reverify all staged Cloud settings and all other sport capability flags before enabling global settings. Current cached production baseline does not prove that a later stage has no pending unrelated settings.
- [ ] Verify backup/PITR, perform approved additive migration deployment, then inspect indexes/FKs and disabled application paths on actual MySQL.
- [ ] Admit a fresh reviewed, scoped source packet and verify admin/public behavior and unchanged NBA forecast/approval values after research-only activation.
- [ ] Record existing NFL heartbeat/research freshness, default queue latency, shared provider circuit and spend headroom immediately before a paid canary, then compare after it. No paid health check or circuit reset is required for preparation.
- [ ] Obtain approval for the actual NBA ownership transfer and paid canary. Confirm dry-run work identity immediately before inline execution; do not use a synchronous refresh job as a read-only preparation step.
- [ ] Establish who refreshes NBA source packets within 120 minutes and monitor missing topics honestly before enabling automatic reconciliation. Automation does not fetch current PDFs.
- [ ] After rollback deployment, verify new NBA admissions stopped, wait for owned attempts/leases to settle, retain accounting/history, and verify original NFL reads/writers remain healthy.

These unchecked items require release approval, a current source packet, or actual deployment evidence. Local tests cannot mark them complete.
