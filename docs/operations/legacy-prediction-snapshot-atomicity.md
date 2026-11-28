# Legacy prediction and snapshot persistence

Source qualification: 2026-11-28, private integration candidate only. Shared application code and production settings are unchanged by this guide.

`AbstractPredictionGenerator::execute` and `executeHistorical` persist their prediction, real ModelRun parent, and feature snapshot in one coherent owning database transaction. New prediction creation retains Eloquent `firstOrCreate` unique-conflict recovery; existing saves must acknowledge success. An ordinary cancelled prediction save or snapshot creation refuses the operation and rolls back its rows. The returned snapshot and its run must have matching persisted owner headers before narrative dispatch is scheduled.

For a known standalone entry, the Game row is locked before the legacy Prediction row. This aligns with the existing grader order and prevents a later Game foreign-key access from reversing that local order. Non-MLB calculation inputs and provenance remain the original calculated model; this lock does not attest a new current-source calculation. Current standalone MLB additionally retains its existing explicit Game-context/status validation and observed-capture path. Historical reconstruction and inherited MLB transactions do not gain pregame or training authority. Unknown inherited caller lock ordering remains unsupported; this is not a global deadlock guarantee.

Descriptive config/code-version metadata is staged before the newly owned persistence transaction and checked against its sport/config binding. A caller may already own an outer transaction. Existing metadata compression/resolution and inherited IO are not certified as occurring outside all SQL transactions. No global configuration is changed to stage a run.

Narrative dispatch is registered through `afterCommit` inside the owning transaction. An outer rollback suppresses it; a relation-detached cloned prediction preserves the dispatch class/key if the caller later changes the returned model. Tests use Queue::fake and Http::preventStrayRequests. They do not exercise notification transports or real AI jobs, and a failure after the database commit is not converted into a safe automatic replay claim.

## Qualified evidence

- Original malformed-clock controls: 4 failures, 2 valid positives, 50 assertions. Expanded original runtime: 7 failures and 4 positives, 102 assertions, including premature outer-transaction dispatch and a late snapshot observer exception.
- Ordinary save-cancellation controls: 3 genuine failures on the original runtime and 3 on the first prototype, each 15 assertions. These are separate epochs, not additive independent business cases.
- Corrected portable cohort: 30 passing tests / 203 assertions at 256M, including 16 focused controls, 5 existing run-cache identity controls and 9 existing MLB backend-safety controls.
- Disposable MySQL 8.4.6 after-only: actual catalog 17, disjoint serial groups 6/5/6, 139 assertions at 128M. Canonical passed/finished IDs, JUnit and the unchanged strict verifier established zero missing, duplicate, skipped or failed tests. Each lifecycle finished at schema 383 migrations / 271 tables, exact owned identity, stopped server and absent socket/PID; selected 7,048 source / 15,859 dependency / 206 asset files and synthetic environment hashes stayed unchanged. No grants or instrumentation were changed.

The first monolithic native attempt timed out at its unchanged 180-second watchdog, with no footer and interrupted schema 200/120. It remains a failed attempt with no established executed-case count. A declaration-only canonical-method filter initially selected zero because the pinned Pest filter uses printable source descriptions; that setup failure is retained. The later reviewed groups used source-title selectors proven against actual opaque discovery IDs, not reverse-inferred identities. Their separate 180-second watchdogs were not extended.

The native MLB companions used current accepted GeneratePrediction dependency `02da26018ff5e95eb68d92936ce2dff58de857cc2ab5153bc5af321483a5ea9e`. The earlier portable cohort used its prior dependency epoch. Native companions are real no-odds current/historical generator paths; they are not a separate native away-first market-matcher numeric certificate.

The native regression is automatically selected by the existing `phpunit.mysql.xml` directory entry for `tests/Integration/MySql`; no duplicate XML entry is required. Local direct proof is not hosted CI or production acceptance.

See the owning source/evidence report (private verification record retained separately) and the private adoption receipt. This closes only the reviewed exception/save-cancellation persistence boundary. Initial full-model/metadata allocation, arbitrary observer/PDO or raw-SQL intervention, other generic/period writers, all-sport lock composition, scientific approval and production rollout remain separate gates. No model defaults, calibration, probability math or approval statuses are changed.
