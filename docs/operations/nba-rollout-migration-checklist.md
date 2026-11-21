# NBA migration and release checklist

Verified locally November 20, 2026. This checklist supports the [NBA rollout runbook](nba-research-rollout.md); it does not authorize deployment or production writes. No push, deployment, production DDL, or production setting change was performed by this checklist pass.

## Completed

- [x] Read repository engineering and production access guidance. Confirmed tests use SQLite `:memory:` with explicit `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`; no cached configuration was present.
- [x] Reviewed both migration `up()` methods: five new tables only; no alteration of existing tables, data backfill, trigger, or provider call. Foreign keys use nullable canonical lineage where legacy forecasts may lack canonical IDs.
- [x] Verified disposable installation, indexes, parent links, nullable lineage, dispatch timestamp, and down/up preservation of existing NFL report/revision values, NFL table shape and admission settings.
- [x] Re-ran migration architecture and guardrail tests plus NFL compatibility and NBA review rollback freshness: **26 tests / 111 assertions passed**.
- [x] Focused Pint passed for both rollout migrations and migration preservation test. `php -l scripts/nba-rollout-preflight.php` passed.
- [x] Captured the [reviewed migration SHA-256 manifest](nba-rollout-migration-manifest.json). Recompute before release; it is not a release approval.
- [x] Compared local filenames with the [previous read-only production preflight](nba-rollout-preflight-2026-11-20.json): production had no deployed pending migrations, and candidate pending set was exactly the two rollout names. This pass did not repeat that live query.
- [x] Confirmed the recorded production metadata has MySQL 8.4.7-7, 16 KB InnoDB pages, and unsigned BIGINT parent keys for `sport_events`, `predictions`, `ai_generations`, and `sports_game_context_reports`.
- [x] Confirmed operational rollback keeps shared tables and evidence; destructive `down()` testing occurs only in disposable SQLite.

Command executed locally:

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: bash scripts/php85.sh artisan test --compact \
  tests/Feature/Research/NbaRolloutMigrationTest.php \
  tests/Architecture/MigrationSafetyTest.php \
  tests/Feature/Database/MigrationGuardrailRepairsTest.php \
  tests/Feature/Research/NflResearchCompatibilityTest.php \
  tests/Feature/NBA/LegacyAiReviewFreshnessTest.php
```

## Release scope findings

At inspection local HEAD was `a933c87f95bee7b962ce4209289ca4a1e37a5666`; the recorded deployed Cloud SHA was `b176298f6e39b20f0f64dc5cd236af3f0331da69`. These are different baselines. The shared workspace has extensive unrelated changes. A clean working tree or local HEAD cannot be treated as the candidate release.

Three migration files were untracked locally: the two rollout migrations and `2026_10_15_203917_add_payload_archive_to_nfl_player_injury_snapshots_table.php`. The existing production preflight's full filename comparison reported only the two rollout migrations pending, so the historical migration was already represented in production's ledger at that observation. Preserve its identity; do not rename it or blindly package every untracked file. Determine its ownership in the approved release scope rather than editing or deleting it here.

## Required before production deployment

- [ ] Select a reviewed candidate SHA and explicit file/hunk scope; compare it with the actual current deployed SHA. Include required dependencies and exclude unrelated unfinished work.
- [ ] Recompute both migration hashes and compare with the manifest. If changed, rerun relevant tests and update review evidence.
- [ ] Verify a recoverable backup/PITR point, retention and authorized recovery owner. The root agent subsequently verified an available Cloud snapshot, recorded in the main runbook; restore testing, PITR, acceptable recovery age and operator ownership remain open.
- [ ] Run the read-only preflight against that exact candidate's complete migration filename list; require exactly the two expected pending rollout names and no partially created shared tables.
- [ ] Inspect all staged Cloud settings and configured branch/deploy command. Push-to-deploy is enabled for `main`; its configured `migrate --force` applies every pending migration.
- [ ] Obtain release approval before pushing or deploying. Retain false/absent shared flags for the schema/code deployment.
- [ ] Execute and verify production DDL on Cloud only after approval. SQLite tests and MySQL metadata compatibility are not an actual MySQL DDL execution result.
- [ ] After release, verify both migration ledger rows, all five tables, expected FKs/indexes, stable existing NFL evidence, disabled feature paths, API health and worker/scheduler heartbeat.

If production has a shared table without its migration ledger entry, stop and reconcile the partial MySQL DDL. Do not rerun blindly, drop tables, restore the database as feature rollback, or run `migrate:rollback`. Follow the runbook's flag rollback on a compatible release.
