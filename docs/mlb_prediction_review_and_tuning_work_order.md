# MLB Prediction Review and Tuning Work Order

Last updated: 2026-06-18

## Purpose

This document is the single source of truth for reviewing and tuning the MLB prediction system. The first goal is measurement and audit, not changing production prediction logic. MLB should eventually show fewer picks, with stronger trust requirements around clean timestamps, fresh odds, no-vig edge, historical calibration, and consistent bet/lean/live labels.

## Executive Summary

The MLB page currently has two separate decision systems on the same screen:

- The MLB Slate Decision Board uses `MlbBettingSignalService` through the API v2 signals endpoint.
- The lower MLB Predictions list uses the generic `SportPredictions` and `UnifiedPredictionCard` flow through the API v2 predictions endpoint.

That split explains why the sample can show `1 bets` in the Slate Decision Board and `0 bets` in the Predictions section. The Slate board counts selective MLB bet-filter candidates from `recommended_bets`. The Predictions section counts recommendations using generic prediction fields such as `betting_value`, `betting_value_summary`, `prediction_analysis.bet_classification`, and `ai_analysis.bet_classification`. MLB v2 predictions do not currently expose the Slate board's canonical recommendation candidate on each prediction row.

The safest next step is to add a canonical MLB recommendation payload at the backend/resource layer, then make both the Slate board and prediction cards read the same object. Do not tune model weights until the recommendation contract and historical snapshot/backtest path are clean.

## Current Architecture

### Prediction Generation

Primary files:

- `app/Actions/MLB/GeneratePrediction.php`
- `app/Console/Commands/MLB/GeneratePredictionsCommand.php`
- `app/Console/Commands/MLB/BackfillHistoricalPredictionsCommand.php`
- `app/Actions/MLB/GradePredictions.php`
- `app/Console/Commands/MLB/ReportCalibrationCommand.php`

MLB predictions are generated from:

- Team Elo
- Pitcher Elo with probable-pitcher fallback
- Team metrics
- Dynamic early-season team/pitcher weighting
- Historical context
- Situational context
- Injury and probable-pitcher availability
- Park-factor total adjustment
- Actual weather total adjustment
- Market spread and market total context where available

The active model version fields are stored on `mlb_predictions`:

- `model_version`
- `feature_version`
- `blend_version`
- `model_metadata`

Feature snapshots are recorded through `PredictionFeatureSnapshotRecorder`. Evaluation rows are recorded through `PredictionEvaluationRecorder`.

### Data Feeds

Primary feed and sync files:

- ESPN games, teams, players, details, plays, stats, injuries:
  - `app/Actions/ESPN/MLB/*`
  - `app/Jobs/ESPN/MLB/*`
  - `app/Console/Commands/ESPN/MLB/*`
- Odds API current odds and player props:
  - `app/Actions/OddsApi/MLB/SyncOddsForGames.php`
  - `app/Actions/OddsApi/MLB/SyncPlayerPropsForGames.php`
- Historical odds:
  - `app/Actions/OddsApi/MLB/SyncHistoricalOddsForGames.php`
  - `app/Actions/ScoresAndOdds/MLB/SyncHistoricalOddsForGames.php`
  - `app/Services/ScoresAndOdds/MLB/HistoricalOddsScraper.php`
- Weather:
  - `app/Console/Commands/MLB/SyncGameWeatherCommand.php`
  - `app/Services/MLB/GameWeatherService.php`
- Team metrics and bullpen:
  - `app/Actions/MLB/CalculateTeamMetrics.php`
  - `app/Services/MLB/BullpenRatingService.php`

### API and UI Paths

Slate Decision Board:

- Backend service: `app/Services/MLB/MlbBettingSignalService.php`
- API v2 entry: `app/Services/Api/V2/SportSignalQuery.php`
- UI: `resources/js/components/mlb/MlbSignalsPanel.vue`

Predictions list:

- API query: `app/Services/Api/V2/SportPredictionQuery.php`
- API resource: `app/Http/Resources/Api/V2/SportPredictionResource.php`
- Page: `resources/js/pages/MLB/Predictions.vue`
- List: `resources/js/components/SportPredictions.vue`
- Card: `resources/js/components/predictions/UnifiedPredictionCard.vue`

## Answers to Required Audit Questions

### 1. How are MLB predictions currently generated?

`GeneratePrediction` blends team Elo, pitcher Elo, team metrics, historical priors, situational context, injuries, probable-pitcher status, park factors, and weather into predicted spread, total, win probability, and confidence.

Historical generation uses `executeHistorical()` and bypasses the normal final-game guard. Current/live generation avoids final games.

### 2. What data feeds are used?

The system uses ESPN for games/details/stats/plays/injuries, Odds API for current odds and props, ScoresAndOdds/Odds API archive paths for historical odds, and app-owned weather/park/metrics services for contextual adjustments.

### 3. How are odds, scores, teams, pitchers, park factors, and signals stored?

- Odds are stored on `mlb_games.odds_data`, `odds_api_event_id`, and `odds_updated_at`.
- Scores and game state are stored on `mlb_games` with score, inning, inning half, count, and status fields.
- Teams are stored in `mlb_teams`.
- Probable pitchers are stored on `mlb_games.probable_home_pitcher_espn_id` and `probable_away_pitcher_espn_id`.
- Park and weather context is stored mainly inside prediction `model_metadata` and `mlb_game_weather`.
- Slate signals are computed dynamically by `MlbBettingSignalService`; they are not currently stored as immutable recommendation records.

### 4. How are moneyline, run line, totals, and live signals calculated?

Moneyline:

- `moneylineBetCandidate()` picks the side with higher model win probability.
- It gets the matching h2h price from `game.odds_data`.
- It converts American odds to raw implied probability.
- It calculates `probability_edge = model_probability - market_implied_probability`.
- It classifies as `bet`, `lean`, or `pass` through `finalizeBetCandidate()`.

Run line:

- `runLineBetCandidate()` uses `runLineEdge()`.
- Run-line betting is disabled by default in `config/mlb.php`.

Totals:

- `totalBetCandidate()` compares predicted total to market total.
- Total betting is disabled by default in `config/mlb.php`.

Live:

- `UpdateLivePrediction` writes `live_win_probability`, `live_predicted_spread`, `live_predicted_total`, `live_outs_remaining`, and `live_updated_at`.
- `MlbBettingSignalService::liveSignals()` intentionally treats live rows as monitor-only.

### 5. Why does the Slate Decision Board show 1 bet while MLB Predictions shows 0 bets?

Root cause: they count different things from different sources.

- Slate board `1 bets` = `recommended_bets.length` from `MlbBettingSignalService`.
- Prediction list `0 bets` = generic `recommendedBetCount` in `SportPredictions.vue`.
- The generic prediction list does not use `MlbBettingSignalService` candidates.
- API v2 prediction rows do not currently include a canonical MLB recommendation payload.

This is a contract mismatch, not necessarily a model mismatch.

### 6. Are pregame predictions separated from live predictions?

Partially.

- Backend storage has separate live fields on the same `mlb_predictions` row.
- `MlbBettingSignalService` labels live signals as monitor-only.
- `UnifiedPredictionCard` uses live probability when live data exists, but still displays a moneyline label on the same shared prediction card.

Required fix: the card should display pregame pick and live monitor separately. Live data should not mutate the semantic meaning of the original pregame pick.

### 7. Are historical predictions saved as immutable snapshots?

Partially.

- `PredictionFeatureSnapshotRecorder` records snapshots in `prediction_feature_snapshots`.
- `PredictionEvaluationRecorder` records evaluations in `prediction_evaluations`.
- The active `mlb_predictions` row is updated with `updateOrCreate(['game_id' => ...])`, so the row itself is mutable.

Backtesting should use snapshots/evaluations wherever possible, not only the latest mutable prediction row.

### 8. Can the current system be accurately backtested?

Yes, but only with constraints:

- Use historical backfill and feature snapshots.
- Do not use live fields for pregame backtests.
- Do not use post-game stats in feature generation.
- Validate that historical odds are odds available before game start.
- Prefer snapshot `generated_at` and market context over mutable `updated_at`.

Current calibration exists through `mlb:report-calibration`, but it should be expanded for moneyline edge, no-vig edge, recommendation buckets, and risk flags.

### 9. Which metrics should evaluate the model?

Core prediction metrics:

- Winner accuracy
- Brier score
- Log loss
- Calibration by probability bucket
- Spread MAE
- Total MAE
- Bias vs market spread
- Bias vs market total

Betting/recommendation metrics:

- Raw implied edge
- No-vig edge
- Closing-line value where available
- ROI proxy by market
- Hit rate by recommendation type
- Hit rate by probability bucket
- Hit rate by odds bucket
- Hit rate by home/away split
- Hit rate by risk flag
- Pass-rate and false-positive rate

Operational metrics:

- Odds freshness
- Probable-pitcher freshness
- Weather freshness
- Feature snapshot completeness
- Prediction lock time relative to first pitch

### 10. Which tuning changes are safest?

Safest first:

- Unify recommendation contract across Slate board and Prediction cards.
- Add raw edge and no-vig edge separately.
- Add explicit timestamp labels and prediction lock metadata.
- Add pregame/live UI separation.
- Expand calibration report before changing weights.

Riskier later:

- Adjust model coefficients.
- Adjust pitcher/team Elo weights.
- Promote run-line or total bets.
- Change bet thresholds.

## Immediate Issue Findings

### Bet Count Mismatch

Files involved:

- `MlbBettingSignalService::recommendedBets()`
- `MlbSignalsPanel.vue`
- `SportPredictions.vue::shouldShowAsRecommendedBet()`
- `SportPredictionResource`
- `UnifiedPredictionCard.vue`

Recommendation:

Add one canonical recommendation object to API v2 prediction rows and reuse it in both components.

Preferred shape:

```json
{
  "recommendation_type": "bet",
  "market_type": "moneyline",
  "recommendation_strength": "moderate",
  "is_bet": true,
  "is_visible": true,
  "model_probability": 0.57,
  "market_price": -105,
  "raw_implied_probability": 0.512,
  "no_vig_implied_probability": null,
  "raw_edge": 0.058,
  "no_vig_edge": null,
  "score": 80,
  "reason_codes": ["moneyline_bet_filter", "probable_pitchers_confirmed"],
  "risk_flags": ["away_pick_underperforming_split"]
}
```

### Pregame vs Live

Current risk:

- The live monitor is backend-correct as monitor-only.
- The shared card can visually blur pregame and live because it uses live probability for the moneyline display.

Recommended UI language:

```text
Pregame pick: PHI ML
Pregame model: 54%
Original edge: +2.1%
Live win probability: 46%
Live model status: monitor only
```

### Timestamp Semantics

Current fields:

- `mlb_predictions.created_at`
- `mlb_predictions.updated_at`
- `mlb_predictions.graded_at`
- `mlb_predictions.live_updated_at`
- `prediction_feature_snapshots.generated_at`
- `mlb_games.odds_updated_at`
- `mlb_games.updated_at`

Problem:

`updated_at` on the prediction row can represent reruns, result grading, live clearing, or other persistence changes. It is not safe as the original prediction timestamp.

Recommendation:

Add or expose explicit metadata:

- `prediction_created_at`
- `model_run_at`
- `prediction_locked_at`
- `feature_snapshot_at`
- `odds_collected_at`
- `game_started_at`
- `result_updated_at`
- `display_updated_at`

V1 can expose existing `created_at`, `updated_at`, `graded_at`, `live_updated_at`, `feature_snapshot.generated_at`, and `game.odds_updated_at` with clearer labels before adding columns.

### Final Result Cards

Current result cards show win/loss outcome but not the original pick context.

Recommended final card display:

```text
Winner Pick: SEA
Result: Correct
Pregame model: 56%
Odds at pick: -115
Raw edge: +2.4%
No-vig edge: +1.8%
Prediction time: 9:15 AM
Model version: rules-v1 / core-v3 / baseline-v1
```

### Edge Calculation

Current moneyline calculation:

- `-105` maps to `105 / (105 + 100) = 51.2%`.
- `+120` maps to `100 / (120 + 100) = 45.5%`.
- `57% - 51.2% = +5.8% raw edge`.

This raw calculation appears correct in `MlbBettingSignalService::americanToImpliedProbability()`.

Missing:

- No-vig implied probability.
- Book/market source selection.
- Best-price vs first-book policy.
- Staleness hard gating.

### Risk Flags

Current risk flag example:

- `away_pick_underperforming_split`

This is generated in `baseBetScore()` for away picks. It currently affects score by subtracting 6, but it is not yet proven by a stored historical risk-flag report.

Recommendation:

Risk flags should be audited by:

- Home vs away
- Favorite vs underdog
- Odds bucket
- Confidence bucket
- Pitcher certainty bucket
- Recommendation type

### Odds Coverage Denominator

Current Slate board denominator:

- `Priced 2 / 2` is scoped to moneyline candidate count, not total slate games.
- `odds_health.slate_games` is the full slate count.
- `moneyline_readiness.candidate_count` is the moneyline candidate denominator.

Recommended label:

```text
Priced candidates: 2 / 2
Full slate odds: 9 games
```

### MLB Live Status Formatting

The signal panel formats MLB live rows correctly enough with inning and score. The generic live rail/card path can still show football/basketball-style clock fields because `usePredictionLiveData` normalizes all sports through shared `period`/`clock` fields and API v2 game resources do not expose MLB inning fields.

Recommendation:

Expose MLB-specific live fields through API v2 prediction game payload:

- `inning`
- `inning_half`
- `balls`
- `strikes`
- `outs`

Then format:

- `Top 2nd`
- `Bottom 2nd`
- `Middle 3rd`
- `End 3rd`
- `Final`

## Canonical Recommendation Contract

Create a backend helper/service that normalizes recommendation output for MLB predictions.

Suggested service:

```text
App\Services\MLB\MlbPredictionRecommendationService
```

Responsibilities:

- Wrap `MlbBettingSignalService::betCandidatesForPrediction()`.
- Return exactly one primary recommendation per prediction.
- Preserve all candidates for diagnostics.
- Separate pregame and live semantics.
- Include raw and no-vig edge.
- Include odds freshness.

Canonical fields:

- `recommendation_type`: `bet | lean | monitor | no_play`
- `market_type`: `moneyline | run_line | total | live`
- `recommendation_strength`: `strong | moderate | lean | none`
- `is_bet`: boolean
- `is_visible`: boolean
- `reason_codes`: string array
- `risk_flags`: string array
- `score`: integer
- `model_probability`: float or null
- `market_price`: integer or null
- `raw_implied_probability`: float or null
- `no_vig_implied_probability`: float or null
- `raw_edge`: float or null
- `no_vig_edge`: float or null
- `odds_updated_at`: ISO timestamp or null
- `odds_fresh`: boolean
- `prediction_phase`: `pregame | live | final`

## Backtest and Measurement Plan

### Phase 1: Measurement Before Tuning

Extend `mlb:report-calibration` or add a companion mode to report:

- Moneyline recommendation buckets
- Raw edge buckets
- No-vig edge buckets
- Odds buckets
- Confidence buckets
- Home/away splits
- Risk flag splits
- Pitcher certainty splits
- Weather and park-context splits
- Pregame-only vs live-only separation

Do not count live fields in pregame backtests.

### Phase 2: Recommendation Contract

Add canonical MLB recommendation payload to API v2 predictions.

Update:

- `SportPredictionResource`
- `SportPredictions.vue`
- `UnifiedPredictionCard.vue`
- `MlbSignalsPanel.vue`

Goal:

The Slate Decision Board and prediction list should show the same bet count for the same slate and filter context.

### Phase 3: Timestamp and Snapshot Clarity

Expose or add:

- Prediction created/model run time
- Feature snapshot time
- Odds collected time
- Lock time before first pitch
- Result/grading time

Goal:

Final cards and backtests can distinguish original prediction from later status/result updates.

### Phase 4: Pregame/Live UI Separation

Update cards so live games show:

- Original pregame pick
- Original model probability
- Original market edge
- Live win probability
- Live status as monitor only

Goal:

No user should interpret live monitor output as a new official pregame bet.

### Phase 5: Tuning

Only after phases 1-4:

- Tune moneyline thresholds.
- Add no-vig gating.
- Add stricter stale-odds gating.
- Consider run-line and total promotion if backtest proves them.
- Consider separate moneyline/run-line/total signal scores.

## Required Tests

### Odds Math

- `-105` odds converts to about `0.5122`.
- `+120` odds converts to about `0.4545`.
- `57%` model probability against `-105` produces about `+0.0578` raw edge.
- Raw edge and no-vig edge are stored separately.

### Contract Consistency

- Slate board and prediction list count the same canonical `is_bet` rows.
- A `bet` in `recommended_bets` appears as `recommendation_type = bet` on the matching prediction row.
- A `lean` appears consistently as `lean`, not `bet` in one component and `0 bets` in another.

### Pregame/Live Separation

- Live rows expose `live_win_probability`.
- Pregame pick fields remain unchanged during live updates.
- Live monitor rows do not become official bets.
- Final cards display original pregame pick details.

### Timestamp Safety

- Prediction card displays prediction/model timestamp, not generic `updated_at`, when available.
- Final cards display result/grading timestamp separately.
- Backtests use feature snapshot time and pregame odds time.

### MLB Status Formatting

- In-progress MLB games render inning labels, not `0:00`.
- Final games render `Final`.

## Implementation Order

1. Add tests for odds math and canonical recommendation contract.
2. Add `MlbPredictionRecommendationService`.
3. Add recommendation payload to API v2 prediction resource.
4. Update `SportPredictions.vue` recommended count/filter to use canonical payload.
5. Update `UnifiedPredictionCard.vue` to separate pregame and live labels for MLB.
6. Update Slate board labels for candidate denominator clarity.
7. Extend calibration reporting for recommendation/risk buckets.
8. Only then tune thresholds.

## Current No-Go Rules

- Do not tune from one slate.
- Do not promote run-line or total bets until backtested.
- Do not evaluate pregame predictions using live fields.
- Do not use `updated_at` as the original prediction timestamp.
- Do not treat raw implied edge as no-vig edge.
- Do not let live monitor rows become saved official bets without explicit market context.

## PR 1 Implementation Notes

This PR adds the first canonical MLB recommendation contract without tuning model weights, Elo formulas, or bet thresholds.

Implemented:

- `MlbPredictionRecommendationService` wraps `MlbBettingSignalService::betCandidatesForPrediction()` so API v2 prediction rows use the same bet/lean/pass decision as the Slate Decision Board.
- API v2 MLB predictions now expose `recommendation` with `recommendation_type`, `market_type`, `recommendation_strength`, `is_bet`, `prediction_phase`, raw implied probability, raw edge, no-vig placeholders, score, reason codes, risk flags, and odds freshness.
- Live MLB rows return `recommendation_type = monitor` and `is_bet = false`; the original pregame recommendation is preserved under `pregame_recommendation`.
- Frontend MLB prediction counts and the recommended-bets filter now use `prediction.recommendation.is_bet` instead of generic cross-sport fields.
- `UnifiedPredictionCard` keeps MLB moneyline display tied to pregame probability and labels MLB rows as `Pregame pick` so live probability does not overwrite pregame recommendation semantics.
- API v2 prediction game payload now includes MLB live state fields: `inning`, `inning_half`, `balls`, `strikes`, and `outs`.

Test coverage added:

- American odds conversion for `-105` and `+120`.
- Raw edge calculation for `57%` model probability versus `-105`.
- Raw edge and no-vig edge remain separate.
- A Slate board recommended bet appears on the matching API v2 prediction row as `recommendation_type = bet` and `is_bet = true`.
- Lean, no-play, and live monitor rows do not count as official bets.

## Backend Calculation Review

This section reviews the backend calculation path as it exists today. It is intentionally an audit, not a tuning pass. Do not change weights, thresholds, or formulas until the point-in-time data path and calibration reports are proven clean.

### End-to-End Call Graph

Pregame season generation:

```text
mlb:generate-predictions
  -> App\Console\Commands\MLB\GeneratePredictionsCommand
  -> App\Console\Commands\Sports\AbstractGenerateSeasonScheduledPredictionsCommand
  -> App\Actions\MLB\GeneratePrediction::execute()
  -> App\Actions\Sports\AbstractPredictionGenerator::execute()
  -> App\Actions\MLB\GeneratePrediction::makePredictionData()
  -> mlb_predictions updateOrCreate(game_id)
  -> PredictionFeatureSnapshotRecorder::record()
  -> optional narrative dispatch
```

Historical backfill and grading:

```text
mlb:backfill-historical-predictions
  -> App\Console\Commands\MLB\BackfillHistoricalPredictionsCommand
  -> App\Actions\MLB\GeneratePrediction::executeHistorical()
  -> App\Actions\Sports\AbstractPredictionGenerator::execute()
  -> mlb_predictions updateOrCreate(game_id)
  -> PredictionFeatureSnapshotRecorder::record()
  -> App\Actions\MLB\GradePredictions::executeForGameIds()
  -> App\Actions\Sports\AbstractGradePredictions
  -> PredictionEvaluationRecorder::record()
```

Live update path:

```text
ESPN MLB scoreboard/details sync
  -> App\Actions\MLB\UpdateLivePrediction
  -> mlb_predictions live_* fields only
  -> API v2 prediction resource
  -> Vue prediction/live components
```

Signals and recommendation path:

```text
API v2 signals endpoint
  -> App\Services\Api\V2\SportSignalQuery
  -> App\Services\MLB\MlbBettingSignalService

API v2 predictions endpoint
  -> App\Services\Api\V2\SportPredictionQuery
  -> App\Http\Resources\Api\V2\SportPredictionResource
  -> App\Services\MLB\MlbPredictionRecommendationService
  -> App\Services\MLB\MlbBettingSignalService::betCandidatesForPrediction()
```

### Flow Detail Matrix

| Flow name | Entry point | Calls | Reads | Writes | Output fields | Used by | Risks |
|---|---|---|---|---|---|---|---|
| Scheduled/current prediction generation | `mlb:generate-predictions` | `GeneratePredictionsCommand`, `AbstractGenerateSeasonScheduledPredictionsCommand`, `GeneratePrediction`, `PredictionFeatureSnapshotRecorder` | `mlb_games`, `mlb_teams`, Elo ratings, pitcher Elo, team metrics, injuries, weather, odds data | `mlb_predictions`, `prediction_feature_snapshots` | `predicted_spread`, `predicted_total`, `win_probability`, `confidence_score`, Elo splits, metadata | API v2 predictions, recommendation service, grading | `updateOrCreate` mutates the active prediction row; current odds and mutable metrics can move after the original prediction. |
| Historical prediction generation | `mlb:backfill-historical-predictions` | `BackfillHistoricalPredictionsCommand`, `GeneratePrediction::executeHistorical`, `GradePredictions` | Final MLB games, same feature sources as current generation | `mlb_predictions`, `prediction_feature_snapshots`, grading fields, `prediction_evaluations` | Same prediction fields plus actual/error fields | Calibration/backtest reports | Highest leakage risk unless metrics, odds, weather, pitcher, and roster inputs are constrained to pregame availability. |
| Live prediction update | ESPN MLB scoreboard/details sync | `UpdateLivePrediction` | Current game score, inning, inning half, existing pregame prediction | `mlb_predictions.live_*` | `live_win_probability`, `live_predicted_spread`, `live_predicted_total`, `live_outs_remaining`, `live_updated_at` | API v2 prediction resource, live cards/rails | Live fields share the same row as pregame fields; live must remain monitor-only and evaluated separately. |
| Betting signal generation | API v2 signals endpoint | `SportSignalQuery`, `MlbBettingSignalService` | Scheduled slate games, predictions, odds, teams, weather/status context | None for regular signal read path | slate summary, moneyline candidates, run-line/total candidates, live monitors | MLB Slate Decision Board | Signal rows are dynamic; they are not immutable recommendation records. |
| API v2 prediction payload | API v2 predictions endpoint | `SportPredictionQuery`, `SportPredictionResource`, `MlbPredictionRecommendationService` | `mlb_predictions`, loaded game/team rows, odds metadata | None | prediction fields, market summary, canonical recommendation, live fields | MLB Predictions page/cards | Phase/status handling is duplicated between resource and recommendation service. |
| Grading/final result update | `mlb:grade-predictions` and backfill grading path | `GradePredictions`, `AbstractGradePredictions`, `PredictionEvaluationRecorder` | Final scores, predictions, market fields | grading columns, `prediction_evaluations` | actual spread/total, errors, winner correctness, Brier/log loss where possible | Calibration and operations reports | Does not grade official bet ROI, CLV, moneyline hit rate, or totals/run-line pushes. |
| Calibration report | `mlb:report-calibration` | `ReportCalibrationCommand` | Latest graded `mlb_predictions` rows | Console output only | accuracy, MAE, market comparison, confidence buckets | Manual model review | Reads mutable prediction rows rather than always using immutable feature snapshots/evaluations. |

### Calculation Inventory Table

| Field | File/Function | Inputs | Formula/Logic | Stored Where | Pregame/Live | Backtest Safe | Test Coverage | Risk |
|---|---|---|---|---|---|---|---|---|
| `predicted_winner` | Derived by consumers from `win_probability` or spread | `win_probability`, `predicted_spread` | Home side when probability/spread favors home, otherwise away | Not stored as a dedicated MLB column | Pregame | Partially | Covered indirectly by grading/resource tests | No dedicated stored pick side means reports must consistently derive it. |
| `predicted_home_score` / `predicted_away_score` | Not generated by current MLB model | N/A | MLB stores spread and total, not explicit score projection columns | Not stored | Pregame | N/A | Not covered | UI/report code should not assume these fields exist for MLB. |
| `predicted_spread` | `GeneratePrediction::calculateSpread` plus adjustments | Team Elo, pitcher Elo, HFA, metrics, injuries, situational, priors | Home margin projection; positive means home favored | `mlb_predictions.predicted_spread` and snapshot outputs | Pregame | Partially | `GeneratePredictionTest`, backfill tests | Mutable metrics and historical backfill can leak future context. |
| `predicted_total` | `GeneratePrediction::calculateTotal` plus adjustments | Combined Elo, historical priors, situational, injuries, park, weather | Base run environment plus context adjustments | `mlb_predictions.predicted_total` and snapshot outputs | Pregame | Partially | Weather/team metric tests, prediction tests | Weather/park/current metric timestamp safety must be proven. |
| `win_probability` | `AbstractPredictionGenerator::calculateWinProbability` | Final predicted spread | Logistic conversion from spread to home win probability | `mlb_predictions.win_probability` and snapshot outputs | Pregame | Partially | Prediction/grading tests | Calibration curve needs expanded Brier/log-loss bucket reporting. |
| `confidence_score` | `AbstractPredictionGenerator::calculateConfidence` | `win_probability` | `max(p, 1-p) * 100` | `mlb_predictions.confidence_score` and snapshot outputs | Pregame | Partially | Prediction tests | Confidence is not the same as bet edge; it must not override market value gates. |
| Team Elo fields | `GeneratePrediction::getTeamElo` | `mlb_elo_ratings`, team fallback Elo | Latest carryover rating at or before game date, fallback team/default Elo | `mlb_predictions.home_team_elo`, `away_team_elo` | Pregame | Mostly | Elo tests | Safe if Elo ratings are calculated in chronological order and not overwritten. |
| Pitcher Elo fields | `GeneratePrediction::getPitcherElo` | Probable pitcher id, depth chart, pitcher Elo ratings, defaults | Probable rating, depth-chart likely starter, recent team pitcher average, league default | `home_pitcher_elo`, `away_pitcher_elo`, metadata | Pregame | Partially | Prediction/probable pitcher tests | Probable pitcher changes after prediction timestamp need lock-time tracking. |
| Context adjustments | `applyContextMetricAdjustments`, `SituationalPredictionContextService` | Team metrics, bullpen, handedness, starter form, injuries | Weighted spread/total deltas, several clamped submodels | `model_metadata`, snapshots, final spread/total | Pregame | Mixed | Some team metric/bullpen tests | Advanced ratings and handedness can use mutable/current rows in historical runs. |
| Market spread/total context | `GeneratePrediction::getVegasSpread`, `getMarketTotal` | `mlb_games.odds_data` first matching bookmaker/market | Home spread point and Over total point | `vegas_spread`, `model_metadata.market_context`, snapshots | Pregame/recommendation context | Partially | Odds sync tests | Current mutable odds are not enough for historical pregame audit without snapshots. |
| `signal_score` | `MlbBettingSignalService::finalizeBetCandidate` | Confidence, model spread, win probability, price edge, pitcher certainty, side | Starts at 30, adds/subtracts configured components, clamps 0-100 | Dynamic recommendation payload; not stored as immutable row | Pregame signal | No unless snapshotted | `MlbSignalsMoneylineTest`, recommendation contract test | Score calibration is not yet proven historically. |
| `raw_edge` | `MlbBettingSignalService::moneylineBetCandidate` and recommendation service | Model probability and American moneyline | `model_probability - raw_implied_probability` | Dynamic recommendation payload | Pregame signal | Partially | Recommendation contract test | Raw edge is not no-vig edge. |
| Live fields | `UpdateLivePrediction` | Score, inning, inning half, pregame prediction | Live logistic/pace blend; coarse outs remaining | `mlb_predictions.live_*` | Live only | Separate only | `UpdateLivePredictionTest` | Must be excluded from pregame backtests. |

### Calculation Inventory

Primary generator:

- `App\Actions\MLB\GeneratePrediction`
- `App\Actions\Sports\AbstractPredictionGenerator`

Context services:

- `App\Services\MLB\HistoricalPredictionContextService`
- `App\Services\MLB\SituationalPredictionContextService`
- `App\Services\MLB\BullpenRatingService`
- `App\Services\MLB\GameWeatherService`
- `App\Services\Sports\GameData`
- `App\Services\Sports\DateWindow\SportsDateWindowService`

Market and signal services:

- `App\Actions\OddsApi\MLB\SyncOddsForGames`
- `App\Actions\OddsApi\AbstractSyncOddsForGames`
- `App\Services\OddsApi\GameOddsSnapshotRecorder`
- `App\Services\MLB\MlbBettingSignalService`
- `App\Services\MLB\MlbPredictionRecommendationService`

Post-generation audit:

- `App\Actions\MLB\GradePredictions`
- `App\Actions\Sports\AbstractGradePredictions`
- `App\Services\Predictions\PredictionFeatureSnapshotRecorder`
- `App\Services\Predictions\PredictionEvaluationRecorder`
- `App\Console\Commands\MLB\ReportCalibrationCommand`

Team metric inputs:

- `App\Actions\MLB\CalculateTeamMetrics`
- `App\Actions\MLB\CalculateElo`
- `App\Actions\MLB\CalculatePitcherElo`
- `App\Actions\MLB\CalculateBullpenRatings`

### Pregame Prediction Formula Path

`GeneratePrediction::makePredictionData()` produces the pregame model row.

Core sequence:

1. Skip final games unless `executeHistorical()` explicitly enables historical mode.
2. Load home and away teams.
3. Resolve team Elo before the game date when possible.
4. Resolve pitcher Elo through probable pitcher, depth chart likely starter, recent team pitcher ratings, then default fallback.
5. Blend team and pitcher Elo with early-season ramping.
6. Add prediction-specific home-field advantage.
7. Convert Elo difference into predicted spread.
8. Convert average combined Elo into predicted total.
9. Add scaled context metric adjustments.
10. Add historical prior adjustments, ramping down as current-season sample grows.
11. Add situational adjustments.
12. Add injury and probable-pitcher injury adjustments.
13. Add park factor and actual-weather total adjustments.
14. Convert final spread into win probability.
15. Convert win probability into confidence.
16. Attach market spread and total if available.
17. Store row and feature snapshot.

Important sign convention:

- `predicted_spread` is model home margin. Positive means the home team is favored.
- `vegas_spread` is parsed as the home-team spread from Odds API. Vegas convention is usually negative when the home team is favored.
- Market spread comparisons must account for that sign. The calibration command does this with `actual_margin + market_spread`.

### Formula Catalogue

Team and pitcher Elo blend:

```text
season_progress = min(1, completed_sample_games / MLB_EARLY_SEASON_RAMP_GAMES)
team_weight = early_team_weight + ((base_team_weight - early_team_weight) * season_progress)
pitcher_weight = 1 - team_weight
combined_elo = (team_elo * team_weight) + (pitcher_elo * pitcher_weight)
```

Default config values:

- `MLB_EARLY_SEASON_RAMP_GAMES`: `20`
- `MLB_EARLY_SEASON_TEAM_WEIGHT_START`: `0.45`
- `mlb.elo.team_weight`: `0.60`

### Config and Threshold Catalogue

| Config key | Default | Used by | Effect | Tuning risk | Backtest before changing |
|---|---:|---|---|---|---|
| `mlb.elo.default_rating` | `1500` | Team/pitcher Elo fallbacks | Baseline strength when no rating exists | Medium | Yes |
| `mlb.elo.team_weight` | `0.60` | `GeneratePrediction::dynamicEloWeights` | Final-season team share of combined Elo | High | Yes |
| `mlb.elo.recent_starts_limit` | `10` | Pitcher fallback | Number of recent team pitcher ratings averaged when starter rating is missing | Medium | Yes |
| `mlb.prediction.home_field_advantage` | `5` | Pregame spread model | Adds to home combined Elo before spread conversion | High | Yes |
| `mlb.prediction.spread_to_probability_coefficient` | `10.0` | Win probability | Controls probability steepness from projected run margin | High | Yes |
| `mlb.prediction.elo_diff_to_spread_divisor` | `44.0` | Spread model | Converts Elo gap to projected runs | High | Yes |
| `mlb.prediction.total_model.base_runs` | `10.6` | Total model | Base MLB run environment | High | Yes |
| `mlb.prediction.total_model.average_elo_baseline` | `1500` | Total model | Neutral combined Elo baseline | Medium | Yes |
| `mlb.prediction.total_model.average_elo_divisor` | `80` | Total model | Converts average Elo strength into total runs | High | Yes |
| `mlb.prediction.historical_priors.max_weight` | `0.35` | Historical priors | Max early-season prior influence | Medium | Yes |
| `mlb.prediction.historical_priors.max_spread_adjustment` | `0.8` | Historical priors | Spread cap from prior-year context | Medium | Yes |
| `mlb.prediction.historical_priors.max_total_adjustment` | `0.9` | Historical priors | Total cap from prior-year context | Medium | Yes |
| `mlb.prediction.early_season.ramp_games` | `20` | Early-season blend | Games until context/team weighting fully ramps | Medium | Yes |
| `mlb.prediction.early_season.context_scale_min` | `0.35` | Context adjustments | Minimum context adjustment scale early in season | Medium | Yes |
| `mlb.prediction.probable_pitcher_out_spread_penalty` | `1.1` | Pitcher injury adjustment | Moves spread against team with out probable pitcher | High | Yes |
| `mlb.prediction.probable_pitcher_out_total_boost` | `0.7` | Pitcher injury adjustment | Adds total when probable pitcher is out | Medium | Yes |
| `mlb.prediction.actual_weather.max_total_adjustment` | `1.6` | Weather adjustment | Caps weather total movement | Medium | Yes |
| `mlb.signals.odds_stale_hours` | `12` | Signal odds health/recommendation service | Marks odds freshness | Medium | Yes, before making it a hard gate |
| `mlb.signals.bet_filter.moneyline_enabled` | `true` | Signal candidate generation | Enables moneyline recommendation candidates | High | Yes |
| `mlb.signals.bet_filter.run_line_enabled` | `false` | Signal candidate generation | Enables run-line recommendations | High | Yes |
| `mlb.signals.bet_filter.total_enabled` | `false` | Signal candidate generation | Enables totals recommendations | High | Yes |
| `mlb.signals.bet_filter.strong_min_score` | `70` | `finalizeBetCandidate` | Minimum score for `bet` | High | Yes |
| `mlb.signals.bet_filter.lean_min_score` | `55` | `finalizeBetCandidate` | Minimum score for `lean` | High | Yes |
| `mlb.signals.bet_filter.min_confidence` | `55` | `baseBetScore` | First confidence score bonus threshold | Medium | Yes |
| `mlb.signals.bet_filter.strong_confidence` | `60` | `baseBetScore` | Strong confidence score bonus threshold | Medium | Yes |
| `mlb.signals.bet_filter.min_model_spread` | `1.0` | Moneyline signal scoring | First model run-margin bonus threshold | Medium | Yes |
| `mlb.signals.bet_filter.strong_model_spread` | `1.5` | Moneyline signal scoring | Strong model run-margin bonus threshold | Medium | Yes |
| `mlb.signals.bet_filter.min_run_line_edge` | `1.0` | Run-line signal scoring | Minimum run-line edge before pass risk | High | Yes |
| `mlb.signals.bet_filter.min_total_edge` | `1.25` | Totals signal scoring | Minimum total edge before pass risk | High | Yes |

Spread:

```text
adjusted_home_elo = home_combined_elo + MLB_PREDICTION_HOME_FIELD_ADVANTAGE
elo_diff = adjusted_home_elo - away_combined_elo
predicted_spread = elo_diff / MLB_ELO_DIFF_TO_SPREAD_DIVISOR
```

Default config values:

- `MLB_PREDICTION_HOME_FIELD_ADVANTAGE`: `5`
- `MLB_ELO_DIFF_TO_SPREAD_DIVISOR`: `44.0`

Total:

```text
average_elo = (home_combined_elo + away_combined_elo) / 2
predicted_total = MLB_TOTAL_MODEL_BASE_RUNS
  + ((average_elo - MLB_TOTAL_MODEL_AVERAGE_ELO_BASELINE) / MLB_TOTAL_MODEL_AVERAGE_ELO_DIVISOR)
```

Default config values:

- `MLB_TOTAL_MODEL_BASE_RUNS`: `10.6`
- `MLB_TOTAL_MODEL_AVERAGE_ELO_BASELINE`: `1500`
- `MLB_TOTAL_MODEL_AVERAGE_ELO_DIVISOR`: `80`

Probability and confidence:

```text
win_probability = 1 / (1 + exp(-predicted_spread / MLB_SPREAD_TO_PROBABILITY_COEFFICIENT))
confidence_score = max(win_probability, 1 - win_probability) * 100
```

Default config value:

- `MLB_SPREAD_TO_PROBABILITY_COEFFICIENT`: `10.0`

Context metric adjustments from `AbstractPredictionGenerator`:

```text
recent_diff = home_recent_form_rating - away_recent_form_rating
fatigue_diff = away_rest_travel_fatigue - home_rest_travel_fatigue
injury_delta = (home_injury_adjusted - away_injury_adjusted) - (home_elo - away_elo)
injury_loss = max(0, home_elo - home_injury_adjusted) + max(0, away_elo - away_injury_adjusted)

spread_adjustment =
  recent_diff * recent_spread_weight
  + fatigue_diff * fatigue_spread_weight
  + injury_delta * injury_spread_weight

total_adjustment =
  (home_recent + away_recent) * recent_total_weight
  - (home_fatigue + away_fatigue) * fatigue_total_weight
  - injury_loss * injury_total_weight
```

MLB situational adjustments:

- Bullpen fatigue: `(away_fatigue - home_fatigue) * 0.30` to spread, `(home_fatigue + away_fatigue) * 0.22` to total.
- Bullpen quality: rating-centered difference with spread weight `0.24` and total weight `0.14`.
- Handedness: lineup handedness edge spread weight `0.45`, total weight `0.16`.
- Advanced ratings: OPS/WHIP/ERA matchup score with spread weight `0.18`, total weight `0.16`, capped at `0.6` spread and `0.7` total.
- Starter form: recent pitcher Elo trend with spread weight `0.25`, total weight `0.10`.

Historical prior adjustments:

- Uses prior-season `TeamMetric` rows only.
- Run differential gap moves spread by `MLB_HISTORICAL_PRIORS_SPREAD_RUN_DIFF_MULTIPLIER`, capped at `0.8`.
- Prior run environment moves total by `MLB_HISTORICAL_PRIORS_TOTAL_RUN_ENVIRONMENT_MULTIPLIER`, capped at `0.9`.
- Weight starts at max `0.35` and ramps down as current-season sample grows.

Injury adjustments:

- Generic team injury adjustment uses active injury rows as of game date.
- Probable pitcher injury adjustment uses pitcher injury rows active as of game date.
- Probable pitcher out: spread penalty `1.1`, total boost `0.7`.
- Probable pitcher questionable: spread penalty `0.45`, total boost `0.25`.

Park and weather:

- Park factor is configured by venue and applied directly to predicted total.
- Actual weather applies only when outdoor context is known.
- Wind/gust direction, precipitation, cold, warmth, and humidity move total.
- Weather total adjustment is clamped by `MLB_ACTUAL_WEATHER_MAX_TOTAL_ADJUSTMENT`, default `1.6`.

### Team Metrics Formula Catalogue

`CalculateTeamMetrics` writes one mutable row per team, season, and season type.

Offensive rating:

```text
100
  + (runs_per_game - 4.40) * 12
  + (OPS - .720) * 120
  + (home_runs_per_game - 1.10) * 5
```

Pitching rating:

```text
100
  + (4.40 - runs_allowed_per_game) * 8
  + (4.20 - ERA) * 7
  + (1.30 - WHIP) * 35
  + (K_minus_BB_per_game - 5.20) * 3
  + (1.10 - HR_allowed_per_game) * 4
```

Other important metrics:

- `R/G`: team runs divided by completed games with complete team stats.
- `RA/G`: opponent runs divided by completed games with complete team stats.
- `AVG`: hits divided by at bats.
- `OBP`: official `(H + BB + HBP) / (AB + BB + HBP + SF)` when HBP/SF are available, otherwise approximate `(H + BB) / (AB + BB)`.
- `SLG`: total bases divided by at bats.
- `OPS`: OBP plus SLG.
- `ERA`: earned runs times nine divided by normalized innings pitched.
- `WHIP`: walks plus hits allowed divided by normalized innings pitched.
- `SOS`: opponent Elo/rating context.
- `Form`: recent performance context.
- `InjAdj`: injury-adjusted team rating.
- `Fatigue`: rest and travel fatigue context.

Important constraint:

`TeamMetric` rows are not point-in-time snapshots. They are recalculated mutable season aggregates. This is acceptable for current production display when the data is fresh, but it can leak future information into historical prediction backfills unless the command reconstructs metrics as of each game date or reads a historical snapshot.

### Odds and Edge Formula Catalogue

Current odds sync:

```text
mlb:sync-odds
  -> App\Actions\OddsApi\MLB\SyncOddsForGames
  -> App\Actions\OddsApi\AbstractSyncOddsForGames
  -> OddsApiService::getOdds()
  -> GameOddsSnapshotRecorder::record()
  -> mlb_games.odds_data / odds_api_event_id / odds_updated_at
```

Odds matching:

- Odds API commence time is parsed as UTC.
- Local game time is built from `game_date`, `game_time`, and `config('app.timezone')`, then converted to UTC.
- Game matching allows a maximum event-time difference of 360 minutes.
- Matching first tries mapped team names, then fuzzy matching.

Moneyline implied probability:

```text
positive American odds: 100 / (price + 100)
negative American odds: abs(price) / (abs(price) + 100)
```

Moneyline edge:

```text
model_probability = max(home_win_probability, 1 - home_win_probability)
market_implied_probability = american_to_implied_probability(price)
probability_edge = model_probability - market_implied_probability
```

Moneyline score components:

- Base score starts at `30`.
- Confidence above `55` adds `10`; above `60` adds `20`.
- Probable pitchers confirmed adds `10`.
- Pitcher uncertainty subtracts `18`.
- Home side adds `5`; away side subtracts `6`.
- Model spread edge above `1.0` adds `8`; above `1.5` adds `16`.
- Win probability above `55%` adds `6`; above `60%` adds `12`.
- Raw probability edge above `1.5%` adds `6`; above `3.5%` adds `14`.
- Missing price or no market value forces pass.

Run-line edge:

```text
run_line_edge = predicted_spread + vegas_spread
```

Important constraints:

- Run-line recommendations are disabled by default.
- Run-line price is not part of the current run-line score.
- Total recommendations are disabled by default.
- Total price is not part of the current total score.
- Odds staleness is reported as odds health, but it is not currently a hard scoring gate inside candidate scoring.

### Pregame and Live Separation

Pregame:

- Main prediction fields are `predicted_spread`, `predicted_total`, `win_probability`, `confidence_score`, `vegas_spread`, and market context in `model_metadata`.
- `MlbPredictionRecommendationService` now returns canonical pregame recommendation data from the same candidate service used by the Slate board.

Live:

- `UpdateLivePrediction` writes only `live_predicted_spread`, `live_predicted_total`, `live_win_probability`, `live_outs_remaining`, and `live_updated_at`.
- Live signals are monitor-only. They should not become official pregame bets.
- Live outs remaining is coarse by half-inning and does not subtract current outs within the half-inning. This can overstate remaining outs by up to two outs.

Resource/UI risk:

- `MlbPredictionRecommendationService` treats delayed MLB games as live.
- `SportPredictionResource::isLivePrediction()` uses shared live statuses and does not include MLB `STATUS_DELAYED`.
- That creates a small duplicate-status risk where recommendation phase and generic live fields can disagree for delayed MLB games.

### Grading and Evaluation

`AbstractGradePredictions` calculates:

```text
actual_spread = home_score - away_score
actual_total = home_score + away_score
spread_error = abs(actual_spread - predicted_spread)
total_error = abs(actual_total - predicted_total)
winner_correct = sign(actual_spread) == sign(predicted_spread)
```

Important limits:

- The base grader grades winner, spread error, and total error.
- It does not grade ATS against market spread.
- It does not grade moneyline recommendation hit rate.
- It does not grade total over/under recommendation hit rate.
- It does not calculate ROI or CLV.
- `winner_correct` treats zero predicted spread or tied actual spread as not correct.

`PredictionEvaluationRecorder` adds Brier/log-loss style fields when prediction probabilities are present, and compares model error with market error where market fields exist.

### Snapshot and Backtest Safety

Current snapshot behavior:

- `mlb_predictions` is mutable because predictions are saved with `updateOrCreate(['game_id' => $game->id])`.
- `prediction_feature_snapshots` is keyed by prediction table, prediction id, model version, feature version, and blend version.
- Re-running the same prediction versions for the same prediction updates the existing snapshot instead of preserving every historical run.
- `prediction_evaluations` uses a similar version-keyed update path.

Backtest risk:

- Historical prediction rows can be regenerated after games are final.
- Team metrics are mutable full-season aggregates unless rebuilt as of each game date.
- Odds on `mlb_games.odds_data` are mutable current odds unless historical odds snapshots are explicitly used.
- Weather rows can be forecast or observed context fetched after the game unless timestamped and constrained.
- Current roster/player handedness context can leak into historical lineup assumptions.

Safe backtesting rule:

Backtests should only trust features that can prove they existed before first pitch. When that cannot be proven, the report should flag the row as diagnostic-only instead of using it to tune weights.

### Leakage and Duplication Risks

High-risk leakage areas:

- `TeamMetric` rows in historical backfills because they are one mutable row per team/season/season type.
- Advanced ratings in `SituationalPredictionContextService`, because they read current `TeamMetric` rows.
- Current odds on `mlb_games.odds_data`, unless historical snapshots are used.
- Current roster/player context for historical handedness assumptions.
- Weather rows without explicit forecast/observed/available-at semantics.

Medium-risk areas:

- Feature snapshots overwrite the same model/feature/blend version instead of preserving every run.
- Calibration command reads latest mutable predictions rather than always reading immutable snapshots/evaluations.
- Odds freshness is not a candidate scoring gate.
- Moneyline uses raw implied probability, not no-vig probability.

Duplication risks:

- Slate board and prediction cards previously had separate recommendation logic.
- Pregame/live/final status logic exists in both backend recommendation service and generic resource helpers.
- Market spread sign convention is handled in multiple places and should be centralized.
- Date/time parsing happens in feed sync, odds matching, weather, API query, and validation layers.

### Leakage Risk Table

| Risk | File/Function | Current behavior | Severity | Recommended fix |
|---|---|---|---|---|
| Full-season team metrics used for historical games | `GeneratePrediction::teamMetricsForGame`, `AbstractPredictionGenerator::teamMetricsForGame`, `CalculateTeamMetrics` | Current `TeamMetric` row is one mutable aggregate per team/season/type. Historical prediction can read a row calculated after the game. | High | Add point-in-time metric rebuild/snapshot path for historical backfills, or mark those rows diagnostic-only in reports. |
| Current odds used as historical pregame odds | `GeneratePrediction::getVegasSpread`, `getMarketTotal`, `MlbBettingSignalService` | Reads mutable `mlb_games.odds_data`; odds snapshots exist but generation does not prove selected market was available before first pitch. | High | Use `GameOddsSnapshot` or historical odds rows with collected-at/commence-time constraints in historical reports. |
| Weather fetched after first pitch treated as pregame context | `GameWeatherService`, `GeneratePrediction::actualWeatherContext` | Weather row has `observed_at`, but pregame availability is not enforced in generator/backfill. | Medium | Store weather source type and available-at timestamp; require pregame-safe weather in backtest mode. |
| Current roster/player handedness used in old games | `SituationalPredictionContextService::handednessContext` | Uses current player/team data, not a confirmed historical lineup snapshot. | Medium | Treat as diagnostic in historical backtests until lineup snapshots exist. |
| Probable pitcher changes after prediction timestamp | `GeneratePrediction::getPitcherElo`, probable pitcher injury logic | Uses current game probable pitcher fields as of generation, not necessarily original lock time. | Medium | Store prediction lock time and probable pitcher ids in immutable snapshot; compare to game start. |
| Mutable prediction row used as audit truth | `AbstractPredictionGenerator::execute` | `updateOrCreate(['game_id'])` overwrites active prediction row. | High | Use feature snapshots/evaluations for audit; add immutable run id or generated-at key if every run must be preserved. |
| Live output mixed into pregame interpretation | `UpdateLivePrediction`, `SportPredictionResource`, Vue cards | Live fields share the prediction row but are separate columns; UI must keep monitor semantics. | Medium | Keep canonical recommendation pregame-only and add tests proving live rows do not become official bets. |

### Duplicate Logic Table

| Logic | Locations | Difference | Risk | Recommendation |
|---|---|---|---|---|
| American odds implied probability | `MlbBettingSignalService::americanToImpliedProbability`, frontend formatting/helpers may derive display edge | Backend helper is authoritative; frontend should not reimplement math for decisions. | Inconsistent edge display or bet counts. | Keep backend as single source; frontend only formats API values. |
| Bet/lean/pass classification | `MlbBettingSignalService::finalizeBetCandidate`, previous generic prediction-card logic | Slate board used signal service while predictions list used generic cross-sport fields. | Board and card counts disagree. | Use `MlbPredictionRecommendationService` canonical payload everywhere. |
| Pregame/live/final phase detection | `MlbPredictionRecommendationService::predictionPhase`, `SportPredictionResource::isLivePrediction`, frontend live helpers | MLB delayed status is treated differently in places. | Delayed game can show inconsistent live/monitor state. | Add shared MLB phase helper or sport-aware resource method. |
| Market spread sign handling | `GeneratePrediction`, `ReportCalibrationCommand`, signal services | Model spread positive home; Vegas spread usually negative home favorite. | Incorrect market edge if formula is copied wrong. | Centralize sign conversion helper and test home/away examples. |
| Date/time window parsing | Odds sync, weather service, API query, validation, sentinel | Multiple date-window implementations and timezone assumptions. | Date leakage and wrong local-date slate membership. | Reuse `SportsDateWindowService` or a sport/date-window helper consistently. |
| MLB status formatting | Scoreboard sync, API resource, `usePredictionLiveData`, card components | Some paths use generic clock/period labels. | MLB live rows can show football/basketball style display. | Keep MLB inning fields in API and format via one frontend helper. |

### Existing Test Coverage

Relevant existing test files:

- `tests/Feature/MLB/GeneratePredictionTest.php`
- `tests/Feature/MLB/BackfillHistoricalPredictionsCommandTest.php`
- `tests/Feature/MLB/ReportCalibrationCommandTest.php`
- `tests/Feature/MLB/MlbSignalsMoneylineTest.php`
- `tests/Feature/MLB/MlbPredictionRecommendationContractTest.php`
- `tests/Feature/MLB/CalculateTeamMetricsTest.php`
- `tests/Feature/MLB/TeamMetricsSeasonTypeTest.php`
- `tests/Feature/MLB/SyncOddsForGamesTest.php`
- `tests/Feature/MLB/GameOddsSnapshotsTest.php`
- `tests/Feature/MLB/GameWeatherServiceTest.php`
- `tests/Feature/Actions/MLB/UpdateLivePredictionTest.php`
- `tests/Feature/ESPN/MLB/SyncGamesTest.php`
- `tests/Feature/ESPN/MLB/SyncGameDetailsTest.php`
- `tests/Feature/ESPN/MLB/SyncPlayerStatsTest.php`

Important missing coverage:

- Point-in-time historical backfill test proving no final/full-season team metrics leak into a past game.
- Test proving historical backfill uses pregame odds snapshot rather than mutable current odds.
- Test proving weather context used by a historical prediction was available before first pitch.
- Test proving no-vig edge remains separate from raw implied edge.
- Test proving stale odds cannot become an official bet once stale-odds gating is added.
- Test proving delayed MLB status has consistent live/monitor behavior across recommendation service and API resource.
- Test proving live fields never change the canonical pregame pick.
- Test proving calibration reports can bucket by canonical recommendation type, risk flags, home/away, odds bucket, and probability edge.

### Test Gap Table

| Area | Existing coverage | Missing cases | Recommended test |
|---|---|---|---|
| Odds math | `MlbSignalsMoneylineTest`, `MlbPredictionRecommendationContractTest` | No-vig probability once implemented; stale-odds hard gate. | Add tests for `-105`, `+120`, raw edge, no-vig edge, stale odds downgrade/pass. |
| Bet classification | `MlbSignalsMoneylineTest`, recommendation contract test | Below-lean pass, exact lean threshold, exact bet threshold, unconfirmed pitcher gate. | Add threshold boundary tests around score 55 and 70. |
| Run-line logic | Limited or indirect | Run-line price not considered; disabled path not deeply backtested. | Add disabled/enabled tests and verify run-line price is not promoted until modeled. |
| Totals logic | Limited or indirect | Total price not considered; weather/park total split not bucketed. | Add total candidate tests, but keep recommendations disabled until calibration proves value. |
| Pregame/live separation | `UpdateLivePredictionTest`, recommendation contract test | Delayed status consistency; live cannot become official bet. | Add API resource test for delayed/live/final MLB recommendation phase. |
| Grading | `ReportCalibrationCommandTest`, backfill tests | ATS/push, moneyline ROI, total OU, postponed/suspended skip behavior. | Add grading tests for push/no-action and non-final game skip. |
| Snapshot safety | Snapshot-related tests exist around odds and historical commands | Repeated same-version prediction overwrites snapshot; generated-at as audit key not proven. | Add snapshot preservation or intentional overwrite test and document chosen contract. |
| Historical leakage | Backfill tests exist | No test proving metrics/odds/weather were available before first pitch. | Add point-in-time historical fixture with post-game metrics that must not influence pregame row. |

### Validation Commands

| Command | Result | Notes |
|---|---|---|
| `php artisan test tests/Feature/MLB/MlbPredictionRecommendationContractTest.php tests/Feature/MLB/MlbSignalsMoneylineTest.php` | Passed in prior PR 1 verification | Validated canonical recommendation contract and odds math added before this backend audit section. |
| `php vendor/bin/pint --dirty` | Passed in prior PR 1 verification | Formatting was clean after the recommendation contract implementation. |
| `npm run build` | Passed in prior PR 1 verification | Frontend built successfully after the recommendation contract implementation. |
| `npm run lint:check` | Failed in prior PR 1 verification | Existing unrelated lint failures in `SportTeam.vue`, `PredictionsPageShell.vue`, and settings pages; changed files passed targeted ESLint. |
| Documentation-only backend review pass | Not rerun | This specific update only changes `docs/mlb_prediction_review_and_tuning_work_order.md`; no production formulas were changed. |

## Backend Calculation Review Recommendations

### Must Fix Before Tuning

1. Prove historical backfills do not use full-season/current `TeamMetric` rows for earlier games.
2. Prove historical market context comes from odds available before first pitch.
3. Preserve or intentionally version every prediction snapshot run so same-version reruns do not silently replace audit history.
4. Add no-vig probability and no-vig edge, and keep them separate from raw implied edge.
5. Make stale odds a recommendation risk gate before any official bet promotion.
6. Centralize MLB phase detection so delayed, live, final, postponed, and suspended states behave consistently.
7. Ensure live fields never affect pregame recommendation backtests or official bet counts.

### Should Fix Soon

1. Extend `mlb:report-calibration` to use canonical recommendations and report performance by `bet`, `lean`, `pass`, edge bucket, odds bucket, risk flag, home/away, and pitcher certainty.
2. Add live-specific calibration separately from pregame calibration.
3. Add a report mode that excludes any row without provably pregame feature, odds, weather, and roster timestamps.
4. Centralize market spread sign conversion and add home-favorite/away-favorite tests.
5. Clarify market source selection: first book, best available price, average book, or configured book priority.
6. Add stale odds, unconfirmed pitcher, missing weather, and missing market context as explicit recommendation risk flags.
7. Add final-card context for prediction generated time, odds collected time, grading time, and result time.

### Tune Later After Backtest

1. Elo team/pitcher weights.
2. Prediction home-field advantage.
3. Spread-to-probability coefficient.
4. Elo-to-run spread divisor.
5. Total model base run environment.
6. Weather and park factor weights.
7. Historical prior weight and caps.
8. Moneyline bet and lean thresholds.
9. Signal score scale and component weights.
10. Run-line activation.
11. Totals activation.

Do not do yet:

- Do not change Elo, pitcher, spread, total, weather, park, or signal thresholds from this review alone.
- Do not promote run-line or total recommendations until their historical pricing and hit-rate reports are separated from moneyline.
- Do not treat full-season team metrics as valid historical pregame features.
- Do not use mutable `mlb_predictions.updated_at` as the prediction lock time.
- Do not use live prediction fields in pregame recommendation backtests.

Recommended implementation order:

1. Audit safety: point-in-time tests for metrics, odds, weather, and snapshots.
2. Reporting safety: calibration report buckets using canonical recommendation payload.
3. Recommendation safety: no-vig edge and stale-odds gate.
4. Runtime cleanup: centralized MLB phase/date/market sign helpers.
5. Tuning: only after the above reports show reliable historical rows.

## Backend Safety Implementation Notes

### Summary

Implemented the first backend-safety layer required before MLB tuning. This pass did not change Elo weights, pitcher weights, spread/total coefficients, weather weights, park factors, bet thresholds, run-line activation, or totals activation.

The core change is that MLB prediction/recommendation code now has explicit guardrails for point-in-time metric usage, pregame-safe market context, append-only feature snapshots, raw-vs-no-vig edge separation, stale odds, centralized game phase detection, and live/pregame isolation.

### Files Changed

- `app/Actions/MLB/GeneratePrediction.php`
- `app/Actions/MLB/UpdateLivePrediction.php`
- `app/Console/Commands/MLB/ReportCalibrationCommand.php`
- `app/Http/Resources/Api/V2/SportPredictionResource.php`
- `app/Models/PredictionFeatureSnapshot.php`
- `app/Services/MLB/MlbBettingSignalService.php`
- `app/Services/MLB/MlbPredictionRecommendationService.php`
- `app/Services/Predictions/PredictionFeatureSnapshotRecorder.php`
- `app/Support/MLB/MlbGamePhase.php`
- `app/Support/MLB/MlbMarketSpread.php`
- `app/Support/Odds/AmericanOdds.php`
- `database/migrations/2026_06_18_120000_add_run_id_to_prediction_feature_snapshots.php`
- `tests/Feature/MLB/MlbBackendSafetyTest.php`
- `tests/Feature/MLB/MlbPredictionRecommendationContractTest.php`
- `tests/Feature/Actions/MLB/UpdateLivePredictionTest.php`

### Point-In-Time Feature Safety

Historical MLB prediction generation now refuses future-calculated current-season `TeamMetric` rows.

Behavior:

- Current/live prediction generation still uses the current mutable `mlb_team_metrics` row.
- Historical prediction generation queries `TeamMetric` rows with `calculation_date <= game_date`.
- If the only current-season metric row was calculated after the game date, it is excluded.
- If no pregame-safe current-season metric exists, the generator falls back to a prior-season metric when available.
- If no prior-season fallback exists, metrics are omitted and `model_metadata.point_in_time_safety.team_metrics.*` records the limitation.

Important limitation:

`mlb_team_metrics` still stores one mutable row per team, season, and season type. It does not yet support true dated current-season metric snapshots. This PR proves future rows are excluded; it does not create a full historical metric snapshot system.

### Market Context Safety

Historical MLB prediction generation now prefers `game_odds_snapshots` captured before scheduled first pitch.

Behavior:

- Historical market context uses the latest odds snapshot where `captured_at < scheduled_start_at`.
- Post-start snapshots are excluded from pregame prediction context.
- If no pregame-safe snapshot exists, historical generation does not silently trust mutable `mlb_games.odds_data`.
- Market safety metadata is written to `model_metadata.market_context.safety` and the feature snapshot market context.

Remaining limitation:

Older historical rows without snapshots will have missing pregame-safe market context and should be excluded by strict reports before tuning.

### Snapshot Preservation / Versioning

Prediction feature snapshots are now append-only/distinguishable.

Behavior:

- Added `snapshot_run_id` to `prediction_feature_snapshots`.
- Removed same-version `updateOrCreate` behavior in `PredictionFeatureSnapshotRecorder`.
- Re-running the same game/model/feature/blend version creates a new snapshot row with a unique run id.
- The active `mlb_predictions` row remains mutable by design.

Remaining limitation:

`prediction_evaluations` still uses the existing version-keyed update path. Tuning/backtest work should prefer feature snapshots for run history and treat evaluations as latest evaluation state until evaluation versioning is added.

### Raw Edge and No-Vig Edge

Added centralized American odds math in `App\Support\Odds\AmericanOdds`.

Raw implied probability:

```text
negative odds: abs(price) / (abs(price) + 100)
positive odds: 100 / (price + 100)
```

No-vig probability:

```text
home_no_vig = home_raw_implied / (home_raw_implied + away_raw_implied)
away_no_vig = away_raw_implied / (home_raw_implied + away_raw_implied)
```

Recommendation payload behavior:

- `raw_implied_probability` and `raw_edge` remain raw/vig-included.
- `no_vig_implied_probability` and `no_vig_edge` are filled only when both moneyline sides are available.
- Raw edge is no longer the placeholder for no-vig edge.

### Stale Odds Risk Gate

Stale and missing odds timestamps now block official bet promotion.

Behavior:

- Missing odds timestamp adds `missing_odds_timestamp`.
- Odds older than `mlb.signals.odds_stale_hours` adds `stale_odds`.
- A stale or missing-timestamp candidate can still be visible as a lean/diagnostic row.
- A stale or missing-timestamp candidate cannot be classified as an official `bet`.

### Centralized MLB Phase Detection

Added `App\Support\MLB\MlbGamePhase`.

Supported phases:

- `pregame`
- `delayed`
- `live`
- `final`
- `postponed`
- `suspended`
- `cancelled`
- `unknown`

Behavior:

- Delayed games are no longer treated as live by the recommendation service or live prediction updater.
- Final games are not live.
- Postponed, suspended, cancelled, and unknown phases do not produce normal official pregame bets.

### Pregame vs Live Isolation

Live fields remain separate from pregame fields.

Behavior:

- `UpdateLivePrediction` only updates live fields for true in-progress games.
- Delayed games clear stale live fields instead of producing live model output.
- Canonical recommendation rows return `monitor` for live games and `is_bet = false`.
- The original pregame recommendation remains nested under `pregame_recommendation`.
- Pregame bet counts should use `recommendation.is_bet`, not live fields.

### Calibration / Reporting Changes

`mlb:report-calibration` now supports:

```text
--strict-pregame
```

Strict mode excludes rows with missing or unsafe proof, including:

- missing prediction timestamp
- missing feature snapshot timestamp
- missing game start timestamp
- prediction not before first pitch
- postponed/suspended/cancelled context
- live prediction fields present
- missing pregame-safe market context
- missing odds timestamp

The calibration report also includes canonical recommendation buckets by `bet`, `lean`, `no_play`, and `monitor`, using the same backend recommendation service instead of reimplementing classification logic.

### Market Source Selection

Current policy remains unchanged for live/current rows:

- current recommendations use the first available `h2h` market in `mlb_games.odds_data`.
- this PR documents and centralizes math but does not change book selection to best price, average price, or configured priority.

Historical policy now differs intentionally:

- historical predictions use the latest pre-start `game_odds_snapshots` row when available.
- post-start snapshots are excluded from pregame prediction context.

### Market Spread Sign Convention

Added `App\Support\MLB\MlbMarketSpread`.

Convention:

- Positive model spread means projected home margin.
- Negative model spread means projected away margin.
- Vegas home spread keeps sportsbook convention.
- Run-line edge is `model_home_margin + vegas_home_spread`.

### Final-Card Context Fields

API v2 prediction resources now expose `audit_context` with:

- `prediction_generated_at`
- `prediction_locked_at`
- `feature_snapshot_at`
- `snapshot_run_id`
- `odds_collected_at`
- `graded_at`
- `result_finalized_at`
- `model_version`
- `feature_version`
- `blend_version`

The UI can use these fields later to show original prediction context separately from mutable row updates.

### Tests Added

Added `tests/Feature/MLB/MlbBackendSafetyTest.php`.

Coverage:

- MLB phase detection for scheduled, delayed, live, final, postponed, suspended, cancelled, unknown.
- Raw edge and no-vig edge separation.
- Stale odds block official bet promotion.
- Missing odds timestamp blocks official bet promotion.
- Historical prediction excludes future `TeamMetric` rows.
- Historical prediction uses pre-start odds snapshot and excludes post-start snapshot.
- Same-version prediction generation creates distinguishable feature snapshot runs.
- Live monitor rows do not count as official pregame bets.
- Delayed games clear stale live fields instead of updating live predictions.
- MLB spread sign helper behavior.

Updated existing recommendation contract tests to expect no-vig edge when both moneyline sides are present.

### Validation Commands

| Command | Result | Notes |
|---|---|---|
| `php artisan test tests/Feature/MLB/MlbBackendSafetyTest.php tests/Feature/MLB/MlbPredictionRecommendationContractTest.php tests/Feature/Actions/MLB/UpdateLivePredictionTest.php` | Passed | 15 tests, 88 assertions. |
| `php vendor/bin/pint --dirty` | Passed | Pint fixed import/style details. |
| `php artisan mlb:report-calibration --strict-pregame --limit=5` | Passed after local DB access approval | Command booted successfully; local DB had no graded MLB rows in selected scope. |

### Remaining Limitations

- `mlb_team_metrics` still does not store dated current-season snapshots.
- Historical rows without `game_odds_snapshots` cannot prove pregame market context.
- Weather and roster/lineup timestamp proof is still limited.
- `prediction_evaluations` are not yet append-only run records.
- Current book selection remains first available book, not best price or configured priority.
- Strict pregame mode may exclude many older rows until historical snapshots are backfilled.

### Follow-Up Work Before Tuning

1. Add dated MLB team metric snapshots or a historical-as-of metric builder.
2. Backfill/import pregame odds snapshots for historical MLB games.
3. Add weather available-at/source-type proof for historical reports.
4. Add roster/probable-pitcher lock timestamp proof.
5. Version prediction evaluations the same way feature snapshots are now versioned.
6. Expand `mlb:report-calibration` buckets for edge bucket, odds bucket, risk flag, home/away, pitcher certainty, and signal score bucket.
7. Only after those rows are trustworthy, tune weights and thresholds.

## MLB Final Score Reconciliation Fix

### Problem Summary

The production snapshot generated on June 18, 2026 showed a severe MLB final-score gap:

- 2026 regular-season final MLB games: 1,111
- Final games with null `mlb_games.home_score` or `mlb_games.away_score`: 1,068
- Final games with score columns present: 43

The missing scores were reconstructable because the same games had complete home and away `mlb_team_stats.runs`.

### Root Cause

`SyncGameDetails` only wrote `mlb_games.home_score` and `mlb_games.away_score` when ESPN competitor score fields were present.

`SyncTeamStats` correctly stored home and away team runs, but there was no reconciliation step to copy those final runs back onto the game row when ESPN omitted competitor scores.

The MLB game-details command also treated games as complete once player stats, team stats, and plays existed, even if final score columns were still null.

### Files Changed

- `app/Actions/MLB/ReconcileGameScoreFromTeamStats.php`
- `app/Console/Commands/MLB/ReconcileFinalScoresCommand.php`
- `app/Actions/ESPN/MLB/SyncGameDetails.php`
- `app/Actions/ESPN/MLB/SyncTeamStats.php`
- `app/Console/Commands/ESPN/AbstractSyncMissingPlayerStatsGameDetailsCommand.php`
- `app/Console/Commands/ESPN/MLB/SyncGameDetailsCommand.php`
- `app/Actions/Validation/Checks/FinalizedDataCompletenessCheck.php`
- `tests/Feature/MLB/MlbFinalScoreReconciliationTest.php`

### Reconciliation Source Of Truth

The reconciliation source is:

- home score: `mlb_team_stats.runs` where `team_type = home` and `team_id = mlb_games.home_team_id`
- away score: `mlb_team_stats.runs` where `team_type = away` and `team_id = mlb_games.away_team_id`

The action only reconciles `STATUS_FINAL` games.

It skips scheduled, live, delayed, postponed, suspended, cancelled, and unknown states.

### Command Added

Added:

```bash
php artisan mlb:reconcile-final-scores
```

Options:

```text
--season=2026
--from=YYYY-MM-DD
--to=YYYY-MM-DD
--dry-run
--force
```

Default mode applies safe missing-score reconciliation. `--dry-run` reports what would change without writing. `--force` is required to overwrite score conflicts.

The command reports:

- final games scanned
- games already matching team stats
- games updated
- skipped games
- missing team stat run skips
- conflicts
- remaining final games with null scores
- remaining reconstructable final games with null scores

### Sync Flow Integration

`SyncTeamStats` now calls reconciliation after team stats are synced for a game.

`SyncGameDetails` now also calls reconciliation after the full detail sync completes.

The MLB game-details command now treats final games with null score columns as incomplete enough to be selected for detail repair.

### Validation / Sentinel Behavior

`validation_finalized_data_completeness` now checks final game score consistency.

It fails when:

- a final MLB game has null score columns and both team stat runs are available
- existing `mlb_games` scores conflict with team stat runs

It reports but does not hard-fail the score-reconciliation condition when:

- final game scores are null
- team stat runs are also incomplete

Those rows still need game-detail/stat repair before they can be reconciled.

### Tests Added

Added `tests/Feature/MLB/MlbFinalScoreReconciliationTest.php`.

Coverage:

- fills missing final scores from home/away team stat runs
- skips scheduled, live, delayed, postponed, and suspended games
- idempotent when scores already match team stat runs
- conflict detection does not overwrite by default
- partial missing score fill when the present score matches team stats
- skips missing team stat runs
- command dry-run does not write
- command apply mode writes scores
- validation fails on reconstructable missing final scores
- validation reports non-reconstructable missing final scores without failing the reconciliation condition

### Before / After Local Counts

Before implementation, the imported production snapshot showed:

```text
final_regular_games = 1111
missing_score_games = 1068
scored_games = 43
reconstructable_missing_score_games = 1068
```

After implementing the command, a local dry-run against the current app database showed:

```text
final_games_scanned = 1559
games_already_matching = 490
games_that_would_be_updated = 1068
conflicts = 1
remaining_final_games_with_null_scores = 1068
remaining_reconstructable_final_games_with_null_scores = 1068
```

Because this was a dry-run, remaining null-score counts are expected to stay unchanged until apply mode is run.

The command is expected to fill all reconstructable rows when run against production:

```bash
php8.4 artisan mlb:reconcile-final-scores --season=2026
```

Run dry-run first:

```bash
php8.4 artisan mlb:reconcile-final-scores --season=2026 --dry-run
```

### Validation Commands

| Command | Result | Notes |
|---|---|---|
| `php artisan test tests/Feature/MLB/MlbFinalScoreReconciliationTest.php` | Passed | 14 tests, 52 assertions. |
| `php artisan test tests/Feature/MLB/MlbFinalScoreReconciliationTest.php tests/Feature/MLB/MlbBackendSafetyTest.php tests/Feature/MLB/MlbPredictionRecommendationContractTest.php` | Passed | 26 tests, 123 assertions. |
| `php artisan mlb:reconcile-final-scores --season=2026 --dry-run` | Passed | Scanned 1,559 final games; 1,068 would be updated; 1 conflict found. |

### Remaining Limitations

- Score source is returned by the action/command but not persisted on `mlb_games`; no `score_source` or `score_reconciled_at` columns were added in this pass.
- Scores are reconciled only from team stat runs, not player stats or play-by-play.
- Conflicts require explicit `--force`; the default path will not overwrite existing non-null scores.
- Backtest/calibration reports still need to be rerun after production score reconciliation.

### Follow-Up Work

1. Run `mlb:reconcile-final-scores --season=2026 --dry-run` on production.
2. Run the apply command if dry-run counts match expectations.
3. Re-run MLB grading/calibration/backtest reports after scores are filled.
4. Consider adding persisted score provenance columns later if audit requirements grow.

## MLB Prediction Calculation Soundness Review

### Calculation Contract

| Field | Meaning | Formula Source | Inputs | Expected Range | Stored Where | Backtest Use | Risk |
|---|---|---|---|---|---|---|---|
| `predicted_winner` | Team with the higher model win probability. | Derived from `win_probability >= 0.5`. | Stored prediction, game teams. | home/away team label. | Derived in explain/API layers, not stored as a column. | Winner accuracy and recommendation display. | Ties default to home because 0.500 is home-side by convention. |
| `home_win_probability` | Probability that the home team wins. | `1 / (1 + exp(-predicted_spread / coefficient))`. | Final model spread, `mlb.prediction.spread_to_probability_coefficient`. | `0.000-1.000`. | `mlb_predictions.win_probability`. | Winner accuracy, Brier/log-loss audit, moneyline edge. | Coefficient still needs calibration after score reconciliation. |
| `away_win_probability` | Probability that the away team wins. | `1 - home_win_probability`. | Home win probability. | `0.000-1.000`; sum with home approximately `1.000`. | Derived. | Winner accuracy and display. | Rounding can create tiny display deltas. |
| `predicted_home_score` | Derived projected home runs. | `(predicted_total + predicted_spread) / 2`. | Model total and home-perspective spread. | `>= 0`. | Derived by audit/explain command; not stored. | Internal consistency check only. | Invalid if total is lower than absolute spread. |
| `predicted_away_score` | Derived projected away runs. | `(predicted_total - predicted_spread) / 2`. | Model total and home-perspective spread. | `>= 0`. | Derived by audit/explain command; not stored. | Internal consistency check only. | Same derived-score limitation as home. |
| `predicted_spread` | Projected home margin in runs. Positive favors home; negative favors away. | Team/pitcher Elo blend, home-field, context, injuries, park/weather adjustments. | Team Elo, pitcher Elo, team metrics, historical/situational context, injuries, park/weather. | Usually low single digits; must be finite. | `mlb_predictions.predicted_spread`. | Spread error, winner direction, run-line diagnostics. | Sign convention must stay documented because market spread uses Vegas sign. |
| `predicted_total` | Projected combined game runs. | Base run model plus Elo, context, injury, park, and weather total adjustments. | Combined Elo, total model config, situational context, injuries, park/weather. | Positive finite value. | `mlb_predictions.predicted_total`. | Total error and totals diagnostics. | Park/weather weights are not tuning-safe until backtest sample is clean. |
| `confidence` | Predicted winner probability on a `50-100` scale. | `max(home_wp, away_wp) * 100`. | Home win probability. | `50-100` for valid generated rows. | `mlb_predictions.confidence_score`. | Confidence buckets and recommendation risk controls. | Confidence is model certainty, not betting edge. |
| `confidence_label` | Display bucket for confidence. | API/audit label: `high >= 75`, `medium >= 60`, else `low`. | Confidence score. | `low`, `medium`, `high`, `unknown`. | Derived in API/audit layers. | Calibration bucket review. | Labels are descriptive only; they should not imply profitability. |
| `model_version` | Rules/model identity. | `GeneratePrediction::modelVersion()`. | Generator constants/inheritance. | Non-empty string. | `mlb_predictions.model_version`. | Calibration grouping. | Missing version blocks reliable model comparison. |
| `feature_version` | Feature-set identity. | `GeneratePrediction::featureVersion()`, currently `core-v3`. | Generator constant. | Non-empty string. | `mlb_predictions.feature_version`. | Calibration grouping. | Historical rows with mixed feature versions need separate buckets. |
| `blend_version` | Blend/scoring identity. | `GeneratePrediction::blendVersion()`. | Generator constants/inheritance. | Non-empty string. | `mlb_predictions.blend_version`. | Calibration grouping. | Missing blend version makes tuning attribution messy. |
| `model_metadata` | Explainability and safety context. | Built by `GeneratePrediction::buildModelMetadata()`. | Pitchers, season context, market context, PIT safety, park/weather, injuries. | JSON object. | `mlb_predictions.model_metadata`. | Explain/audit/report commands. | Some source timestamps remain incomplete, especially current odds/weather history. |

### Input Lineage Map

| Input Area | Source | Point-In-Time Handling | Used In Core Prediction? | Used In Recommendation? |
|---|---|---|---|---|
| Team Elo | `mlb_elo_ratings`, fallback `mlb_teams.elo_rating` | `MlbRegularSeasonWindow::applyCarryoverFilter()` excludes future dates. | Yes | Indirectly through model output. |
| Pitcher Elo | `mlb_pitcher_elo_ratings`, probable pitcher, depth chart, recent team average, league average fallback | Carryover filter by season/date. | Yes | Indirectly and as pitcher confidence/risk. |
| Team metrics | `mlb_team_metrics` | Historical mode uses `calculation_date <= game_date` or prior-season fallback. | Yes | Indirectly. |
| Historical priors | prior MLB game/stat context | Uses dated game graph through service. | Yes | Diagnostic metadata. |
| Situational context | bullpen, handedness, starter form, advanced ratings services | Depends on service data availability; needs continued snapshot review. | Yes | Diagnostic metadata. |
| Injuries | `mlb_player_injuries` and depth-chart impact | Filters active injuries by game date and source timestamp when present. | Yes | Risk and confidence context. |
| Park factors | `config/mlb.php` venue map | Static config. | Yes, totals only. | Ballpark signal display. |
| Weather | `mlb_game_weather` | Uses stored row; `observed_at` is reported by audit when present. | Yes, totals only. | Ballpark/weather signal display. |
| Market odds | current `mlb_games.odds_data` or pregame `game_odds_snapshots` in historical mode | Historical mode requires captured snapshot before start; current mode reports `odds_updated_at`. | No core probability override; stored as market context only. | Yes, edges/recommendations/signals. |
| Live prediction fields | `mlb_predictions.live_*` | Written by live update action only. | No for pregame generation/backtests. | Live monitor display only. |

### Formula Verification Results

- Spread convention is documented and tested: positive `predicted_spread` means the home team is favored by that many runs.
- Win probability is a logistic transform of the final model spread and is bounded by tests.
- Confidence is the predicted winner probability on a `50-100` style scale.
- Predicted team scores are derived, not stored: home `(total + spread) / 2`, away `(total - spread) / 2`.
- Market odds are captured after core outputs and are used for recommendation/edge logic, not to override `win_probability`.
- Park and weather adjustments affect totals, not core win probability directly.

### Prediction Invariant Checks

Added `app/Services/MLB/MlbPredictionCalculationAuditService.php`.

Hard failures:

- missing or non-finite probability, spread, total, or confidence
- home win probability outside `0-1`
- probabilities not summing to `1`
- spread sign disagreeing with win probability side
- derived team score would be negative
- confidence outside `0-100`
- missing `model_version`, `feature_version`, or `blend_version`

Warnings:

- missing feature snapshot
- market context not proven pregame-safe
- weather applied without `observed_at`
- team metric point-in-time limitation
- live fields present, while explicitly not core pregame inputs

### Golden Fixture Test Results

Added `tests/Feature/MLB/MlbPredictionCalculationSoundnessTest.php`.

Covered fixtures:

- generated predictions are mathematically coherent
- missing probable pitchers fall back to league-average pitcher Elo with explicit metadata
- moneyline odds changes do not alter core probability/spread/total
- live fields do not alter regenerated pregame outputs
- explain command emits structured JSON
- audit command fails on hard invariant violations

Existing MLB tests already cover:

- configurable spread/total/probability formulas
- home-field source separation
- park factor total boosts
- weather total boosts and roof-status guardrails
- historical team metrics excluding future rows
- pregame odds snapshots excluding post-start odds
- live monitor recommendations not counting as official pregame bets

### Point-In-Time Safety Results

Current answer: **Partially**.

What is safe:

- Historical team metrics exclude future `calculation_date` rows when available.
- Historical market context uses `game_odds_snapshots.captured_at < scheduled_start_at`.
- Final score fields are not model inputs.
- Live prediction fields are not model inputs for pregame generation.

Remaining blockers:

- Some current-season team metrics are mutable rows, so historical safety depends on `calculation_date` coverage.
- Weather rows have `observed_at`, but the model does not yet require it before applying weather.
- Some injury/depth-chart inputs depend on provider timestamps that may be incomplete.
- Full calibration is not reliable until final MLB scores are reconciled in production and predictions are regraded.

### Market Usage

| Market Input | Used In Core Prediction? | Used In Recommendation? | Used In Display? | Risk |
|---|---|---|---|---|
| Moneyline odds | No | Yes, raw/no-vig edge and moneyline classification. | Yes | Needs fresh odds timestamps for strict pregame trust. |
| Run line/spread | No core probability override. Captured as `vegas_spread`. | Yes, run-line edge diagnostics; run-line bets remain disabled by config. | Yes | Vegas sign is inverse of model home-margin convention. |
| Total | No core total override. Captured as market context. | Yes, total edge diagnostics; total bets remain disabled by config. | Yes | Requires market timestamp and enough backtest sample before activation. |
| Closing odds | No | Historical audit/CLV only when snapshots exist. | Report only | High severity if closing odds enter pregame generation; current reviewed path does not do that. |

### Live/Pregame Isolation Review

Reviewed fields:

- `live_win_probability`
- `live_predicted_spread`
- `live_predicted_total`
- `live_outs_remaining`
- `live_updated_at`

Result:

- Pregame `GeneratePrediction` does not read live fields.
- Live monitor rows are separated in `MlbBettingSignalService::liveSignals()`.
- Existing recommendation tests prove live monitor output does not count as an official pregame bet.
- New soundness test proves stored live fields do not alter regenerated pregame outputs.

### Baseline Comparison

Baseline comparison is available through the calibration/audit path after score reconciliation and regrading.

| Method | Rows | Accuracy | Brier | Log Loss | Notes |
|---|---:|---:|---:|---:|---|
| Current model | Pending | Pending | Pending | Pending | Requires applying final score reconciliation and rerunning grading. |
| Market no-vig | Pending | Pending | Pending | Pending | Requires pregame-safe h2h snapshots. |
| Favorite | Pending | Pending | Pending | Pending | Requires market side extraction. |
| Home team | Pending | Pending | Pending | Pending | Can be computed after final scores are reconciled. |
| Elo-only | Pending | Pending | Pending | Pending | Not separated into its own report yet. |

### Calibration Readiness Status

Status: **Not tuning-ready yet**.

Reasons:

- Production final scores must be reconciled before any historical MLB calibration is trusted.
- Calibration should be grouped by `model_version`, `feature_version`, and `blend_version`.
- Buckets should include probability, confidence label, recommendation type, raw edge, no-vig edge, signal score, home/away, favorite/underdog, pitcher confirmation, and risk flags.
- Buckets with small samples must be marked as too small; no profitability claim should be made from thin slices.

### Calculation Risks

| Risk | Severity | Evidence | Impact | Recommended Fix Before Tuning |
|---|---|---|---|---|
| Final score gaps | High | 1,068 reconstructable 2026 final MLB score gaps found in production snapshot. | Backtests/grades undercount or exclude most completed games. | Run reconciliation apply command and regrade. |
| Mutable team metric rows | Medium | Historical safety falls back when dated current-season metric is unavailable. | Historical prediction may lose current-season context or rely on prior-season fallback. | Expand immutable team metric snapshots. |
| Market odds timestamp missing | Medium | Current mode reports `odds_updated_at`; strict historical mode needs snapshots. | Strict pregame market edge may be unavailable. | Keep building pregame odds snapshots. |
| Weather timestamp missing | Medium | Weather can apply even when `observed_at` is absent. | Pregame weather safety is partial. | Require or warn harder on missing `observed_at` when weather adjustment is nonzero. |
| Spread sign ambiguity | Low | Sign conversion is documented and tested. | UI/reporting confusion if new code ignores convention. | Keep sign tests and use `MlbMarketSpread`. |
| Live fields mixed with pregame | Low | Tests show live fields do not alter generation; live monitor is separate. | Would inflate official picks if mixed later. | Keep audit warning and recommendation tests. |
| Missing calibration by model version | Medium | Calibration command filters feature version and reports versions, but deeper grouping is still pending. | Tuning could blend incompatible model eras. | Add model/blend grouping before threshold tuning. |

## MLB Underperformance Diagnostic Report

### Status

Status: **Diagnostic complete enough to identify issues; not tuning-ready yet**.

This pass intentionally did not change model weights, formulas, recommendation thresholds, confidence thresholds, feature inputs, or betting rules. It added diagnostic reporting around the existing `mlb:report-calibration` command so underperformance can be separated into model accuracy, market comparison, confidence separation, recommendation quality, pitcher source quality, park/weather adjustments, date/month effects, and calculation safety checks.

### Commands

| Command | Purpose |
|---|---|
| `php artisan mlb:report-calibration --season=2026 --feature-version=core-v3 --limit=2500 --diagnostics --compare-market` | Human-readable calibration plus underperformance diagnostics. |
| `php artisan mlb:report-calibration --season=2026 --feature-version=core-v3 --limit=2500 --diagnostics --compare-market --json` | Structured JSON for deeper review or export. |
| `php artisan mlb:audit-prediction-calculations --season=2026 --strict-pregame --sample=20` | Independent calculation-soundness audit. |

### Local Diagnostic Snapshot

Local database sample: 43 graded `core-v3` predictions.

| Baseline | Rows | Winner % | Spread MAE | Total MAE | Brier | Log Loss |
|---|---:|---:|---:|---:|---:|---:|
| Current model | 43 | 44.2% | 4.16 | 2.71 | 0.2484 | 0.6900 |
| Market favorite/spread/total | 43 | 62.8% | 3.90 | 3.00 | 0.1485 | 0.4526 |
| Home team | 43 | 55.8% | n/a | n/a | 0.2500 | 0.6931 |

Production latest-500 sample before this diagnostic work showed the same broad concern: model winner accuracy was 49.2%, market spread MAE was better than model spread MAE, and the `lean` bucket underperformed `no_play`.

### Breakdown Findings

| Area | Finding | Interpretation |
|---|---|---|
| Model vs market | Market winner baseline beat the model in the local sample. | The model is not adding enough directional winner value yet. Treat market comparison as a diagnostic baseline, not as a model input change. |
| Model-market disagreement | `model_home_market_away` went 14.3% locally while the market side went 71.4%. | Disagreement buckets are the first place to investigate before thresholds are changed. |
| Spread buckets | Pick'em bucket went 26.1% locally while market side was 65.2%. | Near-pick'em predictions are not reliably separating sides. |
| Total buckets | Total MAE was competitive locally, but production showed model total bias around +1.00. | Totals need bias review by month, park, weather, and pitcher source before totals recommendations are expanded. |
| Recommendation type | Local `bet` and `lean` slices were thin and did not beat `no_play`; production `lean` was also weak. | Do not promote recommendations until recommendation logic is recalibrated on trusted pregame rows. |
| Confidence | Local confidence range was 50.0 to 58.2; production latest-500 range was 50.0 to 60.8. | Confidence is compressed and should be treated as descriptive, not proof of edge. |
| Pitcher source | `team_recent_average_fallback` underperformed `probable_starter` locally. | Confirmed starter quality matters. Fallback rows should carry visible risk and may need separate thresholds later. |
| Park/weather | Diagnostics now bucket by park, weather, and combined adjustment. | Use these buckets to find whether adjustments are helping totals or creating bias. |

### Calculation Bug Checks

| Check | Current Result | Action |
|---|---|---|
| Home/away mapping | Passing in local diagnostic run. | Keep covered by audit tests. |
| Spread sign convention | Passing for market comparisons. | Continue using home-margin convention internally and invert Vegas spread to `market_home_margin`. |
| Market spread sign | Passing in diagnostic command. | Keep sign conversion centralized. |
| Total calculation | Passing. | No action. |
| Duplicate rows | Passing in local diagnostic run. | No action. |
| Live rows excluded | Passing in local diagnostic run. | Keep live prediction data out of pregame calibration. |
| Winner/spread side consistency | Passing after diagnostic correction. | The first diagnostic pass treated rounded `0.500` probabilities as home picks, which made tiny away spreads like `-0.1` look inverted. The report now derives pick side from spread sign and only fails when probability is truly on the opposite side. |

### Likely Root Causes

- Directional winner signal is weak relative to market baseline.
- Recommendation buckets do not separate value yet; `lean` is not trustworthy as a betting label.
- Confidence is too compressed to support strong labels.
- Market disagreement buckets are where the model is getting punished hardest.
- Pitcher fallback rows need separate handling from confirmed probable-starter rows.
- Strict pregame market proof remains incomplete for many historical rows, so strict calibration may exclude too much until snapshots are complete.

### Recommended Next Steps

1. Run the corrected JSON diagnostic on production and archive the output before changing formulas.
2. Add small-sample warnings to recommendation output before using `bet` or `lean` labels publicly.
3. Review model-market disagreement rows one by one with `mlb:explain-prediction`.
4. Separate confirmed-starter rows from fallback-starter rows in future recommendation thresholds.
5. Only after the above is stable, begin tuning on trusted pregame rows.

### Active Recommendation Guard

Active MLB pregame `bet` and `lean` promotions are guarded by default through `mlb.signals.bet_filter.calibration_guard_enabled=true` and `mlb.signals.bet_filter.promotions_validated=false`.

This does **not** change historical/final diagnostic reporting. The calibration report can still show what the candidate filter would have classified, which is necessary to measure whether the filter is improving. For upcoming games, however, unvalidated promotions are downgraded to `no_play` with `recommendation_calibration_unvalidated` until the filter proves it can beat `no_play` and market baselines on trusted pregame rows.

## MLB Recommendation Protection And Shadow Validation

MLB recommendations are treated as protected research until readiness passes. The API recommendation contract separates:

- `recommendation.public`: what product surfaces may show as public/promotion-safe.
- `recommendation.candidate`: the shadow candidate produced by the filter for research and backtesting.
- `recommendation.promotion`: promotion status, validation flags, and block reasons.

Public labels such as `Model Bet`, `Model Lean`, `Best Bets`, and `Top Betting Signal` must not be displayed from candidate/shadow data while promotion is blocked. Candidate classifications remain available for admin review, calibration reports, and readiness checks.

The signals endpoint follows the same rule:

- `recommended_bets` contains only public promoted bets.
- `shadow_recommended_bets` contains research candidates.
- `bet_filter.promotion` explains whether public promotion is enabled or blocked.

Run readiness before enabling public promotion:

```bash
php artisan mlb:validate-recommendation-readiness --season=2026 --feature-version=core-v3 --limit=2500
```

Expected current posture: fail closed. Known blocking themes include model underperformance versus market baseline, weak candidate bucket performance, compressed confidence, total bias versus market, and poor model-market disagreement performance. These should be fixed and revalidated before `MLB_BET_FILTER_PROMOTIONS_VALIDATED=true` is considered.

## MLB Usability Deep Dive And Market-Aware Shadow Model

### Summary

MLB public recommendations remain disabled. The next usability path is not to tune thresholds into production; it is to measure whether a market-aware shadow layer can make the MLB experience more honest and useful while public `bet` and `lean` labels stay fail-closed.

The research command is:

```bash
php artisan mlb:research-market-blends --season=2026 --feature-version=core-v3 --limit=2500
```

For automation or export:

```bash
php artisan mlb:research-market-blends --season=2026 --feature-version=core-v3 --limit=2500 --json
```

For promotion-quality research, require strict pregame market rows:

```bash
php artisan mlb:research-market-blends --season=2026 --feature-version=core-v3 --limit=2500 --strict-pregame
```

This command is report-only. It does not update predictions, recommendations, model metadata, promotion flags, or public API behavior.

### Current Recommendation Protection State

Latest production readiness evidence:

| Metric | Current Evidence |
|---|---:|
| Public promoted rows | 0 |
| Candidate rows | 0 |
| Model winner accuracy | 33.3% |
| Home baseline | 50.0% |
| Market baseline | 67.6% |
| Pure model research winner rate | 37.8% |
| 25% model / 75% market research winner rate | 64.9% |

Interpretation: MLB model-only recommendations are not product-ready. The market-heavy shadow result is interesting enough to study, but not proof that public picks should be enabled.

### Why Pure Model Recommendations Are Not Usable

- Model directional winner performance is below the home-team baseline and market baseline in the latest readiness sample.
- Model-market disagreement buckets are especially weak.
- Confidence is compressed and does not yet separate strong plays from no-plays.
- Recommendation buckets have not proven better than `no_play`.
- Stale odds and missing timestamp proof must block promotion.

### Product Definition Of Usable MLB

Mode 1: **Predictions Only**

- Show projected score, win probability, spread, total, and matchup context.
- Do not show public betting labels.
- Safe as long as copy does not imply a validated betting edge.

Mode 2: **Market-Aware Tracking**

- Show where the model agrees or disagrees with market direction.
- Label as tracking/research.
- Use strict timestamp warnings when odds are not proven pregame-safe.

Mode 3: **Consensus Signal**

- Show non-promotional consensus when model and market agree.
- Keep disagreement rows as watch/review only.
- Require confirmed pitchers and fresh odds.

Mode 4: **Protected Shadow Betting Research**

- Run candidate rules in reports and admin screens only.
- Compare market-heavy blends, total corrections, and confidence recalibration.
- Keep all public recommendation output disabled.

Mode 5: **Future Public Recommendations**

- Only consider after enough strict-pregame rows prove candidates beat home, market, and no-play baselines.
- Require stable monthly/walk-forward results, not a single aggregate sample.
- Keep promotion flags manual and fail-closed.

### Market-Aware Blend Results

The research evaluator tests model weights:

| Model Weight | Market Weight | Purpose |
|---:|---:|---|
| 1.00 | 0.00 | Pure model benchmark. |
| 0.75 | 0.25 | Light market correction. |
| 0.50 | 0.50 | Equal blend. |
| 0.25 | 0.75 | Market-heavy shadow candidate. |
| 0.10 | 0.90 | Near-market tracking. |
| 0.00 | 1.00 | Pure market benchmark. |

The latest readiness research strongly suggests a market-heavy blend is more plausible than pure model winner selection. It is still research until strict pregame safety, sample size, and walk-forward stability are proven.

### Walk-Forward Results

The command reports `Blend Performance By Month` so we can see whether the blend works across time instead of only in one aggregate bucket. Any month with a small sample should be treated as a stability warning, not proof.

### Model-Market Disagreement Deep Dive

The report separates:

- model and market agree
- model home / market away
- model away / market home
- spread gap buckets
- probability gap buckets

Current posture: disagreement should remain suppressed from public recommendation logic until it proves it can beat the market baseline.

### Research Candidate Rule Comparison

The report compares:

- pure model
- market agreement only
- 25% model / 75% market
- consensus plus edge
- disagreement suppressed

These are research candidates, not product labels. Public output should continue to read as predictions/tracking until a candidate rule passes readiness.

The research report also exposes recommendation visibility separately:

- `Public Recommendation Buckets`: product-safe output, expected to remain `no_play` while promotion is blocked.
- `Candidate Recommendation Buckets`: shadow classifications used for research only.
- `Promotion Block Reasons`: why a candidate cannot become public.
- `candidate_samples`: sampled research-only candidates with public type, candidate type, raw edge, no-vig edge, score, risk flags, reason codes, and promotion block reasons.

This is the correct state: public rows can remain `no_play` while candidate rows are still measurable for historical/final diagnostics.

### Total Bias Research

The report includes a total correction grid:

- current model
- model minus 0.50
- model minus 0.75
- model minus 1.00
- model minus 1.25
- model minus 1.50
- market total baseline

It also breaks total bias down by month, park adjustment bucket, weather adjustment bucket, predicted total bucket, and market-total gap bucket. Do not activate totals recommendations until these buckets show stable improvement.

### Confidence Recalibration Research

The report compares:

- current model confidence
- market probability confidence
- market-aware blended confidence
- agreement-adjusted confidence

Goal: confidence should become monotonic and meaningful. Until then, high-confidence public language should stay disabled for MLB bets.

### Point-In-Time Safety For Market Blends

The report flags:

- missing market odds
- missing odds timestamps
- odds after first pitch
- stale odds
- missing game start time
- missing prediction timestamp
- live-only rows
- postponed, suspended, or canceled rows

If odds timestamps are incomplete, the report emits a strict warning. Market-aware results with incomplete timestamp proof may be useful for research, but they are not strict-pregame safe.

The report also separates:

- `market_rows`: all rows with extractable market prices.
- `strict_market_rows`: rows with market prices and no point-in-time safety flags.
- `analysis_rows`: rows used by the performance tables.
- `analysis_mode`: either all flagged market rows or strict pregame market rows.

Use `--strict-pregame` when deciding whether a market-aware rule is promotion-quality. General market rows can show whether the market was a useful benchmark, but rows updated after first pitch or without timestamp proof are not valid pregame evidence.

The research command should prefer immutable `game_odds_snapshots` captured before first pitch. It may fall back to the current `mlb_games.odds_data` row only when no pregame snapshot exists, and that fallback must remain subject to timestamp safety flags.

### Shadow Model Versioning

Current shadow version: `mlb_market_aware_shadow_v1`.

This version is intentionally not written to prediction rows yet. If it graduates later, it should be persisted separately from `model_version`, `feature_version`, and `blend_version` so production prediction history stays auditable.

### Proposed Protected UX

- Keep cards focused on projected score, win probability, spread, total, pitchers, odds freshness, and model-market agreement.
- Use `Research`, `Tracking`, or `Consensus Watch` language instead of `Bet`, `Lean`, or `Official`.
- Show stale/missing odds warnings clearly.
- Hide public best-bet modules when promotion remains blocked.

### Tests Added

- `tests/Feature/MLB/ResearchMarketBlendsCommandTest.php`
  - proves the command emits the market-aware shadow research contract
  - proves the required blend weights are present
  - proves missing odds timestamps generate strict warnings
  - proves public and candidate recommendation buckets are reported separately
  - proves promotion block reasons are counted
  - proves candidate samples remain research-only
  - proves predictions are not mutated
  - proves public promoted rows remain zero

### Validation Commands

```bash
php artisan test tests/Feature/MLB/ResearchMarketBlendsCommandTest.php
php artisan test tests/Feature/MLB/ResearchMarketBlendsCommandTest.php tests/Feature/MLB/MlbPredictionRecommendationContractTest.php tests/Feature/MLB/ReportCalibrationCommandTest.php
php artisan mlb:research-market-blends --season=2026 --feature-version=core-v3 --limit=2500
php artisan mlb:research-market-blends --season=2026 --feature-version=core-v3 --limit=2500 --strict-pregame
php artisan mlb:validate-recommendation-readiness --season=2026 --feature-version=core-v3 --limit=2500
```

### Recommended Path To Usability

1. Keep public MLB recommendations disabled.
2. Run market-aware blend research on production after each final score and grading pass.
3. Use monthly/walk-forward stability as the first filter.
4. Suppress model-market disagreement from candidates until proven.
5. Use consensus and market-heavy tracking as the first possible product layer.
6. Only discuss public picks after strict-pregame samples are large enough and candidates beat market/no-play baselines.

The next research layer is documented in `docs/mlb-correlation-research-and-data-enrichment-plan.md`. It defines the report-only correlation work needed to identify model success/failure drivers, total bias drivers, model-market disagreement drivers, missing data, pregame-safe features, and the data enrichment order before any public MLB recommendation promotion is considered.

### What Must Stay Disabled

- Public MLB `bet` labels.
- Public MLB `lean` labels.
- Best-bet modules sourced from shadow candidates.
- Any auto-promotion from stale odds, missing timestamps, live-only rows, or model-market disagreement rows.

## Prediction Calculation Soundness Recommendations

### Must Fix Before Tuning

- Apply `mlb:reconcile-final-scores --season=2026` after dry-run review.
- Re-run MLB grading after score reconciliation.
- Run `mlb:audit-prediction-calculations --season=2026 --strict-pregame`.
- Treat any hard audit invariant failure as blocking.
- Keep market odds out of core win probability unless explicitly re-designed and retested.

### Should Fix Soon

- Add baseline comparison rows for home team, market favorite/no-vig, and Elo-only.
- Require stronger weather timestamp safety before applying weather in historical mode.
- Add calibration grouping by `model_version`, `feature_version`, and `blend_version`.
- Add immutable current-season team metric snapshots where possible.
- Add report buckets for pitcher confirmation and risk flags.

### Safe To Tune Later

- Elo weights.
- Pitcher weights.
- Home-field advantage.
- Spread-to-probability coefficient.
- Park/weather weights.
- Bet thresholds.
- Signal score weights.
- Run-line activation.
- Totals activation.

### Soundness Commands

| Command | Purpose |
|---|---|
| `php artisan mlb:explain-prediction {game_id} --json` | Explain one stored MLB prediction, inputs, adjustments, outputs, warnings, and hard failures. |
| `php artisan mlb:audit-prediction-calculations --season=2026 --strict-pregame` | Audit stored MLB predictions for hard math invariants and safety warnings. |
| `php artisan mlb:reconcile-final-scores --season=2026 --dry-run` | Confirm final score reconciliation status before calibration/backtesting. |

### Validation Commands

| Command | Result | Notes |
|---|---|---|
| `php artisan test tests/Feature/MLB/MlbPredictionCalculationSoundnessTest.php` | Passed | 6 tests, 31 assertions. |
| `php artisan test tests/Feature/MLB/MlbPredictionCalculationSoundnessTest.php tests/Feature/MLB/MlbFinalScoreReconciliationTest.php tests/Feature/MLB/MlbBackendSafetyTest.php tests/Feature/MLB/MlbPredictionRecommendationContractTest.php tests/Feature/MLB/ReportCalibrationCommandTest.php` | Passed | 33 tests, 166 assertions. |
| `php artisan mlb:audit-prediction-calculations --season=2026 --strict-pregame --sample=5` | Passed with warnings | Scanned 2,446 predictions; 0 hard math failures; 1,068 final games excluded due missing scores; 1,361 rows missing source timestamp proof; 3 rows had live fields present. |
| `php artisan mlb:explain-prediction 5357 --json` | Passed | Returned structured inputs, adjustments, outputs, safety warnings, and no hard failures for sample prediction 7612. |
