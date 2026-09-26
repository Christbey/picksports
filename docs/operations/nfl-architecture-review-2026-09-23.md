# NFL prediction architecture review — September 23, 2026

## Conclusion

A model already exists and is running in production. The problem is not absence of modeling infrastructure. It is fragmented ownership of forecasts, deployment drift, and incomplete validation of the formulas and evidence used by each path.

Production currently has:
- A public-facing legacy Elo/rules pipeline with many sequential adjustments.
- A separate canonical rules calculator publishing immutable forecasts for the same games.
- A research process that previews the legacy calculator with additional availability evidence and can supply the displayed forecast.
- Five trained tabular ML artifacts, none promoted, with tabular shadow inference and weekly training disabled.
- New local historical-line and matchup-analysis services that are not deployed.

The right next step is consolidation around one immutable forecast contract, not another independent model and not a blind canonical-reader switch.

## Scope and evidence

Read-only production queries at **17:49 and 17:53 CDT on September 23, 2026** examined runtime flags, source hashes, releases, artifacts, all 16 Week 3 predictions and latest research revisions, and recent NFL command heartbeats. No production settings, forecasts, releases, or paid research were changed by this review.

Evidence: [production audit export](../../outputs/nfl-architecture-production-audit-2026-09-23.json).

Source review covered pregame generation, canonical snapshots/calculation/publication, research, model artifact inference, API/Vue forecast selection, historical evidence, grading, and scheduled orchestration. This is not an exhaustive review of player-prop mathematics, in-game trading, every provider implementation, or every infrastructure setting. No new accuracy backtest or load test was performed.

The worktree already contained substantial uncommitted changes. This audit preserves them. A local source review is explicitly not proof that its changes are deployed. Frontend source hash equality is not independent verification of the compiled production browser bundle.

## Actual architecture

~~~text
ESPN / nflverse / odds / injuries / depth / weather / official research
                         |
                   Eloquent storage
                         |
         +---------------+------------------+
         |               |                  |
   Legacy forecast    Canonical rules    Research assessment
   Elo + adjustments  Frozen snapshot    EvidencePacket
         |            Different formula  + legacy preview
   nfl_predictions         |                  |
   Feature snapshots  predictions +       research revisions
   Candidate decisions markets/releases   + availability overlay
         |               |                  |
         +---- API ----+ |                  |
              |        | |                  |
      legacy reads ON  canonical reads OFF |
              +----------------------------+
                         |
           Board may prefer research forecast

Tabular ML artifacts -> optional shadow inference -> comparison/settlement
                        OFF in production; not current public forecast

Local historical ATS/ML cohorts + matchup catalog -> descriptive evidence
                        NOT deployed; zero prediction weight
~~~

### Legacy forecast

Entry point: [GeneratePredictionsCommand](../../app/Console/Commands/NFL/GeneratePredictionsCommand.php).
Calculator: [GeneratePredictionFromHistoricalElo](../../app/Actions/NFL/GeneratePredictionFromHistoricalElo.php).

The local class has 5,762 lines. Its calculation sequence is:

1. Prior-date Elo plus home-field adjustment; initial margin, total and win probability.
2. EPA blend or preseason fallback.
3. Rolling efficiency.
4. Opponent-adjusted efficiency.
5. Total/scoring environment.
6. QB form.
7. Offensive/defensive line matchup.
8. Situational context.
9. Weather.
10. Depth-chart injuries.
11. Adaptive point calibration.
12. Player-position context.
13. Market blend.
14. Win-probability calibration and automated tweaks.
15. Analysis/trust/approval logic, feature snapshots and candidate decision recording.

Not every enabled-looking section changes the numeric forecast. Position-grade context is not an independent forecast blend. Conversely, the market is already part of the prediction, not merely a comparison displayed afterward.

At the production capture, all 16 Week 3 legacy rows reported EPA, QB, line matchup, total environment, context, injury, market and calibration stages applied. Rolling and opponent-adjusted efficiency were **disabled**. Actual-weather adjustment reported applied for 2/16; a false value alone does not establish missing weather, since indoor/neutral weather can legitimately produce no adjustment.

Production uses model version **nfl-historical-elo-v2-career-regular-v2**. Local changes use the additional **ats-v2** suffix.

### Canonical forecast

Entry: [GenerateCanonicalPrediction](../../app/Actions/NFL/GenerateCanonicalPrediction.php).
Input builder: [NflInputSnapshotBuilder](../../app/Services/NFL/Predictions/NflInputSnapshotBuilder.php).
Calculator: [CanonicalFootballCalculator](../../app/Services/Predictions/Football/CanonicalFootballCalculator.php).

This is not just the legacy forecast in safer storage. It is another formula:
- Elo margin, scoring averages and a sample-size blend.
- Power/recent-form/turnover/fatigue/injury-rating adjustments.
- Flat injury counts and output regression.
- Logistic margin-to-win-probability conversion.

The snapshot does not supply the same detailed EPA/QB/trench/weather features as the legacy pipeline. Canonical pregame quote requirements are safety/market evidence, not the legacy numeric market blend.

Production has an approved **rules release 1.1.0** and a current published canonical calculation for all 16 Week 3 games. These forecasts have no model artifact ID. Thus, “canonical release exists” does not mean “trained ML model is live.”

The immutable storage infrastructure is valuable: input snapshots, output hashes, release versions, publication checks, superseded revisions and evaluations already exist.

### Trained ML

[NflFullHistoricalShadowInferenceService](../../app/Services/NFL/NflFullHistoricalShadowInferenceService.php) evaluates tabular artifacts when enabled and stores comparison metadata. Its return explicitly says **apply_to_live_output=false** and **active_source=baseline**, even for a promoted artifact on this path.

Production artifact inventory:
- Four challenger artifacts.
- One promotion-eligible artifact.
- Zero promoted artifacts.
- Tabular shadow enabled: false.
- Weekly training enabled: false.
- Automatic promotion enabled: false.

The latest artifact was created July 29 local time. It has two walk-forward windows against a minimum of three, with unmet market-specific live-shadow gates. Its historical market baseline is unavailable in the reported holdout. A favorable offline comparison with the old internal model is not sufficient evidence of ATS advantage.

A separate full-historical profile fallback is configured on, but the queried NFL inventory contains only tabular bundles; this is not evidence of active profile inference.

### Research and UI

[ResearchPipeline](../../app/Services/NFL/Research/ResearchPipeline.php) builds an EvidencePacket, attaches research_availability to the game, and calls the legacy calculator's preview method. It stores revised outputs separately.

[NflPredictionBoardContext](../../app/Services/NFL/NflPredictionBoardContext.php) can choose this revised forecast when its comparison/evidence checks pass. [nflBoardPresentation](../../resources/js/lib/nflBoardPresentation.ts) prefers that board forecast over saved prediction fields.

Therefore, even “legacy reads” can mean **saved legacy forecast or research-preview forecast**. A source label exists, but that does not make their lifecycle, decisions and grading automatically identical.

The production research capture had **12 passes and 4 holds**, not 16 approved bets and not 16 missing predictions. These are stored assessment statuses at their assessment times; they are not a claim that every source was still current at audit time. Houston–Indianapolis included an attempt-limit block, changed candidate and stale/incomplete research. The other holds were Seattle–Washington, Tennessee–New York Giants and Philadelphia–Chicago.

Research source files and the board selection helper match production hashes. This is deployed behavior, not only local code.

## Prioritized findings

### P1 — Multiple forecast authorities disagree

Production canonical generation is on, canonical reads are off. Both calculators run, but their contracts do not describe the same statistical model.

Comparison from the same generation cycle, expressed consistently as projected winning margin, **not sportsbook handicaps**:

| Game | Saved legacy forecast | Canonical forecast |
| --- | --- | --- |
| Houston at Indianapolis | Houston by 1.6 | Indianapolis by 0.2 |
| Las Vegas at New Orleans | New Orleans by 0.9 | Las Vegas by 3.0 |
| New York Jets at Detroit | Detroit by 9.3 | Detroit by 1.4 |
| LA Chargers at Buffalo | Buffalo by 10.0 | Buffalo by 11.8 |
| LA Rams at Denver | Even | LA Rams by 2.0 |

Totals also differ: Carolina–Cleveland is 39.4 legacy versus 51.1 canonical.

This is not proof either model is better. It proves a reader-flag switch changes the model, rather than merely changing persistence. Do not switch readers as a cosmetic migration.

**Required:** declare one official producer and revision for each game/market; put that producer behind the existing immutable lifecycle; label all alternatives as challengers.

### P1 — Local remediation and production are materially different

Production lacks these local source files:
- NflTeamHistory.
- NflHistoricalMarketEvidence.
- NflMatchupSignalService.
- CanonicalPredictionPresentationService.

The legacy generator and API controller hashes differ. The local EPA quarantine, rushing correction and other ats-v2 changes must not be described as production fixes. Production still reports EPA and adaptive spread calibration applied.

The new unit/history analysis visible in the local preview is not evidence that it feeds the production prediction. Some of it intentionally should not: the user requested a non-influencing evidence layer.

**Required:** a reviewed release manifest with code/config/data migrations, targeted tests, replay results, deployment verification and rollback. Do not deploy the entire dirty worktree indiscriminately.

### P1 — Availability and research use different input paths

Scheduled legacy generation does not build the research EvidencePacket itself. The research preview attaches an additional availability overlay. Canonical snapshots use their own injury query and count-based adjustments.

The deployed canonical calculator does not recognize raw “IR”/“injured reserve” as an out count: its counter checks out/doubtful and questionable/day-to-day strings. Its builder lowercases statuses but does not normalize those reserve statuses. Its count penalty also does not distinguish starting QB from a reserve player. This is a concrete cutover blocker, not a claim that every current injury was missed.

The existing QuarterbackAvailability service has stronger timestamp/reserve handling and is already deployed. Reuse its normalized evidence rather than building another incompatible interpretation.

**Required:** one game-scoped availability snapshot, including verified starter identity and replacement state, consumed by the official forecast and attached to research. Narrative alone must not invent a quantitative adjustment.

### P1 — Calibration history is not a clean model-version experiment

Adaptive helpers query mutable historical nfl_predictions. The inspected point-calibration query has no same-model-version or frozen-pregame-snapshot restriction. Past game date is necessary but insufficient to establish that a saved prediction is an original pregame observation.

Local code disables the legacy spread-bias adjustment by default; total/winner/regime concerns remain. Production still shows a nonzero spread adjustment.

The custom EPA calculator has a baseline lookup but falls back to same-game expected-points buckets built from later scoring within that completed game. Its next-play/scoring scans do not stop at a half boundary. This is not equivalent to an independently trained expected-points model. Using a completed prior game is not automatically future leakage into a later matchup; the issue is the feature definition and validation. Do not equate non-null EPA coverage with trustworthy model quality.

**Required:** versioned calibration artifacts trained on eligible frozen observations, explicit EPA provenance, and chronological held-out evaluation before any formula promotion.

### P2 — Research can change the visible forecast without owning the released pick

Legacy candidate decisions are recorded from the saved legacy prediction and its feature snapshot. Research stores a separate revised forecast. The board can display the latter, while grading/settlement follow different records.

This creates an explainability and performance-reporting risk even if each subsystem works as designed: “the pick I saw” needs an immutable ID, not an inference from whichever table is newest.

**Required:** either research annotates the official forecast without replacing numbers, or a numerical revision goes through the same publication/decision lifecycle. Preserve the user's requested separation between prediction and supporting trends.

### P2 — Readiness messaging overstates its horizon

RunPregamePipelineCommand accepts an eight-day horizon but calls research-readiness with **min(2, daysForward)**. Its success text then claims “full [daysForward]-day readiness verification.”

That statement exceeds the research verification performed. It also explains how a green near-term check can coexist with research problems elsewhere on the displayed slate.

The command refreshes odds and generates forecasts; injuries, depth charts, weather and research ingestion run independently. Success is not an atomic guarantee that all stages used the same source versions.

**Required:** report each checked horizon explicitly and publish per-game source versions, freshness and eligibility. Retain bounded per-game failure handling rather than blocking every other game unnecessarily.

### P2 — Descriptive trends are not an independently validated predictive layer

NflHistoricalMarketEvidence deliberately has affects_prediction=false. It uses verified source closing-line conventions, excludes conflicting/missing lines, and shows both teams plus team/coach/QB cohorts.

NflMatchupSignalService has predictive_weight=0. It covers implemented offense/defense EPA, success, explosiveness and situational splits, while marking unsupported charting requirements explicitly.

Its league-ranking rules require all 32 teams with at least three qualifying games. That means current-season top/bottom rankings cannot qualify before Week 3 kickoff from only two completed regular-season games. Previous-season context is a separate window, not a hidden fallback.

Trench comparisons in the legacy model are sack/rushing proxies, not charted pressure or blocking-win rates. The inspected matchup catalog does not establish a dedicated calibrated special-teams prediction component. Points scored can include special-teams scores without measuring special-teams quality separately.

**Required:** deploy the evidence layer only after its own review; show sample/window/source for both sides. Do not turn overlapping trend matches into additive “confidence votes.”

## Grading and validation

Different outcomes already have different implementations:
- Legacy grading: outright winner correctness and margin/total error on nfl_predictions.
- Canonical grading: latest eligible pregame canonical revision and recorded event result.
- Decision settlements: frozen selected line/price and decision identity.
- Signal grading: outcome and settlement evidence, bounded incremental work.
- Historical cohorts: reconstructed line/result situations, not original model picks.

These must remain explicitly separated in reporting. Model ATS W–L can be tracked without is_bet=true. Wager profit/ROI requires recorded wager assumptions; it should not be inferred from all model decisions.

Existing historical audit files report about 50% reconstructed ATS results, not proof of a durable edge. Their corrected market signs, excluded default-Elo samples and retrospective provenance must remain intact. This review did not rerun or independently reproduce those historical totals.

## Operational findings

Latest observed heartbeats for odds, injuries, metrics, Elo, props sync, legacy grading, canonical evaluation and signal grading were successful. These checks show activity, not complete game coverage.

Examples from the capture:
- Signal grading: approximately 9.6 seconds for the latest bounded command, not the old full-history 18-minute run.
- Team metrics: approximately 13.5 seconds.
- Player-prop synchronization: approximately 98 seconds.
- Research revision batch: approximately 193 seconds.
- Research ingestion batch: approximately 52 seconds.

Two AI-related scheduled paths still exist: research revisions and sports:ai-daily-predictions. Both have recent successful heartbeats. That is evidence of two execution paths, not proof both made paid calls in those runs. Cost/accounting should trace request IDs and usage before declaring duplicate charges.

Retain the current bounded scheduling and improve dependency identity before trying to solve this by increasing workers or adding more schedules. Database query/profile measurements are still needed before claiming a performance bottleneck in a specific query.

## Recommended consolidation sequence

1. **Inventory and freeze the contract.** Explicit producer, model version, feature version, market quote ID, research revision ID, cutoff, generated-at and forecast revision in every prediction response and grade.
2. **Validate the pending local integrity fixes.** Review the release diff, reproduce frozen historical outputs where possible, keep unavailable samples excluded, and separate code deployment from any historical data repair.
3. **Reuse canonical persistence for one selected model.** Do not confuse choosing immutable storage with choosing today's simplified canonical formula. Choose the statistical producer using evaluation, not implementation convenience.
4. **Centralize inputs.** One Eloquent-backed feature/availability snapshot per game; bounded eager-loaded history reads; explicit missing values and sample windows; pure calculation methods over that snapshot.
5. **Keep contextual evidence alongside it.** ATS/ML historical cohorts, coach/QB/division, offense/defense/special teams and cited research remain explanatory until independently validated for predictive use.
6. **Restore shadow evaluation deliberately.** One selected challenger/cohort, budgeted execution, frozen features and chronological comparisons against the official model and market. Do not bypass failed promotion gates.
7. **Unify grading and UI identity.** Displayed winner/margin/probability must identify the exact frozen revision being scored. Track model W–L separately from actual wagers and profit.
8. **Verify production after release.** Check code/config hashes, per-game completeness, deterministic replay, endpoint payloads and browser source labels. A successful deploy or passing healthcheck alone does not satisfy this.

A useful acceptance criterion: for any game, one response can explain “this is the official forecast, these exact inputs produced it, this evidence supports/opposes it, this is why it changed, and this exact revision will be graded.”

## Tests run during this review

Command used testing environment, in-memory SQLite, array cache/session and synchronous test queue:

~~~sh
php artisan test --compact \
  tests/Unit/NFL/NflPregamePipelineRunnerTest.php \
  tests/Feature/Predictions/CanonicalFootballLifecycleTest.php \
  tests/Feature/NFL/NflCanonicalMarketLifecycleTest.php \
  tests/Feature/NFL/NflTeamHistoryContractTest.php
~~~

**43 tests passed, 338 assertions, 3.65 seconds.**

This verifies the tested local contracts. It does not prove production code parity, a complete system test, predictive accuracy, or profitability.
