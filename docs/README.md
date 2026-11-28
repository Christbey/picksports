# Documentation

## Setup and engineering

- [Local development](local-development.md)
- [Engineering conventions](engineering.md)
- [Codebase reference](codebase-reference.md)
- [Scheduler reference](../SCHEDULER_REFERENCE.md)
- [Production access](operations/production-access.md)
- [Database access](operations/database-access.md)

## Operating contracts

The [curated contract index](contracts/README.md) preserves the required API idempotency and injury-observation provenance paths and current recovery contracts. Private execution logs, deployment identities, audit inventories and historical canary records are retained separately. Documentation describes source contracts; it does not establish that a particular deployment runs them.

- [API reference](api-v2-reference.md), [endpoint contracts](api-v2-endpoint-contracts.md), [OpenAPI](openapi-v2.json) and [API operating policies](api/v2/README.md)
- [Architecture decision continuity](architecture/decision-continuity.md)
- [Model lifecycle](model-lifecycle.md), [calculation reference](calculations-reference.md) and [team metrics](team-metrics-architecture.md)
- [Account access](operations/account-access.md), [finite API credentials](operations/api-token-policy.md) and [native device sessions](operations/native-device-sessions.md)
- [Billing webhooks](operations/billing-webhooks.md), [billing entitlement provenance](operations/billing-entitlements.md) and [web push](operations/web-push.md)
- [Queue workers](operations/laravel-cloud-queue-workers.md), [bounded pruning](operations/queue-pruning.md) and [remote model training](operations/remote-model-training.md)
- [NFL canonical operations](operations/nfl-canonical-operations.md), [analysis checklist](nfl-game-analysis-checklist.md), [QB provenance](operations/nfl-historical-qb-provenance.md) and [strength of schedule](operations/nfl-basic-sos.md)
- [CFB pregame pipeline](operations/cfb-pregame-pipeline.md), [kickoff evidence](cfb-kickoff-operations.md), [live projections](operations/cfb-live-betting.md) and [closing evidence](operations/cfb-postgame-closing-evidence.md)
- [CBB possession maintenance](operations/cbb-possession-metrics.md)
- [Selected accessibility fixtures](operations/accessibility-fixtures.md): synthetic24 browser controls, focus ownership and manual assistive-technology limits
- [Python CI runtime](operations/python-ci-runtime.md): exact ARM target closure and hosted-interpreter qualification limits
- [Women’s basketball play observations](operations/womens-basketball-play-observations.md): persisted identity/flags, wire aliases and acquisition/history boundaries
- [NFL research admission and cost controls](operations/nfl-research-cost-controls.md): estimated budgets, manual force boundaries and read-only accounting
- [MLB observed starts](operations/mlb-observed-start.md), [output/period boundaries](mlb-output-and-period-registration-boundaries.md) and [artifact storage](operations/ml-artifact-storage.md)
- [NBA research rollout](operations/nba-research-rollout.md), [structured adapter](operations/nba-structured-research-adapter.md) and [release preparation](operations/nba-adapter-release-candidate-2026-11-21.md)
- [Descriptive team trends](operations/descriptive-team-trends.md), [player-prop corrections](operations/player-prop-corrections.md), [dashboard health](operations/dashboard-health-status.md) and [AI readiness](operations/ai-readiness-review.md)

## Use current source and verified targets

Command signatures, routes, request classes, lockfiles, configuration and tests resolve implementation disagreements. Source review does not prove live connectivity, worker liveness, restore readiness or model approval. Accessing production does not authorize deployment, provider refreshes, historical backfills or notifications.

Keep historical evidence private and dated. Do not copy old deployment IDs, canary game IDs, raw credentials, customer data or machine-specific paths into operating instructions. Retain an explicit successor for any normative contract when retiring an old report; do not remove an entire documentation directory simply because it also contains private proof.
