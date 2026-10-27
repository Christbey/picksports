# NFL evidence collection and validation gap plan

Drafted October 27, 2026. The admin evidence leaderboard extends the existing NFL signal observation and grading system. It does not change predictions, calibration weights or wager approval.

## Current coverage

Production audit at 6:46 p.m. Central on October 27, 2026, season 2026:

| Existing signal type | Distinct keys | Games with observations |
| --- | ---: | ---: |
| Reason codes | 286 | 313 |
| Matched rules | 27 | 211 |
| Pass rules | 7 | 131 |
| Risk flags | 8 | 312 |
| Combinations | 4 | 222 |

Observation totals include repeated forecasts and future scheduled games; they are not independent completed-game samples. Stored outcome grades cover 109 games for winners and 108 for spreads/totals. Exact decision settlements cover 90 games in each market. These are coverage counts, not performance claims, and change as pipelines run.

The leaderboard reads existing observations and outcome grades. It excludes unsafe, reconstructed and post-kickoff evidence; checks snapshot lineage and canonical kickoff; separates model, feature, blend, configuration, definition and direction; and counts each game once within each group. It exposes weekly outcomes, descriptive early/later-week splits, uncertainty, paired baseline improvement and identical game-sample warnings. Division/conference groups are mutually exclusive, using franchise alignment from 2002 onward and known relocation aliases; unknown/pre-2002 alignment is not guessed.

## Phase 1 — Validate the existing ledger and make gaps measurable

Priority: first. Owners: NFL ingestion/operations and analytics.

- Reconcile eligible final-game snapshots, observations, pending grades and settlements by model version and market. Explain missing spread/total grades separately from missing lines, incomplete results, unsupported markets or grading backlog.
- Check kickoff instants, feature availability, quote observation times and definition/configuration stability. Repeated model runs must not increase independent-game counts.
- Review baseline semantics: winner lift compares the stored model baseline; spread/total error lift compares the stored market line. Neither is automatically proof of isolated signal contribution.
- Expose last successful capture/materialization/grading times and explicit pending reasons in the admin view. Trace existing scheduler heartbeats before adding jobs.
- Verify database query cost and page response time with representative production counts. Add bounded caching or persisted report summaries only if measured cost requires them; avoid storing duplicate raw payloads.

Acceptance: every eligible completed-game gap has a recorded reason; a repeat grade/report run is idempotent; one game contributes no more than one observation to a signal/configuration group; admin freshness and missingness are visible.

## Phase 2 — Freeze matchup evidence before kickoff

Priority: next. Owners: NFL matchup and prediction snapshot services.

- Inventory all catalog rules with stable definition IDs, metric units, thresholds, direction (or context-only), target market, source timestamps, coverage requirements and definition version.
- Store matched, not-matched, unavailable and unsupported states alongside the existing immutable prediction snapshot/observation lineage. Preserve raw numeric values and their sample/coverage metadata in a compact evidence payload.
- Use a common pregame capture cutoff and the existing collector/materializer lifecycle. Do not recalculate current matchup pages after a game and label that output prospective.
- Include independent team EPA/success, opponent-quality comparisons, passing/rushing and position matchups, pressure/protection, quarterback identity, personnel availability and lineup changes as source coverage permits.
- Capture division/conference context, venue/rest, expected starter and first-meeting/rematch context with explicit season alignment and evidence provenance. Context-only conditions must not acquire invented pick directions.
- Define how changed conditions near kickoff are selected. Keep source revisions available for audit while selecting one predefined observation per game for evaluation.

Acceptance: replaying a completed game uses only its frozen pregame evidence; later provider corrections cannot silently change that evidence; absent and unavailable conditions remain distinguishable; capture cost fits the existing pipeline budget.

## Phase 3 — Grade evidence contributions and comparison groups

Owners: analytics and existing signal grader/report services.

- Retain existing outcome and exact-settlement grading. Add versioned grading semantics for directional evidence versus conditional model performance.
- Grade winner, spread and total separately, including pushes and no-direction cases. Do not manufacture ROI without an executable, timestamped line and price.
- For each predefined condition, compare signal-present and signal-absent games with comparable pregame market expectations, quarterback status, venue and season stage. Report control-group coverage and uncertainty.
- Compare the full model with a non-persisting ablation preview that removes only the candidate contribution while retaining identical inputs. A heuristic reason code is not itself an ablatable numeric component.
- Report winner Brier/log loss where genuine probabilities exist; use margin/total error for point forecasts. Do not imply calibrated cover/O-U probabilities where none exist.
- Group near-duplicate rules and measure partial overlap, not only identical samples. Account for repeated games across related signals and markets.

Acceptance: each number states its baseline, grading direction, sample unit and comparator; correlated rules are not counted as independent confirmations; diagnostic flags and pass rules are distinguishable from directional picks.

## Phase 4 — Confirm findings on later games

Owners: analytics and model governance.

- Predeclare discovery dates, later validation dates, candidate families, sample requirements and comparison metrics before examining validation outcomes.
- Keep model/configuration versions separate. Use expanding-window evaluation and game/week-based uncertainty estimates; inspect season and divisional/conference subgroup stability.
- Correct for testing many candidates, or use conservative shrinkage and a separate untouched confirmation period. Track how many hypotheses were searched.
- Require incremental improvement beyond the relevant baseline on later games, adequate coverage and no material subgroup regression. Sample size thresholds are planning requirements, not proof of validity.
- Use statuses such as insufficient evidence, exploratory, awaiting confirmation, confirmed improvement, no improvement and unstable. The current leaderboard deliberately never sets a signal to validated automatically.

Acceptance: a favorable historical hit rate alone cannot promote a signal; confirmation evidence is reproducible from frozen records and shows both effect size and uncertainty.

## Phase 5 — Controlled use in future picks

Owners: NFL model and admin/recommendation interfaces.

- Start with admin-only context: support, counterevidence, uncertainty, source age and the relevant earlier sample.
- Test any proposed weighting change as a shadow challenger against unchanged published forecasts.
- Promote only after the existing model approval workflow verifies later-game improvement, calibration where applicable, market-price quality and operational health.
- Keep a versioned rollback path and continue monitoring deterioration, missingness and subgroup performance.

Acceptance: a released pick explains why the side and price are supported, the strongest opposing evidence and whether the selection is a lean or approved. A leaderboard rank cannot bypass approval.

## Immediate next deliverable

Complete Phase 1 reconciliation for the current production model, then implement Phase 2 freezing for a small predefined family (overall/passing EPA and expected-QB/personnel context). Use the same observation and grade tables/services where their contracts fit; extend them with explicit state/version fields only when needed. Do not create another independent scoring pipeline or backfill prospective results from reconstructed evidence.
