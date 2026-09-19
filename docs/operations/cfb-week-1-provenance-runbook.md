# CFB Week 1 provenance runbook

This runbook makes the Week 1 public prediction board reproducible. The official record is the latest canonical pregame revision published before each event starts. Legacy `cfb_predictions` rows may continue to exist during the transition, but they are not the public record and must not be used for Week 1 scoring.

## Required lifecycle

```text
Canonical event
→ immutable input snapshot
→ approved calculation release
→ successful calculation run
→ immutable published prediction revision
→ immutable final result revision
→ immutable evaluation revision
```

Every public prediction must resolve through that chain. A later pregame rerun creates and publishes a new revision while preserving the prior revision. Publishing at or after kickoff is rejected. Published, superseded, and withdrawn revisions and their markets cannot be edited.

## One-time production activation

1. Deploy the lifecycle migrations and application code.
2. Keep `PREDICTION_LIFECYCLE_CFB_CANONICAL_READS=false` during the initial shadow run.
3. Set `PREDICTION_LIFECYCLE_CFB_CANONICAL_PIPELINE=true` and ensure the Laravel Cloud scheduler is running on only one environment.
4. Link CFB games to canonical events:

   ```bash
   php artisan sports:backfill-event-identities --sport=cfb --dry-run --isolated
   php artisan sports:backfill-event-identities --sport=cfb --isolated
   ```

5. Register one approved CFB rules release. Use a new semantic version whenever the frozen configuration or deployed calculator code changes:

   ```bash
   php artisan cfb:register-calculation-release \
     --release-version=1.0.0 \
     --actor=production-release \
     --reason="Initial immutable CFB canonical release for 2026 Week 1"
   ```

Do not run `sports:backfill-canonical-predictions` to create the Week 1 official card. That command preserves legacy compatibility rows; native canonical generation creates the auditable release.

## Week 1 pregame sequence

Run after event, team, metric, injury, and odds synchronization:

```bash
php artisan cfb:generate-canonical-predictions --season=2026 --week=1
php artisan cfb:report-canonical-cutover-readiness \
  --season=2026 \
  --week=1 \
  --json \
  --fail-on-not-ready
```

The readiness command must report:

- `ready_for_cutover: true`
- `missing_safe_prediction_count: 0`
- `unsafe_published_revision_count: 0`
- `duplicate_published_event_count: 0`

Rerun generation whenever material pregame inputs change. An identical snapshot and release reuse the existing run and prediction. A changed snapshot creates a new immutable revision and atomically supersedes the former publication.

After the report passes, set `PREDICTION_LIFECYCLE_CFB_CANONICAL_READS=true`, redeploy configuration, and verify that the Week 1 API response includes `meta.prediction_source: canonical`.

## Postgame sequence

After the scoreboard synchronization has stored final scores:

```bash
php artisan cfb:evaluate-canonical-predictions --season=2026 --week=1
php artisan cfb:report-canonical-cutover-readiness \
  --season=2026 \
  --week=1 \
  --json \
  --fail-on-not-ready
```

The evaluator selects the latest canonical revision published no later than kickoff. Repeating evaluation with unchanged results is idempotent. A corrected provider result creates a new immutable result and evaluation revision instead of overwriting history.

## Live production review sequence

Run live CFB reviews in the Laravel Cloud `picksports` production environment. Do not use a local database, a development snapshot, or the obsolete Forge target to make claims about today's performance.

Use the target sports date and season/week requested by the review:

```bash
php artisan espn:sync-cfb-games-scoreboard YYYYMMDD --sync
php artisan cfb:grade-predictions --season=YYYY
php artisan cfb:evaluate-canonical-predictions --season=YYYY --week=WEEK
php artisan sports:settle-bet-decisions --sport=cfb
php artisan cfb:report-canonical-cutover-readiness \
  --season=YYYY \
  --week=WEEK \
  --json \
  --fail-on-not-ready
```

Verify that each production command finishes successfully. Then query production rows through `SportsDateWindowService` for the requested date and report:

- final, live, and scheduled game counts;
- canonical evaluation coverage and official prediction metrics;
- W-L-P for every relevant settled prediction or model-decision population, including shadow/no-bet decisions;
- qualified tracked bets where `is_bet=true` and settlement coverage;
- qualified profit from `profit_units` and counterfactual shadow profit from `metadata.shadow_profit_units`, reported as separate metrics.

`cfb:grade-predictions` is a legacy compatibility check. Its aggregate output must be labeled legacy and must not replace the official canonical Week 1 evaluation. A settled decision where `is_bet=false` still contributes to the clearly labeled shadow W-L-P record. It does not contribute to the qualified tracked-bet record, but its counterfactual profit remains available in `metadata.shadow_profit_units`. Neither `is_bet` value proves that money was placed at a sportsbook; real-money reporting requires a separate execution record.

Only after the production record is synchronized, evaluated, settled, and queried may web research add current injuries, lineup news, weather, or other explanatory context. Every added claim must have a real source URL, and web research must never invent or overwrite production scores or grades.

## Stop conditions

Do not enable canonical reads, publish a Week 1 performance report, or calculate ROI when any of these conditions is present:

- a Week 1 game lacks a canonical event;
- no single approved calculation release is selectable;
- a published prediction lacks a verified pregame snapshot or successful run;
- a publication timestamp is at or after kickoff;
- a final game has not been synchronized and evaluated;
- the betting decision lacks an immutable market snapshot;
- production commands have not completed successfully for the requested review window;
- a shadow/no-bet record is presented as the qualified tracked-bet record;
- qualified profit and counterfactual shadow profit are combined into one figure.

## Candidate 1.2.0 validation

The sample-aware candidate is opt-in through a new frozen release. Existing releases without `inputs.sample_aware_context` retain their calculator behavior. Register `cfb:register-calculation-release --release-version=1.2.0 --draft` to stage it; draft registration is not production activation. Do not approve solely because the first two weeks improve retrospectively.

New snapshots preserve null metrics, preceding-season priors, player availability evidence, and separate regular-season team windows. Recent-form and turnover terms receive current-season sample weights; total scoring blends toward the preceding season where available, otherwise the league prior. Paired FPI is preferred over comparing unlike rating families; locally derived paired ratings receive sample weights. Missing opposition metrics or quarterback conflicts withhold playable spread signals and label confidence as incomplete, including on old released forecasts at read time.

`php artisan cfb:compare-prediction-models --season=2026 --from-week=1 --to-week=2` is read-only. It compares one latest evaluated pregame revision per game with a candidate replay on the exact saved inputs. It never reconstructs missing pregame history from today's database or rewrites official forecasts/evaluations. The paired report is retrospective, not out-of-sample validation, ATS performance, or profitability. Repeat with held-out seasons only where immutable snapshots exist; missing historical coverage remains an exclusion.

The hourly pregame pipeline refreshes participating rosters once daily, then injuries synchronously, then odds, then immutable forecasts. It does not approve new calculation releases. Source failures stop the affected pipeline; unknown injury coverage does not assert health. Morning metrics remain on their existing schedule.

### Candidate 1.3.0: point-scale ratings and spread evidence

The 1.3.0 candidate uses a paired FPI difference plus the existing home-field
points as its baseline when both ratings are available. FPI is already measured
in points above an average team; the old 0.15 additive weight, and the locally
weighted power-rating fallback, compressed this signal. Elo and unadjusted scoring
margins are not added again on top of this baseline. Existing sampled context and
output regression remain. Releases without `spread.rating_baseline=fpi_points`
retain their original calculation. Reference: https://www.espn.com/college-football/fpi/

New snapshots read current-season FPI directly from the rating table, preserve its
row ID, season, snapshot week, observation time, and units, and exclude future
weeks or observations. The pregame pipeline imports FPI and recalculates metrics
once per day before generation. Empty or unmapped required imports fail the run;
failures are not cached. Per-team missing ratings still block spread promotion.

`value_signal.spread_assessment` shows the market favorite, model winning margin,
favorite's cover edge, and large-favorite agreement (both margins at least 21).
It separates the numerical projection from evidence eligibility. Missing QB
history, missing rating provenance, stale ratings/forecasts/quotes, and insufficient
samples are explicit holds. A later unrelated odds update cannot refresh an old
selected spread quote. The prediction card displays the assessment even when
there is no playable edge. `cover_probability` remains null: moneyline confidence
is not ATS confidence and no validated residual model is available yet.

The frozen-input replay is `outputs/cfb-point-scale-replay-2026-09-18.json`.
Week 1 margin MAE: official 29.3973, candidate 26.8108 (74 games).
Week 2 margin MAE: official 25.1616, candidate 23.0128 (86 games).
Miami–Stanford: official Miami by 5.6, candidate by 18.7. This does not imply a
cover of a hypothetical -20.5 line. It is retrospective, not out-of-sample ATS
validation, and the old inputs do not contain the new provenance/availability.
The candidate's `qualified` replay field only describes the legacy metric checks,
not full spread eligibility. Missing FCS coverage remains a hold, not a fabricated
rating. Calibrated ATS probabilities and a sourced confirmed-starter feed remain
outstanding. Do not auto-activate based on these two weeks.

Registration for review: `php artisan cfb:register-calculation-release --draft`.
No production release was registered, activated, or deployed as part of this change.

### Early-season spread support exception

The six-game current-season gate now has an alternative evidence path,
`early_season_prior_and_fpi`. It applies in Weeks 1–6 when at least one team is
below the current-season minimum. Both teams must have:

- At least one completed game and nonmissing scoring metrics in the current season.
- At least eight games with scoring/allowed metrics from the immediately previous
  season, plus a stored prior metric ID and observation timestamp no later than
  the forecast snapshot.
- A current-season FPI rating with source, row ID, point units, and observation
  timestamp within 14 days before the snapshot.
- Known last-observed or confirmed quarterback evidence with no unresolved hold.

The snapshot must be verified pregame and the calculation diagnostics must show
`spread_baseline=fpi_points`. The policy clears only insufficient sample and metric
reliability flags. It does not modify the sample count, calculated reliability,
model margin, confidence, minimum edge, or market/availability freshness gates.
The normal six-game path continues without requiring prior-season history.
Zero-game preseason forecasts remain ineligible through this exception.

`statistical_support.support_path` and `early_season_support` expose the evidence
counts and failure reasons. Disable the exception using
`CFB_SPREAD_VALUE_EARLY_SEASON_ENABLED=false`. The eight-game/14-day requirements
are conservative policy thresholds, not empirically optimized betting thresholds.
Regression and integration tests validate policy behavior; they do not establish
out-of-sample ATS profitability. No model was activated or bet approved by this edit.
Old snapshots missing provenance fail closed and must be replaced by genuinely
fresh pregame forecasts, not retrospectively enriched historical records.
