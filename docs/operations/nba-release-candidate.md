# NBA research release candidate

Prepared November 20, 2026, America/Chicago. This isolated candidate is based directly on the production commit `b176298f6e39b20f0f64dc5cd236af3f0331da69`, fetched by its exact SHA. It is local branch `codex/nba-research-release-candidate`; no push or deployment occurred. The source workspace and its index were not reset, staged or committed.

## Scope

- Add shared immutable source/documents/assessments, durable review work/revisions, explicit ingestion and reconciliation commands, bounded opt-in jobs, admin-only research/retry endpoints, and lazy admin panels on six non-NFL game pages.
- Add only the two reviewed additive migrations. Keep every pre-existing migration file from the deployed baseline, including the already-applied October NFL injury archive migration.
- Add false-default feature flags and preserve the production dependency manifests/locks, model calculations, NFL agents/source/catalogs, original schedules, canonical read/writer selection and existing deployment helper.
- Add a gated publisher hook and sport-specific legacy review ownership handoff. Preserve production's canonical daily AI selection/persistence and its stale-response guards.
- Retain the production `canonical_prediction_id IS NULL` legacy-reader filter. Add the NBA current-input hash guard so feature rollback cannot surface an obsolete legacy review; the existing NFL hash guard remains unchanged.
- Restrict new non-NFL research context to descriptive evidence; retain the original NFL context conversion. Preserve production's team-color UI properties, WCBB betting presentation, player/roster/MLB OpenAPI contracts and original query filters.
- Regenerate OpenAPI with only additive research/retry paths and schemas. Include source/review operations, readiness reports, redacted metadata, migration hashes and a deliberately incomplete packet template.

The current fresh packet is operational evidence outside Git. It must be revalidated for age and game identity at ingestion; no packet content, raw source PDF, credentials, environment file or customer records are committed.

## Validation on this candidate

- Shared research/review/API/dispatch/timezone/migration/source suite: **156 tests / 1,100 assertions**, passed.
- Existing NFL pipeline/cost controls, NFL admin API/retry, legacy daily AI writer, prediction resources, OpenAPI and migration guardrails: **116 tests / 1,133 assertions**, passed.
- Full `npm run quality:check` passed after generating ignored Wayfinder helpers for the new worktree.
- `npm run build` passed; six research access/presentation SSR tests passed.
- All **1,448 NFL feature tests / 8,364 assertions** passed across all 131 candidate NFL feature files, in 14 isolated batches; see [results](nba-release-nfl-test-results.json). Initial missing test key/code-version setup failures were corrected in an ignored nonsecret test environment and both affected batches passed on rerun.
- Fifteen existing NFL presentation/research SSR tests also passed.
- Full candidate Pint passed. `git diff --check` passed. The reviewed migration hashes match the recorded manifest.
- Actual MySQL 8.4.6 execution of the exact candidate migration files passed **27 checks**; see [MySQL verification](nba-rollout-mysql-validation.json). Production is MySQL 8.4.7-7; minimal key fixtures are not a production clone.

The initial validation exposed missing copied command files and placeholder NBA test hashes while assembling the isolated tree; both were corrected before the successful runs. No production-only canonical behavior was overwritten to make the tests pass.

## Ready versus pending

This candidate is ready for final code/scope review and a disabled-feature deployment decision. It is not deployed or activated. [The rollout runbook](nba-research-rollout.md) provides ordered activation and flag rollback, and [readiness](nba-release-readiness.md) documents recovery limits.

Before deployment, compare the final candidate SHA and exact migration filename set to the latest production release/ledger, inspect all staged Cloud settings, accept a recovery point and owner, and approve the release. Initial deployment keeps all shared feature flags absent/false. Production DDL completion, API/admin smoke checks and existing NFL health must be observed after that approved deployment.

Research-only activation needs a fresh reviewed two-team packet. Paid NBA ownership transfer and automatic operation remain separate decisions. Default budgets and existing NFL settings are unchanged. Do not push main as preparation: production auto-deploys it and runs all pending migrations.

## Read-only operational baseline before deployment

At approximately November 20, 10:24–10:26 p.m. Central, read-only production checks reported OpenAI circuit cooldown zero seconds; default queue two pending / one reserved with oldest age about 28 seconds; and rolling application ledger admission accounting about $1.493 against the default $5 ceiling, including conservative reservations for 20 legacy attempts with unknown measured cost. These are point-in-time ledger/capacity observations, not a billing guarantee or permission for a paid canary. Recheck before paid activation.

Existing NFL readiness had **0/11 fully ready and all 11 partial**, including one stale/unlinked research record and one held assessment; no near-kickoff blocking or stale-market condition was reported. This is a pre-existing warning baseline, not a new-feature regression. Do not modify NFL records, approvals or calibration to force it green. Initial disabled-feature release must preserve these owners/settings and verify no deterioration after deployment.

A fresh real NBA PDF packet was independently replayed through source admission and assessment using SQLite in memory. This exposed and fixed explicit-offset timestamp serialization: research publication/observation values now normalize to the configured application timezone while preserving the instant. A mixed-offset regression passed alongside the actual packet replay. See [local replay evidence](nba-source-packet-local-replay.json). This changes only new shared source admission; original NFL storage/writers remain untouched.

[Sanitized production baseline](nba-release-production-baseline.json) records the current deployment, absent shared schema/flags, bounded queue/provider accounting, pre-existing NFL readiness warnings and snapshot retention observations. No stored snapshot was created/restored, and no production table/flag was changed.

The [final exact-candidate preflight](nba-exact-candidate-preflight.json), collected November 20 at 10:29:46 p.m. Central, compared this worktree's complete migration filename set with production: exactly the two new shared migrations are pending and all five shared tables are absent. No production migration was executed. Recheck if candidate migration files or production release/ledger change.
