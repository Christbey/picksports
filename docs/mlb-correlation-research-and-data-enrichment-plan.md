# MLB Correlation Research And Data Enrichment Plan

## Summary

This plan defines a research-only MLB analytics layer for understanding what drives model success, model failure, model-market disagreement, total error, and future betting-readiness.

It is part of the path toward public MLB `BET` and `Lean Edge` recommendations, but it must not enable public bets or leans. The current MLB posture remains fail-closed:

- `MLB_BET_FILTER_PROMOTIONS_VALIDATED` stays false.
- Public `bet`, `lean`, `best bet`, `strong edge`, and `top betting signal` labels stay disabled.
- Candidate recommendations and market-aware projections remain research/tracking only.
- The core model probability is not overwritten by market-aware or shadow probabilities.
- Any report that uses non-pregame-safe fields must label those rows as research-only and block promotion-quality interpretation.

## Questions To Answer

The first research pass should answer:

- Which existing MLB fields correlate with correct winner predictions?
- Which fields correlate with model failure?
- Which fields explain model-market disagreement?
- Which fields explain total over-bias or under-bias?
- Which fields are missing or low quality in the current system?
- Which new data sources should be added first?
- Which features are safe to use pregame?
- Which features may improve calibration, Brier score, log loss, and eventual betting-readiness?

## Current Data Inventory

The current MLB system already has enough data to start correlation research without adding providers first.

| Data Family | Current Source | Tables / Payloads | Pregame Safe? | Notes |
|---|---|---|---|---|
| Prediction output | Internal model | `mlb_predictions` | Yes, if generated before game start | Includes win probability, spread, total, confidence, versions, metadata, and grading. |
| Final results | ESPN game details / score reconciliation | `mlb_games`, `mlb_team_stats`, `mlb_player_stats`, `mlb_plays` | Postgame only | Used as labels, never as pregame features. |
| Team quality | Internal metrics | `mlb_team_metrics`, `mlb_elo_ratings` | Conditionally | Must ensure calculation date is before game date. |
| Pitcher quality | Internal pitcher Elo / probable starters | `mlb_pitcher_elo_ratings`, `mlb_games.probable_*_pitcher_espn_id` | Conditionally | Must distinguish confirmed probable starter from fallback or postgame inferred starter. |
| Bullpen quality | Internal bullpen ratings | `mlb_bullpen_ratings` | Conditionally | Needs `as_of_date` guard. Good candidate for correlation research. |
| Odds / market | The Odds API / snapshots / historical odds | `game_odds_snapshots`, `mlb_games.odds_data` | Conditionally | Promotion-quality rows should prefer snapshots captured before first pitch. |
| Weather / roof | Weather sync | `mlb_game_weather` | Conditionally | Requires observed/forecast timestamp safety and roof/indoor context. |
| Injuries / depth chart | ESPN injuries / depth charts | `mlb_player_injuries`, `mlb_depth_chart_entries` | Conditionally | Must use `source_updated_at` before game start. |
| Props | The Odds API player props | `mlb_player_props` | Conditionally | Useful for player-level market interest and matchup context, but not a public game recommendation source yet. |
| Protected projection | Internal research layer | `market_aware_projection` API payload | Conditionally | Tracking-only. Uses 25% model / 75% market blend by default. |

## Target Variables

The correlation report should separate target variables instead of mixing them into one "good/bad" label.

| Target | Definition | Why It Matters |
|---|---|---|
| `winner_correct` | Stored graded winner result. | Measures directional pick accuracy. |
| `spread_abs_error` | `abs(spread_error)`. | Measures margin projection quality. |
| `total_abs_error` | `abs(total_error)`. | Measures total projection quality. |
| `total_signed_error` | `predicted_total - actual_total`. | Detects over-bias or under-bias. |
| `beats_market_spread` | Model spread error lower than market spread error. | Checks whether the model adds anything beyond the market. |
| `beats_market_total` | Model total error lower than market total error. | Checks total usefulness. |
| `model_market_agree` | Model side equals market side. | Identifies safer consensus buckets. |
| `model_market_disagree` | Model side differs from market side. | Current weak area; likely suppress until proven. |
| `brier` | Brier score for winner probability. | Calibration quality. |
| `log_loss` | Log loss for winner probability. | Penalizes overconfident wrong picks. |
| `public_recommendation_type` | Promotion-safe output. | Should remain `no_play` until readiness passes. |
| `candidate_recommendation_type` | Research-only candidate output. | Lets us evaluate shadow rules without promoting them. |

## Feature Families To Analyze

### Model Output Features

- `win_probability`
- `confidence_score`
- `predicted_spread`
- `predicted_total`
- `home_team_elo`, `away_team_elo`
- `home_pitcher_elo`, `away_pitcher_elo`
- combined Elo gap
- feature/model/blend version
- metadata flags such as pitcher source, fallback source, injury application, park adjustment, weather adjustment, and market context safety

Research questions:

- Does higher confidence actually mean higher winner accuracy?
- Are strong home probabilities more reliable than strong away probabilities?
- Does pitcher fallback source reduce accuracy?
- Does a large Elo gap improve spread accuracy or only winner accuracy?
- Which model versions should be excluded from pooled calibration?

### Market Features

- no-vig home/away moneyline probability
- market favorite side
- market-model probability gap
- model-market agreement bucket
- moneyline price bucket
- odds freshness bucket
- odds source: pregame snapshot vs mutable current odds
- opening/current/closing line movement when snapshots exist
- CLV from `mlb_bet_filter_results`

Research questions:

- Is the market favorite outperforming the model?
- Does model-market agreement improve winner accuracy?
- Are disagreement rows systematically bad?
- Does stale odds status explain failed candidates?
- Does a market-heavy blend improve Brier/log loss by month?

### Pitcher And Bullpen Features

- confirmed probable starter vs fallback starter
- pitcher Elo gap
- games started / starter sample size
- bullpen rating gap
- bullpen workload penalty
- bullpen recent form
- K/9, BB/9, HR/9 from bullpen ratings
- starting pitcher handedness and matchup splits when added later

Research questions:

- Are pitcher fallback rows materially worse?
- Is bullpen workload correlated with late scoring and total error?
- Does bullpen edge matter more for moneyline, run line, or totals?
- Do pitcher gaps explain model-market disagreement?

### Team Quality And Form Features

- OPS / OBP / SLG
- official OBP components where available: HBP and SF
- runs per game
- runs allowed per game
- run differential per game
- WHIP
- team ERA
- strength of schedule
- recent form rating
- rest/travel fatigue
- injury-adjusted rating
- home/away splits when added later

Research questions:

- Which team metric fields actually correlate with winner correctness?
- Are `offensive_rating` and `pitching_rating` more predictive than their raw components?
- Is rest/travel fatigue predictive or noise?
- Does recent form help or overfit?

### Weather, Park, And Game Context Features

- temperature
- humidity
- wind speed
- wind direction
- precipitation probability
- roof status
- indoor/outdoor flag
- venue
- day/night when available
- park adjustment from model metadata
- weather total adjustment from model metadata

Research questions:

- Which weather fields explain total signed error?
- Are roof/indoor unknown rows causing bad total projections?
- Is the current weather adjustment too strong, too weak, or directionally wrong?
- Do certain parks require stronger total correction?

### Schedule And Season Context

- month
- day of week
- doubleheader indicator when available
- getaway/travel spot when available
- season stage
- days rest
- games in last 3/5/7 days
- series game number when added later

Research questions:

- Is the model worse early season?
- Are totals biased by month/weather temperature?
- Are travel/rest features correlated with run scoring or bullpen failure?

## Proposed Report Command

Add a report-only command:

```bash
php artisan mlb:research-correlations \
  --season=2026 \
  --feature-version=core-v3 \
  --limit=2500 \
  --strict-pregame
```

Options:

| Option | Purpose |
|---|---|
| `--season=` | Filter to one season. |
| `--from=` / `--to=` | Date window for walk-forward slices. |
| `--feature-version=` | Filter to a feature version, or `any`. |
| `--model-version=` | Optional model version filter. |
| `--blend-version=` | Optional blend version filter. |
| `--limit=` | Limit most recent graded rows. |
| `--target=` | One of `winner`, `spread_error`, `total_error`, `market_disagreement`, `recommendation`. |
| `--strict-pregame` | Restrict market and feature analysis to point-in-time-safe rows. |
| `--min-rows=` | Minimum rows required before ranking a feature. |
| `--json` | Structured output for admin/AI/reporting. |
| `--export-csv=` | Optional CSV export path for offline analysis. |

The command must not mutate:

- `mlb_predictions`
- `mlb_games`
- `mlb_bet_filter_results`
- recommendation config
- promotion flags
- API recommendation payloads

## Proposed JSON Contract

```json
{
  "report_type": "mlb_correlation_research",
  "mode": "research_only",
  "promotion_safe": false,
  "summary": {
    "rows": 0,
    "strict_pregame_rows": 0,
    "graded_rows": 0,
    "market_rows": 0,
    "feature_version": "core-v3"
  },
  "data_completeness": [],
  "target_baselines": [],
  "feature_rankings": {
    "winner_correct": [],
    "model_failure": [],
    "market_disagreement": [],
    "total_signed_error": [],
    "total_abs_error": []
  },
  "bucket_reports": [],
  "interaction_reports": [],
  "point_in_time_safety": [],
  "missing_data_backlog": [],
  "recommended_enrichment_order": [],
  "promotion_blockers": []
}
```

## Analysis Methods

V1 should be simple, auditable, and hard to overfit:

- Numeric features:
  - Pearson correlation against signed numeric targets.
  - Spearman correlation for monotonic relationships.
  - Bucketed win rate / MAE / Brier by quantile.
- Categorical features:
  - Winner rate, spread MAE, total MAE by category.
  - Chi-square style lift for binary outcomes.
  - Minimum row threshold before ranking.
- Binary flags:
  - Lift versus baseline.
  - Failure rate versus baseline.
  - Month-by-month stability.
- Model-market features:
  - Separate agreement and disagreement.
  - Never allow disagreement lift to promote unless it beats market baseline in strict pregame rows.
- Calibration:
  - Brier and log loss by probability bucket.
  - Calibration gap by bucket.
  - Monotonicity check across confidence buckets.

V1 should not use black-box feature importance as the source of truth. Machine learning importance can come later after the deterministic report is trusted.

## Pregame Safety Rules

A feature is promotion-quality only when it is provably known before first pitch.

Safe examples:

- Pregame odds snapshot with `captured_at < game_start`.
- Team metric snapshot or metric calculation date before game date.
- Bullpen rating `as_of_date <= game_date`.
- Weather forecast/observation timestamp before game start.
- Injury/depth-chart source timestamp before game start.
- Prediction `created_at` or feature snapshot before game start.

Unsafe examples:

- Final score, player stats, team stats, plays, or postgame grading.
- Current mutable odds row with no timestamp proof.
- Weather observed after game start if used as pregame input.
- Pitcher identity inferred from postgame stats.
- Live prediction fields.
- Postponed/suspended/cancelled games.

The report should output:

- `safe_pregame_features`
- `unsafe_features_excluded`
- `unknown_safety_features`
- `strict_pregame_row_count`

## Missing Data Backlog

The report should score missingness for every feature family:

| Missing Data | Why It Matters | First Action |
|---|---|---|
| Immutable team metric snapshots | Prevents future team metrics from leaking into historical predictions. | Add dated snapshot table or calculate historical-as-of metrics in report. |
| Confirmed pregame probable pitchers | Pitcher fallback appears likely to affect accuracy. | Store pitcher confirmation source and timestamp. |
| Opening/closing odds snapshots | Needed for CLV and line movement. | Expand odds snapshot cadence and backfill where possible. |
| Starting lineup / batting order | MLB offense is lineup-dependent. | Add only after pitcher/odds/weather are clean. |
| Batter/pitcher handedness splits | Core baseball matchup signal. | Start with team-level handedness splits, then player-level. |
| Statcast quality of contact | Helps explain over/under and offense quality. | Research Baseball Savant / Statcast ingestion feasibility. |
| Park factor by venue and handedness | Current venue context may be too coarse. | Add maintained park factor table. |
| Umpire / strike-zone tendencies | Can affect totals and pitcher outcomes. | Research later; not V1. |
| Rest/travel from schedule graph | Fatigue may be noisy but should be measured. | Derive internally before adding a provider. |
| Roof status source reliability | Weather model depends on roof/indoor truth. | Audit unknown roof rows and venue mapping. |

## Data Enrichment Priority

### Priority 1: Make Existing Data Research-Trustworthy

Do this before adding new providers:

1. Reconcile final scores and regrade predictions.
2. Ensure all final games have both team stats and player stats.
3. Ensure odds snapshots exist before first pitch for market research rows.
4. Add or emulate historical-as-of team metrics.
5. Verify pitcher source metadata is present for every prediction.
6. Verify weather timestamp, roof status, and venue coordinates.
7. Group all research by model/feature/blend version.

### Priority 2: Add Baseball-Specific Pregame Features

Add these after the existing data is clean:

1. Handedness splits:
   - team offense versus LHP/RHP
   - probable starter handedness
   - bullpen handedness where available
2. Starting lineup quality:
   - projected lineup OPS/wOBA proxy
   - key bats missing
   - catcher/rest day impact
3. Pitcher shape:
   - recent pitch count
   - days rest
   - K/BB/HR profile
   - ground-ball/fly-ball tendency
4. Bullpen availability:
   - relievers used yesterday / last 3 days
   - high-leverage usage
   - projected bullpen fatigue
5. Park and weather interaction:
   - wind direction relative to field orientation
   - temperature/humidity total impact
   - roof open/closed truth

### Priority 3: External Advanced Data

Candidate sources to research:

- [Baseball Savant / Statcast Search](https://baseballsavant.mlb.com/statcast_search): quality-of-contact, pitch velocity/spin, xwOBA-style context, batted-ball profiles.
- [FanGraphs Sabermetrics Library](https://library.fangraphs.com/): definitions and validation targets for FIP, wOBA, WAR-adjacent concepts, and baseball metric interpretation.
- [Retrosheet](https://www.retrosheet.org/): historical play-by-play validation and schedule/context enrichment.
- pybaseball / public baseball data tooling: useful for research prototypes, but production ingestion should confirm source terms, reliability, and rate limits first.

External enrichment should be gated by:

- source terms and licensing
- update cadence
- timestamp availability
- stable team/player identifiers
- pregame availability
- backfill feasibility
- measurable lift in strict pregame research rows

## First Report Tables

The first human-readable command should output:

### Summary

| Metric | Value |
|---|---:|
| Rows scanned | 0 |
| Strict pregame rows | 0 |
| Winner accuracy | 0.0% |
| Home baseline | 0.0% |
| Market baseline | 0.0% |
| Brier | 0.000 |
| Log loss | 0.000 |
| Spread MAE | 0.00 |
| Total MAE | 0.00 |
| Total bias | 0.00 |

### Top Success Correlates

Rank features by lift in winner accuracy and lower error:

- feature
- bucket/category
- rows
- target result
- baseline result
- lift
- month stability
- pregame safety status

### Top Failure Correlates

Rank features by model miss rate, high spread error, high total error, and bad log loss:

- feature
- bucket/category
- rows
- failure rate
- baseline failure rate
- lift
- notes

### Model-Market Disagreement

Separate:

- model and market agree
- model home / market away
- model away / market home
- small gap disagreement
- large gap disagreement
- stale/unsafe odds disagreement

### Total Bias Drivers

Bucket by:

- month
- venue
- roof status
- temperature
- wind speed/direction
- predicted total bucket
- market total bucket
- park adjustment bucket
- weather adjustment bucket

### Missing Data

Report:

- feature family
- expected rows
- populated rows
- missing percent
- promotion impact
- recommended repair command or enrichment task

## AI Layer

AI can summarize the correlation report, but deterministic report values are the source of truth.

AI may:

- summarize strongest success/failure drivers
- explain missing data priorities
- draft operator notes
- suggest next research runs
- compare current run versus previous run if snapshots are saved later

AI must not:

- invent feature lift not present in the report
- convert research candidates into bets
- override promotion blockers
- hide point-in-time safety warnings
- recommend enabling public MLB bets from a small or unsafe sample

## Implementation Phases

### Phase 1: Documentation And Contract

- Add this plan.
- Confirm public MLB recommendation guard remains documented.
- Define command output shape.
- Define pregame safety rules.

### Phase 2: Correlation Report Command

Add `mlb:research-correlations`.

Acceptance criteria:

- report-only
- JSON and table output
- groups by model/feature/blend version
- separates strict pregame rows from general research rows
- reports missingness
- reports winner, spread, total, market disagreement, Brier, and log loss
- never mutates predictions or promotion config

### Phase 3: Data Completeness Gate

Add data completeness scoring to the report.

Acceptance criteria:

- each feature family has a completeness percentage
- missingness gets a severity label
- repair/enrichment recommendations are emitted
- strict pregame row count is visible

### Phase 4: Enrichment Prototype

Prototype one enrichment family at a time:

1. handedness splits
2. lineup quality
3. bullpen availability
4. Statcast quality of contact
5. park/weather interaction

Each enrichment must prove:

- timestamp safety
- identifier match quality
- backfill feasibility
- correlation lift on strict pregame rows
- improvement to Brier/log loss or recommendation-readiness blockers

### Phase 5: Readiness Re-Evaluation

Only after a stable sample:

```bash
php artisan mlb:research-correlations --season=2026 --feature-version=core-v3 --strict-pregame --json
php artisan mlb:research-market-blends --season=2026 --feature-version=core-v3 --strict-pregame --json
php artisan mlb:validate-recommendation-readiness --season=2026 --feature-version=core-v3 --limit=2500
```

Promotion can be discussed only if:

- strict pregame sample is large enough
- candidate rows beat home, market, and no-play baselines
- Brier/log loss improve
- confidence is monotonic
- model-market disagreement rules are proven or suppressed
- totals bias is within threshold
- monthly/walk-forward stability is acceptable

## Definition Of Done

This plan is complete when:

- There is a documented research-only path for MLB correlation analysis.
- The next command/API work can be implemented without changing public recommendation behavior.
- Existing data sources are inventoried.
- Missing/enrichment data is prioritized.
- Pregame safety rules are explicit.
- AI usage is bounded to summarization and operator support.
- The path to public MLB recommendations remains blocked by readiness evidence, not manual optimism.

