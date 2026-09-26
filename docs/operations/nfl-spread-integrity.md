# NFL spread integrity and chronological validation

For the complete game-forecast sequence and equations, see the
[prediction formula reference](nfl-prediction-formulas.md). The
[review ledger](nfl-prediction-review-ledger.md) preserves the September 21
diagnosis, production stage audit, open issues and next validation priorities.

## Release behavior

The local `-ats-v2` model suffix supersedes `-ats-v1`, adding the rushing spread
direction correction and removing injury-stage rounding. Published numeric
precision is unchanged; analysis uses published margins/totals and raw final
values remain in metadata. This does not claim deployment. Existing
predictions, feature snapshots, decisions, and grades are not rewritten by this
change. This is a safety release, not evidence of improved predictive accuracy.

- Custom same-game-fitted EPA is quarantined independently of the older
  `NFL_TRUE_EPA_ENABLED` switch. The default `NFL_CUSTOM_EPA_QUARANTINED=true`
  prevents both its forecast blend and generation-time EPA backfill. Metrics can
  still be collected separately. Do not clear quarantine merely to improve a
  readiness indicator. Independently trained, as-of EPA and held-out validation
  are prerequisites for a future candidate.
- Intentional quarantine is recorded as `custom_epa_quarantined`, with
  `enabled=false`, rather than pretending a required feature is missing. It does
  not create a new blanket research hold. Predictions and directional W-L
  tracking remain available.
- Rolling and opponent-adjusted current-season efficiency use an effective
  weight of `maximum_weight * n / (n + 8)`, where `n` is the smaller team's
  completed regular-season sample. Existing minimum-sample gates remain. For
  example, a maximum 35% weight becomes 7% at two games. Eight prior-equivalent
  games is a conservative policy, not a coefficient fitted to 2026 outcomes.
  These are game counts, not claims of complete play-by-play coverage.
- Large favorite margins and key-number labels no longer automatically boost
  analysis trust. `allow_unvalidated_trust_boosts` defaults to false even if old
  environment variables request a boost.
- The legacy adaptive spread correction is disabled because it reads mutable
  prediction history. Existing total correction remains unchanged; this release
  does not certify total or moneyline calibration.
- Winner confidence is labeled separately in the resource and Vue model cards.
  Spread/total decision records no longer inherit moneyline confidence.
  Heuristic signal scores remain scores, not percentages of winning a bet.

## Spread probability contract

`NflSpreadProbability` evaluates a supplied distribution of prior residuals at
the actual home sportsbook line. It reports cover, push, and loss separately;
non-push cover probability is distinct from unconditional cover probability.
American-price expected profit, when a price is supplied, is a shadow estimate,
not realized ROI. Zero edge produces no pick; insufficient samples produce null,
not a substituted moneyline probability.

At least 200 prior same-version residuals are required by the chronological
runner. Even then the result is `shadow_uncalibrated`, `calibrated=false`, and
`bet_eligible=false`. The live forecast currently has no approved residual
calibration artifact, so its cover probability remains unavailable. This does
not remove the model's directional spread pick. Existing rule-based tracking
candidates are not reclassified by this calculator.

## Read-only comparison

```bash
php artisan nfl:validate-spread-models --from-season=2021 --to-season=2026 --json
```

Add `--detailed` for per-game shadow estimates. Run on production for production
coverage. No APIs, paid research, queues, migrations, writes, or model promotion
are launched by this command. Empty eligible datasets return a failure exit code.

Only frozen `observed_pregame` feature snapshots with features available before
generation are eligible. The canonical event kickoff is authoritative, not the
legacy game-time or snapshot-time calculation. Post-kickoff snapshots,
reconstructions, missing lines, conflicting line signs, and missing canonical
starts/results are excluded. The frozen line's observation time is known; its
provider fetch freshness and closing-line status are **not** established.
Mutable `odds_data` is never a fallback.

Comparisons include:

- Market margin: a margin-error benchmark, no automatic ATS side.
- Recorded model: the actual saved pregame forecast, not today's regenerated one.
- Fixed 50/50 Elo+market: an offline benchmark from saved pregame legacy Elo
  margin and frozen line. It is also recorded in new prediction metadata, without
  influencing the active forecast. This candidate was chosen after examining
  2026 results, so those results are exploratory, not an untouched holdout.
- Validated EPA+market: explicitly missing until verified candidates exist.
  Custom EPA is never silently substituted. The pure evaluator accepts this
  variant for independently assembled as-of datasets; the production loader
  deliberately does not claim such evidence exists today.

Reports show W-L-P, no-picks, MAE, non-push Brier score and probability sample,
per-season, per-model-version and Weeks 1–4 splits, missing-variant coverage,
and common-cohort buckets. Compare common cohorts, not mismatched headline
samples. A market-only benchmark at its own line has no directional edge.

Residuals can only use games whose kickoff and result availability precede the
target forecast. Versions are not pooled. This is a walk-forward evaluation,
not an optimizer. No random split, same-game fit, automatic promotion, or tuning
to the first two weeks occurs. A future promotion requires multiple seasons of
eligible evidence, an untouched final holdout, probability calibration checks,
and prospective shadow results. Missing historical evidence cannot be repaired
by relabeling a retrospective reconstruction as observed.

## Deployment verification

Read-only production audit on September 21, 2026: the reduced-column loader
completed successfully in approximately five seconds. Of 1,390 regular-season
finals in the 2021–2026 scope, 31 qualified; 1,359 lacked the required canonical
start or result-availability evidence. None qualified for a validated EPA
candidate or the 200-residual shadow probability minimum. Thus this is not yet
a multi-season validation result.

The frozen-forecast directional record is 11–18–1 with one no-edge/no-pick.
That is distinct from the previously reviewed 11–19–1 decision record: this
audit treats an exact frozen-forecast/line tie as no-pick rather than grading a
recorded directional decision. No saved decision or grade was changed.
Market MAE was 11.452 points, recorded-model MAE
12.265, and the fixed Elo+market benchmark MAE 12.028. The smaller benchmark
did not establish superior ATS performance (10–20–1), and is not promoted.
The machine-readable audit is `outputs/nfl-spread-audit-2026-09-21.json`.

The separate [frozen spread-policy replay](nfl-frozen-spread-replay.md) evaluates
the new safeguards against archived signals, reproduces original outputs before
grading, and reports matched samples and exclusions. It is not a full current-model
regeneration or an independent validation set. Its command is
`nfl:replay-frozen-spreads --season=2026 --through-week=2`.

Deploy the scoped changes, rebuild configuration/frontend assets through the
normal release workflow, and verify the next **pregame** forecast contains the
new model suffix, quarantine reason, effective weights, and separate spread
assessment. Run the read-only command above and inspect exclusions before
interpreting performance. Do not regenerate historical picks to make the new
version appear to have a historical record. Profit/settlement tracking remains
separate from model-decision W-L.
