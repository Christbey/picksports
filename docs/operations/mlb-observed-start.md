# MLB observed start and feature availability

The new MLB time contract requires migration `2026_11_26_150000_add_mlb_observed_start_provenance.php` before deploying code that reads its columns. Its rollback deliberately refuses to erase observation evidence. Coordinate all application/worker readers and writers; this implementation has not been deployed to production.

New schedule observations need an explicit offset or `Z`. The system keeps an authoritative signed second-precision epoch and separately preserves existing venue wall fields for display compatibility. Source observation time is owned UTC with microseconds. Unknown old rows stay `legacy_time_unverified`; invalid or stale marker context is `observed_start_invalid`. Do not manufacture provenance by changing a timezone, backdating a row, adding a marker manually or inferring an epoch from an old canonical timestamp.

Stored GET/read paths never generate period features. When genuinely observed input is available, the explicit `mlb:materialize-period-features` command writes captures; use exact reviewed game IDs or a small reviewed date window. Missing/closed/stale inputs and unrepresentable legacy capture clocks cannot become certified pregame captures. Old captures and model outputs are retained rather than rewritten. An unavailable insight can therefore be an intentional admission decision, not a request to force a provider refresh.

Quote markers use actual receipt availability and normalized UTC capture/commence epochs. Weather separates the forecast target hour from the receipt time. Old unmarked records remain descriptive/unverified. A receipt after a frozen prediction capture cannot become part of that earlier prediction even if both occurred before kickoff.

`mlb:export-period-training-data --retrospective` produces explicitly retrospective descriptive material. It does not certify point-in-time training evidence. Do not promote an artifact or change an approval to bypass missing timing/source-availability evidence. The separate MLB tabular exporter/weekly-training path is under a required additional admission review; the period-path acceptance is not a certificate for that pipeline.

Configuration classification: the schema/compatible fleet is **required**; known source observations are **required for precise-time/PIT consumers**, not for retained descriptive inventory; quote/weather enrichment is **optional**, with unavailable evidence explicit. Existing `mlb_ml` shadow, period-model and weekly-training configuration defaults are **enabled**, not default-off; no activation flags were changed by this time repair. Artifact and source-provenance admissions remain mandatory independently of those flags. Do not enable or execute training/promotion as a rollout smoke test.

See the [implementation and exact proof scope](../contracts/mlb-observed-start.md) and native receipt (private verification record retained separately). The 23-additive native proof covers a frozen source copy; subsequent managed-Elo/play/provider work requires separate acceptance.


## Canonical capture clock after model preview

MLB canonical input capture keeps the previously admitted start-observation identity pinned across the real model preview. Its capture timestamp uses the final post-preview DTO instant, so crossing a second during preview does not create two incompatible capture epochs. This does not backdate input availability or refresh source authority: the publisher still refuses a changed current observation and publication at or after kickoff. Existing frozen forecasts are not rewritten.

The post-eighth private repair passed the same 14-test/66-assertion feature cohort on SQLite and MySQL 8.4.6 at 128 MiB. The native run was after-only, with 382 migration rows and 269 tables; source, dependencies, assets and synthetic environment stayed unchanged. This is a scoped clock/storage qualification, not a concurrency, deployment or production certificate.
