# NFL prediction review ledger

Recorded September 21, 2026 at the user's request to retain the diagnosis before
further changes. This is project memory, not a claim of cross-task personal
memory or completed remediation. Read this alongside the
[formula reference](nfl-prediction-formulas.md) before modifying the model.

## Findings to preserve

| Finding | Evidence | Status / next verification |
| --- | --- | --- |
| Rushing matchup direction | Legacy `homeOffRushYPA-awayDefRushYPAAllowed` penalized facing a weaker defense. | Corrected locally in `ats-v2` to offense plus opponent weakness, with monotonic/symmetry/missing-data tests. Legacy total formula retained; not deployed. |
| Context layer hurt this sample | After-line checkpoint 14-15-1 plus one no-pick became 10-20-1 after situational context; margin MAE rose 12.849 to 13.110. | Observed on 31 games, not proof of universal harm. Shadow-test removal and individual overlapping components. |
| Weak base forecast | Elo checkpoint 10-20-1, MAE 12.605. Final model MAE 12.265 versus market 11.452. | Validate a simple baseline; fewer layers alone do not establish accuracy. |
| Custom EPA quality | EP state values fitted using future score changes inside the same game; no half boundary in those scans. | Local forecast quarantine implemented; no deployment asserted. Independent feature/held-out validation needed. |
| Rolling/opponent efficiency inactive | Both disabled in all 31 audited production forecasts. | Do not attribute a performance improvement to changing weights of inactive layers. |
| Conflicting probability transformations | Initial Elo probability repeatedly replaced by `1/(1+exp(-margin/7))`, then recalibrated/shrunk. | Open. Separate winner calibration and ATS calibration; do not call trust a probability. |
| Mutable calibration history | Adaptive margin/total/winner/regime helpers read historical `Prediction` rows, not exclusively frozen same-version observations. | Local margin correction disabled; total/winner/regime concerns remain open. |
| Correlated inputs / overlap | H2H, division, conference and same-week records can overlap; injuries may already affect predictive ratings; weather proxy precedes actual weather. | Risk requiring ablation, not established causality from two weeks. |
| Market anchoring is not a cure | Away from caps, final edge equals model weight times pre-market edge. | Can reduce margin error without correcting a bad directional pick. |
| Market input is not computed consensus | Extractor chooses first matching spread and first total in bookmaker iteration. | Document actual behavior; verify desired quote-selection contract before changes. |
| Displayed evidence is not always an input | Position-grade comparisons and prior-season pedigree are contextual, not direct forecast blends. | Preserve that distinction in UI and explanations. |
| Replay gaps / rounding | Two Week 1 games excluded from the current counterfactual. | Do not rewrite originals or weaken reproduction checks to fill the record. |
| Historical archive spread direction | All-history audit found 4,625 nonzero nflverse sportsbook payloads with the opposite sign from the correct handicap. | Importer signs/timezone fixed locally; legacy reimports blocked. Read-only repair planner verified scope; production data repair remains pending. |
| Calibration same-day leakage | Old `CalibrateSpreadCommand` used date <= game date; same-day Elo history is postgame. | Fixed locally: strict prior-date cutoff, explicit missing samples, forecast caps/rounding, bulk Elo loading and bounded input grid. Not deployed or independently validated. |
| Elo tie handling | Old shared updater treated tied scores as home actual 0 / away actual 1. | Fixed locally to 0.5 each; missing final scores skipped. Historical rating drift still requires a separately reviewed rebuild. |
| Default-only historical baseline | All 1,024 reconstructed 2017–2020 snapshots use Elo 1500 for both teams. | Excluded from meaningful stored-Elo comparisons; not proof of trained baseline accuracy. |

## All-history follow-up

See [local integrity remediation and production repair boundary](nfl-baseline-integrity-remediation.md)
for fixes and the read-only archive correction plan. Historical audit evidence
below is preserved; it is not a claim that production has been repaired.

The [2009–2026 baseline audit](nfl-all-history-baseline-audit.md) inspected 4,661
completed regular/postseason games. On 1,343 matched 2021–2025 games with both
prior Elo ratings, stored baseline ATS was 653–643–33 plus 14 no-picks (50.4%);
the archived full-model reconstruction was 637–663–32 plus 11 no-picks (49.0%).
These are not observed original forecasts. A separately labeled chronological
simulation covered earlier years. A smaller fitted points/Elo factor improved
MAE but worsened 2024–2025 ATS; no new coefficient was promoted. All production
work was read-only. The all-history document preserves source conventions,
sample exclusions, reproduction instructions and season-by-season results.

## Production stage audit: first two weeks

Scope: the 31 completed regular-season games in the September 21 audit; 16 in
Week 1 and 15 in Week 2. Snapshot IDs and frozen handicaps are in
[the replay export](../../outputs/nfl-frozen-spread-replay-2026-09-21.json).
Intermediate values were read from those production snapshots. Missing or inactive
stage outputs carried the preceding margin forward. Hypothetical intermediate
ATS sides used margins rounded to one decimal against the same frozen handicap;
MAE used recorded intermediate precision. These were not additional published
picks, wagers, isolated-feature causal estimates, or a new-model backtest.

| Checkpoint | W-L-P | No-picks | Margin MAE |
| --- | --- | --- | --- |
| Elo | 10-20-1 | 0 | 12.605 |
| EPA | 11-19-1 | 0 | 12.654 |
| Preseason fallback | 13-17-1 | 0 | 12.760 |
| Rolling efficiency | 13-17-1 | 0 | 12.760 |
| Opponent adjustment | 13-17-1 | 0 | 12.760 |
| QB | 14-16-1 | 0 | 12.760 |
| Line matchup | 14-15-1 | 1 | 12.849 |
| Situational context | 10-20-1 | 0 | 13.110 |
| Injuries | 12-18-1 | 0 | 13.158 |
| Spread calibration | 11-18-1 | 1 | 13.173 |
| Market blend | 11-18-1 | 1 | 12.264 |

The market-stage MAE is from its two-decimal metadata; published one-decimal
forecasts have MAE 12.265. A change in intermediate W-L is not proof of improved
probability calibration. Context was the largest observed pre-market increase
in mean absolute margin error (+0.260 points/game before rounding).

Applied-stage counts: custom EPA 16, preseason fallback 15, rolling 0, opponent
adjustment 0, QB 26, actual-weather nonzero adjustments 6; line, context,
injuries, total environment, market blend and calibration reported applied in
all 31. Position-grade context reported applied in all 31 without a direct blend.

## Counterfactual result and deployment boundary

The [frozen-signal replay](nfl-frozen-spread-replay.md) tested the local spread
safeguards while preserving other historical inputs/switches. It recovered
29/31 games. Matched original record: 11-16-1 plus one no-pick. Revised record:
12-16-1. Only Raiders +6.5 at Chargers changed, from zero-edge no-pick to a win.
Two original losses were excluded: Dolphins at Raiders (rounded metadata failed
original-output reproduction), Broncos at Chiefs (missing pregame fallback
ratings). Their exclusion must not be presented as improved accuracy.

This is a retrospective diagnostic, not an independent holdout, not an exact
full-current-model regeneration, and not a basis for automatic promotion.
The replay and safeguards were local; read-only production audits did not deploy
them or overwrite any historical forecast. The preceding code verification ran
528 tests / 2,829 assertions. Passing tests prove tested contracts, not accuracy.

## Agreed priorities for subsequent work

First-batch continuation: local `ats-v2` corrects rushing spread direction,
removes injury-stage rounding, retains raw final values and aligns analysis with
published one-decimal margins/totals. Verification passed 540 tests / 2,868
assertions across NFL feature tests, QB summary unit tests and canonical football
lifecycle tests. Pint and `git diff --check` passed. No deployment or historical
rewrites occurred. The [isolated shadow results](nfl-frozen-spread-replay.md#isolated-formula-shadows-local-policy-v2)
show one added winning selection for batch v2, not a solved accuracy problem.
Historical-context removal remains diagnostic only.

1. Local rushing correction and injury-stage precision fix implemented; validate before deployment.
2. Historical-context removal shadow completed; retain venue/rest/coaching and evaluate individual parts on a larger chronological sample before promotion.
3. Establish a credible baseline on chronological held-out data before adding weight.
4. Use distinct final winner and ATS calibration contracts with frozen inputs.
5. Promote additional signals only after demonstrated incremental value.

Do not silently implement these while responding to a documentation/review-only
request. Preserve unrelated working-tree changes. Verify deployment explicitly
when the user asks to deploy; do not infer that a local fix is already live.
