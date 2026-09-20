# NFL player-prop model v3

Scope: deterministic NFL prop analysis only. Other sports retain v2 prediction behavior. Daily odds collection and the NFL 24-hour quote window are unchanged. No AI research calls are added.

## Input and confidence contract

- Use reported, finalized regular-season stats before the target game, for the player's current team, from the target season or previous season only.
- If reported target-season stats exist, use that season for projection features. Prior backup production must not dilute a new season's workload. Otherwise identify the previous-season fallback explicitly.
- The minimum historical sample remains configurable. Confidence and estimation uncertainty use the actual current-season sample, not the number of past games mislabeled as this season. One observed game is not proof of an established role.
- Missing market fields are excluded, not converted into zero performances. Anytime-TD totals require both rushing and receiving TD fields. Reported zeros remain valid.
- Opponent adjustment uses the other team's offensive rows in that defense's last 12 eligible games, ordered by event date. The selected sample and league baseline are stored with the prediction.
- Uncertain own-player injury status produces a named hold. Missing depth/workload evidence limits signal strength; it is not silently treated as high-quality evidence.
- NFL historical hit rates do not add repeated confidence bonuses on top of the same observations used to estimate the mean.

## Probability and price contract

NFL v3 uses one discretized normal approximation with a market volatility floor and a sample-size estimation adjustment. Count markets condition on nonnegative outcomes; integer lines allocate explicit push mass. Stored binary Over probability is conditional on a non-push, consistent with the grader excluding pushes. The complementary Under probability has the same meaning.

This is **not fitted or empirically calibrated probability**. The UI and snapshot metadata identify that limitation. Historical role changes within a season, reliable snap/carry-share forecasts, and market-specific fitted count distributions remain modeling work; this patch does not claim those are solved by a starter flag or one game.

A recommendation must beat both the existing no-vig edge threshold and the actual offered price's break-even probability. Invalid American prices cannot qualify.

## Lifecycle and rollout

- Snapshot/disposition version: `nfl-player-prop-v3`. Old-version evaluations become unprocessed so the deterministic analyzer can regenerate upcoming games.
- Live, completed, and graded NFL props are not reanalyzed. Quote fetching also skips these games, with another kickoff check after the provider request. Historical forecasts remain frozen; their old probabilities are not relabeled as v3.
- Do not rerun past games to manufacture v3 evaluation history. Old inputs were not frozen comprehensively enough for a leakage-free retrospective claim.
- After a reviewed deployment, run `php artisan sports:analyze-player-props --sport=nfl --only-missing` to update eligible upcoming games without an odds fetch or AI narratives. Check coverage and named holds; an evaluated low-confidence/no-edge result is not a synchronization failure.
- Page cache is invalidated even when the NFL rerun produces no qualifying recommendations.
- Evaluate new settled forecasts separately using `php artisan sports:report-player-props-calibration nfl --season=2026 --model-version=nfl-player-prop-v3`. A small prospective sample cannot establish calibration. Version separation does not eliminate correlated bookmaker duplicates.

## Regression checks

`tests/Feature/BettingRecommendations/NflPropModelIntegrityTest.php` covers the prior-backup/current-starter fixture, missing stats versus zero, history eligibility, defensive side-of-ball, last-12 aggregation, negative-EV prices, pushes, availability, model versions, and snapshot protection across kickoff.

Before deployment: run the player-prop feature suites, frontend contract tests, typecheck, lint, and production build. Assess changes in shadow mode before claiming improved predictive performance.
