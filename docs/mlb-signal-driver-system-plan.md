# MLB Signal Driver System Implementation Plan

Last updated: 2026-06-21

## Summary

Build a disciplined MLB signal driver layer that explains why a candidate exists, improves candidate scoring, and keeps the daily board readable. This is not a tag expansion project. The system should group signals into bettor-friendly sections and show only the strongest useful context on the card, with deeper diagnostics available in the matchup drawer.

The first implementation pass should remain tracking-only and promotion-gated. It should improve internal scoring, API consistency, and UI explanation quality without enabling public MLB bets or leans.

## Product Rules

- Show grouped signal context, not a pile of raw reason codes.
- Cards should show a maximum of 4 visible groups.
- Drawers can show all groups, driver details, source timestamps, and risk flags.
- Pregame cards must not show live-only or postgame-only signals.
- Live prediction context must be explicitly labeled as live and must not rewrite the locked pregame pick.
- Public promotion remains blocked until readiness and calibration gates pass.

Primary display groups:

| Group | Purpose | Card Use |
|---|---|---|
| Mound | Starter skill, confirmation, rest, and pitcher change risk. | High priority for moneyline, F5, F3, pitcher props, totals. |
| Lineup | Confirmed lineup quality, key bats, platoon edge. | High priority once lineups are confirmed. |
| Market | Price quality, no-vig edge, line movement, stale odds. | High priority for all markets. |
| Run Environment | Park, weather, roof, and total context. | High priority for totals and props. |
| Bullpen | Bullpen quality, fatigue, late-inning protection. | Decides full-game vs F5/F3. |
| Risk | Promotion blockers and caution flags. | Always show when present. |

Deferred groups:

| Group | Defer Until |
|---|---|
| Umpire | Plate umpire assignment and reliable tendency data are available pregame. |
| Regression | Quality-of-contact data is captured with point-in-time safety. |
| Props | Game-level signal groups are stable and prop-specific features are normalized. |
| Live | Live model and live data rules are separated from pregame payloads. |

## Existing Code To Use

Backend:

- `app/Services/MLB/Picks/MlbDailyPickService.php`
- `app/Services/MLB/Picks/MlbPickCandidateData.php`
- `app/Services/MLB/Picks/MlbPickCandidateScorer.php`
- `app/Services/MLB/Picks/MlbPickExplanationService.php`
- `app/Services/MLB/Picks/MlbMoneylineCandidateBuilder.php`
- `app/Services/MLB/Picks/MlbRunLineCandidateBuilder.php`
- `app/Services/MLB/Picks/MlbTotalCandidateBuilder.php`
- `app/Services/MLB/Picks/MlbFirstFiveCandidateBuilder.php`
- `app/Services/MLB/Picks/MlbFirstThreeCandidateBuilder.php`
- `app/Services/MLB/Picks/MlbPlayerPropCandidateBuilder.php`
- `app/Services/MLB/MlbMarketAwareProjectionService.php`
- `app/Services/MLB/BullpenRatingService.php`
- `app/Services/MLB/GameWeatherService.php`
- `app/Http/Resources/Api/V2/MlbPickCandidateResource.php`
- `app/Http/Resources/Api/V2/SportPredictionResource.php`

Frontend:

- `resources/js/pages/MLB/Predictions.vue`
- `resources/js/components/mlb/MlbMatchupBoard.vue`
- `resources/js/components/mlb/MlbMatchupCard.vue`
- `resources/js/components/mlb/MlbMatchupDetailDrawer.vue`
- `resources/js/types/mlb-daily-picks.ts`
- `resources/js/lib/mlbRecommendationLabels.ts`

Supporting docs:

- `docs/mlb_prediction_review_and_tuning_work_order.md`
- `docs/mlb-correlation-research-and-data-enrichment-plan.md`
- `docs/odds-snapshots-design.md`

## Existing Data Inventory

| Signal Family | Current Source | Current Storage | Status |
|---|---|---|---|
| Game and teams | ESPN sync | `mlb_games`, `mlb_teams` | Available |
| Probable pitchers | ESPN / game details | `mlb_games.probable_*_pitcher_espn_id` | Available, needs confirmation quality |
| Prediction output | Internal model | `mlb_predictions` | Available |
| Market odds | Odds API / snapshots | `mlb_games.odds_data`, `game_odds_snapshots` | Available, timestamp safety required |
| Weather | Weather sync | `mlb_game_weather` | Available, needs grouped run environment summary |
| Bullpen quality | Internal service | `mlb_bullpen_ratings` | Available |
| Candidate results | Pick candidate pipeline | `mlb_pick_candidates` | Available |
| CLV and audit | Snapshot/backtest paths | `mlb_bet_filter_results`, snapshots | Partially available |
| Injuries | ESPN injuries | `mlb_player_injuries` | Available but not yet first-pass group |
| Player props | Odds API | `mlb_player_props` | Available but separate from first-pass game signals |

## Missing Or Weak Data

| Need | Why It Matters | Suggested Source / Next Step | Phase |
|---|---|---|---|
| Starter FIP/xFIP/SIERA | Better pitcher skill than ERA. | Add provider or derived table from official/stat provider. | Phase 2 |
| Starter K-BB%, HR rate, xwOBA allowed | True skill and quality contact. | Statcast/FanGraphs-style import or derived stats. | Phase 2 |
| Recent velocity and pitch mix | Finds pitcher health/form shifts. | Statcast-style feed. | Phase 3 |
| Confirmed lineups | Downgrade reads until bats are confirmed. | MLB lineups/provider import. | Phase 2 |
| Lineup wRC+/ISO/xwOBA by handedness | Platoon edge. | Provider import or derived splits. | Phase 2 |
| Plate umpire | Totals and pitcher props. | Umpire assignment provider. | Deferred |
| Book-by-book movement | Sharp/slow-book context. | Store odds snapshots per book over time. | Phase 2 |
| Public tickets/handle | Reverse line movement research. | Optional market data provider. | Deferred |

## Signal Aggregation Service

Add:

```text
app/Services/MLB/Signals/MlbSignalDriverService.php
app/Services/MLB/Signals/MlbSignalGroup.php
app/Services/MLB/Signals/MlbSignalDriver.php
app/Services/MLB/Signals/MlbSignalSafety.php
```

`MlbSignalDriverService` responsibilities:

- Collect available signals for a game, prediction, and optional candidate.
- Normalize raw reason codes into grouped signal context.
- Emit group-level status: `positive`, `warning`, `risk`, `neutral`.
- Emit score deltas that candidate builders/scorer can consume.
- Emit reason codes and risk flags for audit/backtesting.
- Mark each driver as pregame-safe, live-only, postgame-only, or tracking-only.
- Return UI-ready summaries without exposing raw noisy tags by default.

Suggested method signatures:

```php
public function forCandidate(MlbPickCandidateData $candidate, Prediction $prediction): array;

public function forPrediction(Prediction $prediction): array;

public function pregameSafeGroups(array $groups): array;
```

Suggested output:

```php
[
    'version' => 'mlb_signal_driver_v1',
    'pregame_safe' => true,
    'recommended_angle' => 'first_5',
    'score_delta' => 14,
    'reason_codes' => ['starter_true_skill_edge', 'f5_angle_preferred'],
    'risk_flags' => ['bullpen_full_game_risk'],
    'signal_groups' => [
        [
            'key' => 'mound',
            'label' => 'Mound',
            'status' => 'positive',
            'summary' => 'Starter edge supports F5',
            'score_delta' => 12,
            'drivers' => [
                [
                    'key' => 'starter_confirmed',
                    'label' => 'Confirmed starter',
                    'value' => 'KC starter confirmed',
                    'impact' => 'positive',
                    'source' => 'espn_game_details',
                    'source_timestamp' => '2026-06-21T15:00:00Z',
                    'captured_at' => '2026-06-21T15:01:00Z',
                    'game_start_at' => '2026-06-21T18:10:00Z',
                    'is_pregame_safe' => true,
                    'pregame_safety_reasons' => [],
                ],
            ],
        ],
    ],
]
```

## Pregame Safety Rules

Every driver must include:

- `source`
- `source_timestamp`
- `captured_at`
- `game_start_at`
- `is_pregame_safe`
- `pregame_safety_reasons`

A signal is not pregame-safe if:

- `source_timestamp` is missing.
- `captured_at` is missing.
- `game_start_at` is missing.
- `captured_at` is after first pitch.
- The game is postponed, suspended, or canceled.
- The source is live-only.
- The source is postgame-only.

Pregame-unsafe signals may be shown in developer diagnostics, but they must not increase candidate score or appear as bettor-facing pregame support.

## First-Pass Signal Groups

### Mound

First pass should use data already available:

- probable starters present
- starter changed after prediction, when detectable
- pitcher source/fallback metadata from `mlb_predictions.model_metadata`
- pitcher Elo gap, if available in metadata
- starter rest/pitch count only if point-in-time safe

Reason codes:

- `starter_confirmed`
- `starter_uncertainty`
- `starter_changed_after_prediction`
- `starter_edge_f5_preferred`
- `pitcher_fallback_risk`

Scoring:

- Confirmed starters: small positive delta.
- Starter edge with bullpen risk: prefer F5, not full-game.
- Unconfirmed/changed starter: risk flag and possible promotion block.

### Lineup And Platoon

First pass:

- Add group shell and risk handling.
- Use confirmed lineup only after a reliable source exists.
- Until then, emit `lineup_context_unavailable` as a neutral/developer diagnostic, not a bettor-facing driver.

Reason codes:

- `lineup_confirmed`
- `lineup_strength_edge`
- `platoon_edge`
- `key_bat_available`

Risk flags:

- `lineup_not_confirmed`
- `key_bat_missing`
- `platoon_data_missing`

Scoring:

- Confirmed strong lineup can increase side/total/prop score.
- Unconfirmed lineup lowers score for public promotion quality.

### Market

Use current sources:

- `game_odds_snapshots`
- `mlb_games.odds_data`
- `odds_updated_at`
- `MlbPickMarketService`
- market/no-vig/blend fields already stored on candidates

Reason codes:

- `model_market_agrees`
- `market_moved_toward_model`
- `positive_no_vig_edge`
- `best_price_available`

Risk flags:

- `stale_odds`
- `missing_odds_timestamp`
- `missing_market_context`
- `market_moved_against_model`
- `moneyline_price_missing`

Scoring:

- Market agreement and fresh positive no-vig edge increase score.
- Market disagreement should stay negative until correlation reports prove otherwise.
- Stale odds can block promotion.

### Bullpen

Use:

- `mlb_bullpen_ratings`
- existing bullpen reason codes
- future reliever usage freshness

Reason codes:

- `bullpen_advantage`
- `bullpen_supports_full_game`
- `bullpen_fatigue_over_context`
- `starter_edge_f5_preferred`

Risk flags:

- `bullpen_full_game_risk`
- `bullpen_data_stale`
- `high_leverage_relievers_unavailable`

Scoring:

- Strong starter plus weak/tired bullpen should prefer F5/F3.
- Strong starter plus strong bullpen can support full-game side.
- Tired bullpens can support full-game over more than F5 over.

### Run Environment

Use:

- `mlb_game_weather`
- prediction metadata park/weather adjustments
- venue/roof context when present

Reason codes:

- `park_support`
- `weather_supports_over`
- `weather_supports_under`
- `roof_context_confirmed`

Risk flags:

- `weather_missing`
- `roof_unknown`
- `rain_delay_risk`
- `total_model_over_bias`

Scoring:

- Run environment can support totals and props.
- Weather/roof unknown should reduce total confidence.
- Existing total over-bias risk should stay visible until calibration improves.

### Angle Selection

Add `recommended_market_angle` to signal payloads and candidate feature snapshots:

- `full_game`
- `first_5`
- `first_3`
- `player_prop`
- `tracking_only`

Rules:

- Strong starter edge plus weak bullpen: prefer `first_5`.
- Starter and bullpen both support side: allow `full_game`.
- Starter edge is short-window only: consider `first_3` or `first_5`.
- Bullpen fatigue supports scoring: full-game over gets more support than F5 over.
- Unconfirmed pitcher/lineup/market: `tracking_only`.

## Candidate Scoring Integration

Change flow:

1. Candidate builders create base market candidates.
2. `MlbSignalDriverService` enriches each candidate with `signal_groups`, `score_delta`, `reason_codes`, `risk_flags`, and `recommended_market_angle`.
3. `MlbPickCandidateScorer` applies signal deltas and risk caps.
4. `MlbPickCandidateRepository` persists grouped payload inside `feature_snapshot.signal_layer`.
5. `MlbPickCandidateResource` exposes `signal_layer` directly.

Update `MlbPickCandidateData` to carry:

```php
public readonly array $signalLayer = [];
```

or store it under `featureSnapshot['signal_layer']` during scoring if the constructor change is too broad for V1.

Risk caps:

- Any `point_in_time_unsafe`, `live_only_or_postgame_unsafe`, or `starter_changed_after_prediction` caps score below public promotion.
- `stale_odds` caps score below official candidate until fresh price exists.
- `lineup_not_confirmed` caps lineup-dependent prop/team-total confidence.

## API Payload Shape

Add to `MlbPickCandidateResource`:

```php
'signal_layer' => data_get($candidate->feature_snapshot, 'signal_layer', [
    'version' => 'mlb_signal_driver_v1',
    'signal_groups' => [],
]),
'recommended_market_angle' => data_get($candidate->feature_snapshot, 'signal_layer.recommended_angle'),
```

Add matching TypeScript:

```ts
export type MlbSignalDriver = {
    key: string;
    label: string;
    value?: string | number | null;
    impact: 'positive' | 'warning' | 'risk' | 'neutral';
    source?: string | null;
    source_timestamp?: string | null;
    captured_at?: string | null;
    game_start_at?: string | null;
    is_pregame_safe: boolean;
    pregame_safety_reasons: string[];
};

export type MlbSignalGroup = {
    key: 'mound' | 'lineup' | 'market' | 'run_environment' | 'bullpen' | 'risk' | string;
    label: string;
    status: 'positive' | 'warning' | 'risk' | 'neutral';
    summary: string;
    score_delta?: number | null;
    drivers: MlbSignalDriver[];
};
```

## Frontend Plan

Add:

- `resources/js/components/mlb/MlbSignalGroups.vue`
- `resources/js/components/mlb/MlbSignalGroupCard.vue`
- `resources/js/components/mlb/MlbSignalDriverList.vue`

Card behavior:

- Show max 4 groups.
- Prefer order: `Risk`, `Market`, `Mound`, `Lineup`, `Run Environment`, `Bullpen`.
- Hide empty groups.
- Hide live-only groups unless prediction timing is live.
- Avoid raw snake_case labels.

Drawer behavior:

- Show all groups with summaries.
- Show driver detail in concise rows.
- Keep raw developer JSON collapsed under `Developer Context`.
- Explain bettor terms:
  - Model says: our projection.
  - Market says: odds-implied probability.
  - Blend: conservative read that pulls the model toward the market.
  - Edge: gap between our read and the price.

## Testing Plan

Backend tests:

- Signal groups are returned in grouped form.
- Empty groups are omitted from UI payloads.
- Pregame-unsafe signals are excluded from pregame score deltas.
- Live-only signals do not appear on pregame cards.
- Starter confirmation emits the `Mound` group and `starter_confirmed`.
- Starter change emits risk and caps promotion.
- Stale odds emits `Market` warning and score cap.
- Bullpen fatigue emits `Bullpen` risk and can prefer F5.
- Park/weather support emits `Run Environment`.
- Candidate scorer consumes signal group deltas.
- Candidate resource exposes additive `signal_layer` without breaking existing fields.

Frontend tests:

- Matchup card renders grouped summaries, not raw reason-code piles.
- Drawer renders all signal groups.
- Drawer hides live-only groups for pregame predictions.
- Raw developer JSON stays collapsed.
- Friendly labels render instead of snake_case codes.

Command/report tests:

- Daily picks generation persists `feature_snapshot.signal_layer`.
- Recommendation readiness can report signal-layer sample counts.
- Backtest/report commands can group performance by signal group and driver.

## Implementation Phases

### Phase 1: Contract And UI Cleanup

- Add signal-layer value objects/service with existing data only.
- Map existing reason/risk codes into groups.
- Persist `feature_snapshot.signal_layer`.
- Expose `signal_layer` on `MlbPickCandidateResource`.
- Add TypeScript types.
- Render grouped signal sections in matchup drawer.
- Keep cards concise.

Outcome: bettor-friendly explanations improve immediately without new providers.

### Phase 2: First-Pass Data Enrichment

- Improve market movement from odds snapshots.
- Add reliable lineup confirmation data.
- Add starter true-skill fields, or import provider fields into a dedicated pitcher context table.
- Add platoon split fields once source timestamps are safe.
- Add bullpen usage/fatigue freshness checks.

Outcome: signal scoring becomes meaningfully stronger, especially for F5/full-game choice.

### Phase 3: Validation And Research

- Add correlation reports by signal group and driver.
- Compare winner accuracy, spread MAE, total MAE, Brier, log loss, CLV, and ROI proxy by group.
- Identify which drivers are positive, noisy, or harmful.
- Tune score deltas only after enough graded rows exist.

Outcome: signal drivers become evidence-based instead of decorative.

### Phase 4: Advanced Signals

- Add umpire tendencies when assignments are reliable.
- Add quality-of-contact regression.
- Add live-only pitcher degradation.
- Add prop-specific signal group integration.

Outcome: richer product surface without compromising pregame safety.

## Deferred

- Public MLB bet promotion.
- Umpire group.
- Live-only pitcher degradation on pregame cards.
- Public ticket/handle based reverse line movement unless a reliable provider is added.
- Raw provider metrics in the main card UI.

## Acceptance Criteria

- The daily board can explain every candidate with grouped bettor-friendly signals.
- The API exposes a stable additive `signal_layer` payload.
- Candidate scoring can consume group score deltas and risk caps.
- Pregame safety is enforced per driver.
- Cards stay concise.
- Drawers provide enough detail for serious users.
- Raw codes remain available for developer diagnostics but are not the primary UI.
