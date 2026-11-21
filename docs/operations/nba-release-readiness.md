# NBA release readiness

Prepared November 20, 2026, America/Chicago. Scope: prepare an isolated candidate and evidence before deployment. No push, deployment, production migration, variable change, source ingestion or paid provider call is authorized by this report. The [rollout runbook](nba-research-rollout.md) remains the activation/rollback procedure.

## MySQL migration gate completed locally

A dedicated MySQL **8.4.6** server was initialized in a new private temporary data directory and started with `--skip-networking --mysqlx=OFF`. It used a private Unix socket and no inherited application connection configuration. The validation script loaded only Composer's autoloader and Illuminate database/schema classes; it did not bootstrap Laravel, dotenv, application providers, queues or HTTP. No existing local schema or production database was connected.

The [machine-readable result](nba-rollout-mysql-validation.json) records **27 successful checks** rerun against migration files loaded directly from `/private/tmp/picksports-nba-release-candidate`, matching the exact migration hashes in the [migration manifest](nba-rollout-migration-manifest.json):

- Both additive migrations executed successfully, installing all five tables on actual MySQL with utf8mb4 and 16 KB InnoDB pages.
- All nine foreign keys, the five-column research document unique index, due-work index, and nullable canonical/AI lineage were installed.
- A real foreign-key violation was rejected with an integrity error.
- The two migrations completed up/down/up in the disposable schema, preserving parent column definitions and sentinel rows for `sport_events`, `predictions`, `ai_generations`, `sports_game_context_reports` and `nfl_research_revisions`.
- The random disposable schema was dropped in a `finally` block. The separate temporary server was cleanly shut down after candidate verification.

Limits: production is MySQL **8.4.7-7**, so this validates the same 8.4 series rather than the identical patch/build. Parent fixtures reproduced unsigned BIGINT keys and InnoDB relationships, not a complete production clone. Production migration execution, existing full-schema compatibility and live post-deploy checks remain separate gates. Never execute down migrations in production; retain all feature tables on flag rollback.

A first sandboxed initialization hit a memory-map permission crash; an explicitly approved isolated retry outside that sandbox initialized normally. No failed attempt touched an existing data directory.

## Disabled feature and NFL invariants

Candidate review must confirm all shared global and per-sport gates default false, including both schedule/publisher flags. Initial deployment must keep them absent/false. Enabling only NBA research must not enable NBA review, shared NFL research/review, or automation. The existing NFL research, legacy daily AI writer, canonical prediction selection, schedules and queue workers remain authoritative until independently approved otherwise.

Source admission and assessment create descriptive evidence only. NBA review ownership transfer is a separate real cutover; shared review enablement suppresses the legacy NBA writer/reader. Its rollback must retain the current-input hash guard so stale legacy analysis does not reappear. Shared provider circuit and default queue still create operational dependencies even though NFL ownership is preserved.

The [activation checklist](nba-rollout-activation-checklist.md) and [migration checklist](nba-rollout-migration-checklist.md) record focused regression tests. Candidate assembly reruns relevant checks in the isolated candidate tree rather than treating dirty-workspace success as release verification; its final SHA and focused test results are recorded in the candidate evidence.

## Recovery gate: evidence versus acceptance

Read-only Cloud metadata previously reported snapshot `db-snapshot-a34493f8-7566-4cdc-81a0-d30215e9bfb6` as available for production cluster `db-a280d080-a70b-4a0d-88de-ce496ac6753f`, created November 19 at 10:35:56 p.m. Central. A fresh read-only Cloud configuration whitelist also verified `uses_scheduled_snapshots=true` and `retention_days=7`. This establishes scheduled snapshot retention, not a PITR window. Snapshot existence is evidence of an available restore input, not proof of recoverability or acceptance of its age.

Installed Cloud CLI help confirms recovery interfaces:

```text
cloud database-restore:create CLUSTER NAME --snapshot=SNAPSHOT_ID
cloud database-restore:create CLUSTER NAME --point-in-time=ISO8601
```

These are documented capability shapes only; neither command was executed. CLI support for point-in-time syntax does not establish this cluster's retention window, enabled PITR, available timestamps, restoration success or recovery time. Do not run an exploratory restore into production or use data restoration as normal feature rollback.

Before release, the authorized operator must record an accepted recovery point/data-loss window, verify that selected snapshot or PITR timestamp is available for the correct production cluster, identify who can perform and validate an isolated restore, and document estimated/tested recovery time. A newer pre-release snapshot or isolated restore drill requires its own approved infrastructure action; this preparation does not invent recovery acceptance for the user.

## Production boundary

These remain actual release-stage gates, not local check failures:

- Select and review the isolated candidate SHA and exact dependencies; compare with the current deployed SHA.
- Recheck candidate migration filenames against the current production ledger, require exactly the two additive pending names, and stop for partial tables or unexpected migrations.
- Review all staged Cloud settings. `main` pushes auto-deploy and `migrate --force` applies every pending migration, so no incidental push is safe preparation.
- Accept the recovery point/owner, then approve deployment. Verify production migration ledger, indexes/FKs, disabled paths and existing NFL health afterward.
- Revalidate fresh source packet publication/observation times and scheduled game/team identity at ingestion. A packet expires after 120 minutes from its oldest relevant source timestamp; no preparation certifies freshness indefinitely.
- Activate only research first; paid review and automatic operation retain their separate runbook gates.


## Fresh packet replay completed

The real reviewed source packet was admitted and assessed only in the isolated candidate using SQLite `:memory:`. The [sanitized replay result](nba-source-packet-local-replay.json) records hashes, explicit production-to-local fixture ID mapping, source coverage and expiry without reproducing private PDF excerpts. Original source publication and observation times were preserved; no timestamp was advanced to make evidence appear fresh.

The initial replay caught a source timezone defect: the ingestion interface accepted timezone-qualified timestamps, but raw SQL serialization retained those source wall times rather than normalizing to the application timezone. Mixed UTC observation and Eastern publication could therefore turn fresh source evidence into excluded or incorrectly timed evidence. `ResearchDocumentStore` now copies and normalizes timestamps to the application timezone before persistence, preserving their actual instants. Added a mixed-offset command regression. This is confined to the new shared research layer; original NFL ingestion and flags remain unchanged.

After that fix, the actual packet replay and ingestion command suite passed **8 tests / 74 assertions**. Both source registrations and both document admissions succeeded locally. The assessment was `partial` and `current`, availability coverage was true for both home and away, and lineup/rotation/rest-travel coverage remained false. Expiry matched November 20 at 11:45 p.m. Central (November 21 05:45 UTC). Legacy and canonical predictions, market projections and an existing approval fixture remained byte-for-byte unchanged. HTTP requests, queue pushes, AI generations and review work items were all zero. The ephemeral packet replay test was removed from the candidate after its execution; reproducible test source remains alongside private packet evidence.

The packet still requires freshness revalidation before any later approved production ingestion. Passing a local replay is not production source admission or authorization to execute a provider call.
