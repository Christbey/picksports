# Calculations Reference

This document is the reference for the main calculations used by PickSports.

It is meant to answer:
- what we calculate
- how we calculate it
- where the source code lives

If this document and the code ever disagree, the code is the source of truth.

## Scope

This reference covers the primary calculation systems:
- shared season/game filtering inputs
- Elo updates
- team metrics
- prediction generation
- live prediction updates
- betting value analysis
- validation and evaluation helpers

It does not try to document every display-only formatter or every narrative sentence builder.

## Shared Inputs

### Completed game filtering

Source:
- `app/Concerns/FiltersTeamGames.php`

Shared team-metric calculations start by loading completed games for a team and season.

Rules:
- filter by `season`
- filter by final status from `config("{sport}.statuses.final")`
- optionally filter by analytics season types
- for MLB regular-season analytics, ignore pre-opener games unless spring training is explicitly requested
- eager-load `teamStats`, `homeTeam`, and `awayTeam`

### Team stat gathering

Source:
- `app/Concerns/FiltersTeamGames.php`

For each completed game:
- identify the team stat row
- identify the opponent stat row
- collect opponent pre-game Elo when available from Elo history
- fall back to current opponent Elo if per-game history is missing

Shared derived inputs:
- `wins` / `losses`
- `strength_of_schedule = average(opponent pre-game Elo)`
- `recent_form_rating = weighted average scoring margin over recent games`
- `rest_travel_fatigue = heuristic fatigue score based on spacing and away games`

## Elo

Source:
- `app/Actions/Sports/AbstractEloCalculator.php`
- sport-specific implementations in `app/Actions/{SPORT}/CalculateElo.php`

### Core Elo formula

Expected score:

```text
expected = 1 / (1 + 10^((ratingB - ratingA) / 400))
```

Update:

```text
elo_change = k_factor * (actual_result - expected_result)
new_elo = old_elo + elo_change
```

Shared behavior:
- add configured home advantage unless neutral site
- skip non-final games
- optionally skip games that already have Elo history
- save one Elo history row per team per game

Sport-specific implementations control:
- base K-factor
- playoff multiplier
- margin-of-victory multiplier
- season-type or recency adjustments

## Team Metrics

### Professional basketball: NBA and WNBA

Source:
- `app/Actions/Sports/AbstractProfessionalBasketballCalculateTeamMetrics.php`
- concrete classes:
  - `app/Actions/NBA/CalculateTeamMetrics.php`
  - `app/Actions/WNBA/CalculateTeamMetrics.php`

Main formulas:

Possession estimate:

```text
possessions = FGA - ORB + TO + (possession_coefficient * FTA)
```

Offensive efficiency:

```text
offensive_efficiency = (total_points / total_possessions) * 100
```

Defensive efficiency:

```text
defensive_efficiency = (opponent_points / opponent_possessions) * 100
```

Net rating:

```text
net_rating = offensive_efficiency - defensive_efficiency
```

Tempo:

```text
tempo = total_possessions / games_played
```

Also stored:
- `strength_of_schedule`
- `recent_form_rating`
- `injury_adjusted_team_rating`
- `injury_total_adjustment`
- `rest_travel_fatigue`
- optional true-EPA fields when enabled

### College basketball: CBB and WCBB

Source:
- `app/Actions/Sports/AbstractCollegeBasketballCalculateTeamMetrics.php`
- `app/Services/OpponentAdjustmentCalculator.php`
- concrete classes:
  - `app/Actions/CBB/CalculateTeamMetrics.php`
  - `app/Actions/WCBB/CalculateTeamMetrics.php`

Raw formulas are the same as pro basketball:
- offensive efficiency
- defensive efficiency
- net rating
- tempo
- possession estimate

Additional calculations:
- minimum-games gating
- rolling window metrics
- home/away splits
- true-EPA metrics
- opponent-adjusted metrics

Opponent-adjusted metrics use an iterative adjustment process inspired by KenPom:

For each game:

```text
adjusted_off_eff = raw_off_eff * (league_avg_def / opponent_current_def)
adjusted_def_eff = raw_def_eff * (league_avg_off / opponent_current_off)
adjusted_tempo   = raw_possessions * (league_avg_tempo / opponent_current_tempo)
```

Then per team:
- average the adjusted game values
- damp toward the target value each iteration
- stop when max change falls below the convergence threshold or max iterations is reached
- normalize to the configured baseline

### Gridiron: NFL and CFB shared base metrics

Source:
- `app/Actions/Sports/Concerns/CalculatesGridironTeamMetrics.php`

Shared formulas:

Average by stat:

```text
average = sum(values) / count(values)
```

Turnover differential:

```text
turnover_differential =
  (opponent_interceptions + opponent_fumbles_lost
   - team_interceptions - team_fumbles_lost)
  / games_played
```

### NFL team metrics

Source:
- `app/Actions/NFL/CalculateTeamMetrics.php`

Base rating model:

```text
offensive_rating = points_per_game
defensive_rating = points_allowed_per_game
net_rating = offensive_rating - defensive_rating
```

Additional season metrics:
- `yards_per_game`
- `yards_allowed_per_game`
- `passing_yards_per_game`
- `rushing_yards_per_game`
- `strength_of_schedule`
- `recent_form_rating`
- `predictive_rating`
- division/non-division splits
- home/away ratings
- last 5 / last 10 ratings
- quality bucket ratings by opponent rank
- first-half / second-half ratings
- true-EPA fields

Predictive rating:

```text
sos_adjustment = (season_sos - league_average_elo) / 25

predictive_rating =
  (net_rating * 0.65)
  + (recent_form_rating * 0.25)
  + (turnover_differential * 0.75)
  + sos_adjustment
```

### CFB team metrics

Source:
- `app/Actions/CFB/CalculateTeamMetrics.php`
- `app/Services/CollegeFootballData/CollegeFootballDataService.php`

CFB starts with the same base gridiron metrics as NFL, then adds external and composite ratings:
- FPI
- WEPA
- power rating
- resume rating
- CFP rating
- preseason metric rows for FBS teams even before completed games

Power rating:

```text
sos_component    = ((strength_of_schedule ?? 1500) - 1500) * 0.020
recent_component = recent_form_rating * 0.180
fpi_component    = fpi * 0.150
wepa_component   = wepa_net * 4.000
capped_net       = min(18.0, net_rating)

power_rating =
  (capped_net * 0.400)
  + sos_component
  + recent_component
  + fpi_component
  + wepa_component
```

Resume rating is a score built from:
- FBS win percentage
- strength of schedule
- conference adjustment
- per-game quality-win / bad-loss scoring
- location bonus
- championship bonus
- non-FBS penalties
- elite resume bonus

CFP rating is then derived from normalized power and resume inputs in the same action.

### MLB team metrics

Source:
- `app/Actions/MLB/CalculateTeamMetrics.php`

MLB uses custom composite formulas.

Offensive rating:

```text
offensive_rating =
  (runs_per_game * runs_multiplier)
  + (batting_average * batting_avg_multiplier)
  + (home_run_rate * home_run_multiplier)
```

Pitching rating:

```text
era = (earned_runs / innings_pitched) * 9
era_component = max(0, era_max - (era * era_scale))

pitching_rating =
  era_component
  + strikeouts_per_game
  - walks_per_game
```

Defensive rating:

```text
fielding_pct = (putouts + assists - errors) / (putouts + assists + errors)

defensive_rating =
  (fielding_pct * fielding_pct_multiplier)
  + putouts_per_game
  + assists_per_game
  - (errors_per_game * errors_multiplier)
```

Additional MLB metrics:
- `runs_per_game`
- `runs_allowed_per_game`
- `run_differential_per_game`
- `home_runs_per_game`
- `batting_average`
- `on_base_percentage`
- `slugging_percentage`
- `ops = obp + slg`
- `team_era`
- `strikeouts_pitched_per_game`
- `whip`
- `strength_of_schedule`
- `recent_form_rating`
- `injury_adjusted_team_rating`
- `injury_total_adjustment`
- `rest_travel_fatigue`

## Prediction Generation

### Shared prediction framework

Source:
- `app/Actions/Sports/AbstractPredictionGenerator.php`

Shared flow:
1. skip completed games
2. load teams and season metrics
3. calculate spread and total with sport-specific logic
4. apply context adjustments
5. apply injury adjustments when persisted team-metric adjustments are not available
6. convert spread into win probability
7. convert win probability into confidence
8. persist prediction and feature snapshot

Shared formulas:

Win probability from spread:

```text
win_probability = 1 / (1 + exp(-spread / spread_to_probability_coefficient))
```

Confidence:

```text
confidence_score = max(win_probability, 1 - win_probability) * 100
```

### NBA prediction model

Source:
- `app/Actions/NBA/GeneratePrediction.php`

Spread components:
- Elo spread
- efficiency spread
- home/away split adjustment
- form spread
- rest adjustment
- turnover adjustment
- rebound adjustment
- injury spread adjustment
- optional Vegas blend
- true-EPA spread blend

Core shape:

```text
elo_spread = (home_elo + home_court_advantage - away_elo) / elo_to_spread_divisor
efficiency_spread = ((home_net - away_net) / 2) + home_court_points
form_spread = ((home_form_net - away_form_net) / 2) + home_court_points

model_spread =
  (elo_weight * elo_spread)
  + (efficiency_weight * efficiency_spread)
  + (form_weight * form_spread)
  + situational_adjustments
  + injury_spread_adjustment
```

If market spread exists:

```text
final_spread =
  (model_weight_with_vegas * model_spread)
  + (vegas_weight * vegas_spread)
```

The total model blends:
- season offense/defense scoring components
- recent offense/defense scoring components
- venue efficiency
- season and recent pace
- pace floors and regressions
- rest-based pace downticks
- injury total adjustment
- true-EPA total blend
- optional market-aware calibration

### CBB and WCBB prediction model

Source:
- `app/Actions/Sports/AbstractCollegeBasketballPredictionGenerator.php`
- concrete classes:
  - `app/Actions/CBB/GeneratePrediction.php`
  - `app/Actions/WCBB/GeneratePrediction.php`

The college basketball spread model follows the same ensemble structure as NBA:
- Elo
- efficiency
- form
- rest
- turnover
- rebounding
- optional Vegas blend
- true-EPA blend

The total model is similar as well, but includes additional college-basketball pace controls:
- season pace regression
- recent pace regression
- max recent pace drop
- pace floor blend
- factor-based total adjustments
- tournament calibration hooks
- win-probability calibration metadata

### NFL and CFB prediction model

Source:
- `app/Actions/Sports/AbstractAmericanFootballPredictionGenerator.php`
- concrete classes:
  - `app/Actions/NFL/GeneratePrediction.php`
  - `app/Actions/CFB/GeneratePrediction.php`

Spread:

```text
elo_diff = (home_elo + home_field_advantage) - away_elo
predicted_spread = elo_diff * points_per_elo
```

Then clamp to configured min/max spread.

Default total:

```text
predicted_total = configured_average_total
```

Each sport can extend or override this with more domain-specific logic.

### MLB prediction model

Source:
- `app/Actions/MLB/GeneratePrediction.php`

MLB combines team Elo and pitcher Elo.

Process:
- resolve team Elo
- resolve pitcher Elo with three-tier fallback:
  1. probable starter Elo
  2. likely-starter or recent team pitcher average
  3. league-average fallback
- derive a season-progress scale
- dynamically weight team Elo vs pitcher Elo
- apply home-field advantage
- convert combined Elo into spread and total
- blend in context adjustments
- apply injury adjustments
- apply probable-pitcher injury adjustments

The exact MLB spread/total conversions live in:
- `calculateSpread()`
- `calculateTotal()`

## Live Prediction Updates

### Shared football live model

Source:
- `app/Actions/Sports/AbstractFootballUpdateLivePrediction.php`
- `app/Actions/NFL/CalculateLiveWinProbability.php`
- concrete live updaters:
  - `app/Actions/NFL/UpdateLivePrediction.php`
  - `app/Actions/CFB/UpdateLivePrediction.php`

Live win probability:
- convert current margin into a stronger signal as more time elapses
- blend that with pre-game log-odds

Formula shape:

```text
point_value = 0.02 + (0.15 * time_elapsed_fraction^2)
margin_adjustment = margin * point_value
pre_game_log_odds = log(p / (1 - p))
pre_game_weight = 1 - time_elapsed_fraction^0.5

combined_log_odds =
  (pre_game_log_odds * pre_game_weight)
  + margin_adjustment

live_probability = logistic(combined_log_odds)
```

Live spread:
- current margin
- remaining pre-game contribution
- pace-of-game margin projection
- regression back toward current margin

Live total:
- current scoring pace
- projected remaining scoring
- blend with pre-game total
- sport-specific upper bound

### Shared simple basketball live model

Source:
- `app/Actions/Sports/AbstractSimpleBasketballUpdateLivePrediction.php`
- concrete users:
  - `app/Actions/WNBA/UpdateLivePrediction.php`
  - `app/Actions/WCBB/UpdateLivePrediction.php`

The simple basketball live model uses the same structure as the football live updater, adapted to basketball game length.

### NBA and CBB advanced live models

Source:
- `app/Actions/NBA/UpdateLivePrediction.php`
- `app/Actions/CBB/UpdateLivePrediction.php`
- `app/Actions/Sports/AbstractAdvancedBasketballUpdateLivePrediction.php`

These use the same live-update pattern but add sport-specific possession and pacing logic beyond the simple model.

### MLB live model

Source:
- `app/Actions/MLB/UpdateLivePrediction.php`

MLB live logic is outs-based.

Live win probability:
- compute outs remaining
- increase run leverage as the game gets later
- double leverage in late bottom-inning walk-off style spots
- blend current margin adjustment with pre-game log-odds

Live spread:
- blend current margin with remaining pre-game expectation
- regress harder to actual margin late

Live total:
- project from runs-per-out pace
- blend with remaining pre-game expectation
- clamp to a bounded MLB range

## Betting Value

Source:
- `app/Actions/Sports/AbstractLineBasedCalculateBettingValue.php`
- `app/Actions/Sports/Concerns/InteractsWithBettingMarkets.php`
- sport-specific entry points such as:
  - `app/Actions/NBA/CalculateBettingValue.php`
  - `app/Actions/NFL/CalculateBettingValue.php`
  - `app/Actions/CBB/CalculateBettingValue.php`

Markets analyzed:
- spreads
- totals
- moneyline

### Spread edge

```text
edge = abs(model_spread - market_spread_in_model_convention)
```

Recommendation only triggers if edge exceeds the configured threshold.

Spread confidence:

```text
confidence = min(95, 50 + edge * 4)
```

### Total edge

```text
edge = abs(predicted_total - market_total)
confidence = min(95, 50 + edge * 5)
```

### Moneyline edge

Implied probability from American odds:

```text
if odds > 0:
  implied = 100 / (odds + 100)
else:
  implied = abs(odds) / (abs(odds) + 100)
```

Edge:

```text
edge = model_probability - implied_probability
```

Kelly bet sizing:

```text
decimal_odds =
  (odds / 100) + 1      if odds > 0
  (100 / abs(odds)) + 1 if odds < 0

kelly = ((probability * decimal_odds) - 1) / (decimal_odds - 1)
bet_size = kelly * fraction
```

Moneyline confidence:

```text
confidence = min(95, 50 + ((edge / threshold) * 7))
```

## Validation and Evaluation

### Metric validation ranges

Source:
- `app/Services/MetricValidator.php`

The validator applies sanity ranges per sport, for example:
- NFL offensive/defensive ratings
- basketball offensive/defensive efficiency ranges
- MLB rating / ERA / batting average ranges

This is a guardrail, not the metric source.

### Prediction evaluation

Source:
- `app/Services/Predictions/PredictionEvaluationRecorder.php`

Recorded errors include:
- absolute win probability error
- Brier score
- log loss
- spread/total error fields when available

Formulas:

```text
probability_error = abs(actual_home_win - predicted_probability)
brier_score = (actual_home_win - predicted_probability)^2
log_loss = -[y*log(p) + (1-y)*log(1-p)]
```

## External Rating Inputs

### CFB external data

Source:
- `app/Services/CollegeFootballData/CollegeFootballDataService.php`

Used external inputs:
- WEPA team season data
- FPI ratings

These are not calculated locally; the app maps them and blends them into CFB ratings.

## Per-Sport Config Keys And Coefficients

This section lists the main config keys that directly change model output. It is not every config key in the repo; it is the coefficient set that materially changes calculations documented above.

### NBA

Source:
- `config/nba.php`

| Area | Keys |
| --- | --- |
| Team metrics | `possession_coefficient` |
| Elo | `elo.default`, `elo.base_k_factor`, `elo.playoff_multiplier`, `elo.home_court_advantage`, `elo.margin_multipliers.*` |
| Spread model | `prediction.elo_to_spread_divisor`, `prediction.elo_weight`, `prediction.efficiency_weight`, `prediction.form_weight`, `prediction.home_court_points`, `prediction.home_away_split_weight`, `prediction.turnover_diff_weight`, `prediction.rebound_margin_weight`, `prediction.rest_day_adjustment`, `prediction.back_to_back_penalty`, `prediction.recent_spread_weight`, `prediction.fatigue_spread_weight`, `prediction.injury_spread_weight` |
| Total model | `prediction.average_pace`, `prediction.default_efficiency`, `prediction.total_recent_efficiency_weight`, `prediction.total_venue_efficiency_weight`, `prediction.total_season_tempo_regression_weight`, `prediction.total_recent_tempo_regression_weight`, `prediction.total_calibration.*`, `prediction.recent_total_weight`, `prediction.fatigue_total_weight`, `prediction.injury_total_weight` |
| Win probability | `prediction.spread_to_probability_coefficient` |
| Market blend | `prediction.vegas_weight`, `prediction.model_weight_with_vegas` |
| Injury model | `prediction.injury_out_spread_penalty`, `prediction.injury_questionable_spread_penalty`, `prediction.injury_out_total_penalty`, `prediction.injury_questionable_total_penalty`, `prediction.injury_epa_*`, `prediction.depth_chart.*` |
| True EPA | `prediction.true_epa.*` |
| Betting | `betting.edge_thresholds.*`, `betting.kelly.*` |

### WNBA

Source:
- `config/wnba.php`

| Area | Keys |
| --- | --- |
| Team metrics | `possession_coefficient` |
| Elo | `elo.default`, `elo.base_k_factor`, `elo.playoff_multiplier`, `elo.home_court_advantage`, `elo.margin_multipliers.*` |
| Prediction | `prediction.elo_to_spread_divisor`, `prediction.average_pace`, `prediction.default_efficiency`, `prediction.total_tempo_regression_weight`, `prediction.spread_to_probability_coefficient`, `prediction.confidence.*` |
| Injury model | `prediction.injury_out_spread_penalty`, `prediction.injury_questionable_spread_penalty`, `prediction.injury_out_total_penalty`, `prediction.injury_questionable_total_penalty`, `prediction.injury_epa_*` |

### CBB

Source:
- `config/cbb.php`

| Area | Keys |
| --- | --- |
| Team metrics | `metrics.minimum_games`, `metrics.possession_coefficient`, `metrics.rolling_window_size`, `metrics.max_adjustment_iterations`, `metrics.adjustment_convergence_threshold`, `metrics.adjustment_damping_factor`, `normalization_baseline` |
| Elo | `elo.default`, `elo.base_k_factor`, `elo.playoff_multiplier`, `elo.home_court_advantage`, `elo.margin_multipliers.*`, `elo.sos_adjustment.*` |
| Spread model | `prediction.elo_to_spread_divisor`, `prediction.elo_weight`, `prediction.efficiency_weight`, `prediction.form_weight`, `prediction.home_court_points`, `prediction.home_away_split_weight`, `prediction.turnover_diff_weight`, `prediction.rebound_margin_weight`, `prediction.rest_day_adjustment`, `prediction.back_to_back_penalty` |
| Total model | `prediction.average_pace`, `prediction.default_efficiency`, `prediction.total_recent_efficiency_weight`, `prediction.total_venue_efficiency_weight`, `prediction.total_season_tempo_regression_weight`, `prediction.total_recent_tempo_regression_weight`, `prediction.total_factor_weights.*`, `prediction.total_calibration.*` |
| Win probability | `prediction.spread_to_probability_coefficient`, `prediction.win_probability_calibration.*` |
| Market blend | `prediction.vegas_weight`, `prediction.model_weight_with_vegas` |
| Injury model | `prediction.injury_out_spread_penalty`, `prediction.injury_questionable_spread_penalty`, `prediction.injury_out_total_penalty`, `prediction.injury_questionable_total_penalty`, `prediction.injury_epa_*` |
| True EPA and live possession | `prediction.true_epa.*`, `prediction.live_possession.*` |
| Betting | `betting.edge_thresholds.*`, `betting.filters.*`, `betting.kelly.*` |
| Tournament forecast | `tournament_forecast.selection_weights.*`, `tournament_forecast.champion_weights.*` and related simulation keys |

### WCBB

Source:
- `config/wcbb.php`

| Area | Keys |
| --- | --- |
| Team metrics | `metrics.minimum_games`, `metrics.possession_coefficient`, `metrics.rolling_window_size`, `metrics.max_adjustment_iterations`, `metrics.adjustment_convergence_threshold`, `metrics.adjustment_damping_factor`, `normalization_baseline` |
| Elo | `elo.default`, `elo.base_k_factor`, `elo.playoff_multiplier`, `elo.home_court_advantage`, `elo.margin_multipliers.*` |
| Prediction | `prediction.elo_to_spread_divisor`, `prediction.average_pace`, `prediction.default_efficiency`, `prediction.elo_weight`, `prediction.efficiency_weight`, `prediction.form_weight`, `prediction.spread_to_probability_coefficient`, `prediction.home_court_points`, `prediction.home_away_split_weight`, `prediction.turnover_diff_weight`, `prediction.rebound_margin_weight`, `prediction.total_season_tempo_regression_weight`, `prediction.total_recent_tempo_regression_weight`, `prediction.vegas_weight`, `prediction.model_weight_with_vegas`, `prediction.win_probability_calibration.*` |
| Injury model | `prediction.injury_out_spread_penalty`, `prediction.injury_questionable_spread_penalty`, `prediction.injury_out_total_penalty`, `prediction.injury_questionable_total_penalty`, `prediction.injury_epa_*` |
| True EPA | `prediction.true_epa.*` |

### NFL

Source:
- `config/nfl.php`

| Area | Keys |
| --- | --- |
| Elo | `elo.default_rating`, `elo.base_k_factor`, `elo.home_field_advantage`, `elo.playoff_multiplier`, `elo.recency_multiplier`, `elo.recency_weeks`, `elo.mov_coefficient`, `elo.max_mov_multiplier` |
| Prediction | `predictions.points_per_elo`, `predictions.max_spread`, `predictions.min_spread`, `predictions.average_total` |
| Injury model | `predictions.injury_out_spread_penalty`, `predictions.injury_questionable_spread_penalty`, `predictions.injury_out_total_penalty`, `predictions.injury_questionable_total_penalty`, `predictions.depth_chart.*` |
| True EPA | `predictions.true_epa.*` |
| Betting | `betting.edge_thresholds.*`, `betting.kelly.*` |

### CFB

Source:
- `config/cfb.php`

| Area | Keys |
| --- | --- |
| Elo | `elo.default_rating`, `elo.offseason_regression_factor`, `elo.base_k_factor`, `elo.home_field_advantage`, `elo.playoff_multiplier`, `elo.recency_multiplier`, `elo.recency_weeks`, `elo.mov_coefficient`, `elo.max_mov_multiplier` |
| Prediction | `predictions.points_per_elo`, `predictions.max_spread`, `predictions.min_spread`, `predictions.average_total`, `predictions.min_total`, `predictions.max_total`, `predictions.fpi_spread_weight`, `predictions.wepa_spread_weight`, `predictions.efficiency_spread_weight`, `predictions.wepa_total_offense_weight`, `predictions.wepa_total_defense_weight`, `predictions.fpi_total_weight`, `predictions.use_previous_season_metrics_fallback`, `predictions.model_version`, `predictions.confidence.*` |
| Injury model | `predictions.injury_out_spread_penalty`, `predictions.injury_questionable_spread_penalty`, `predictions.injury_out_total_penalty`, `predictions.injury_questionable_total_penalty` |

### MLB

Source:
- `config/mlb.php`

| Area | Keys |
| --- | --- |
| Filtering | `season.analytics_types` |
| Elo | `elo.default_rating`, `elo.base_k_factor`, `elo.playoff_multiplier`, `elo.home_field_advantage`, `elo.team_weight`, `elo.pitcher_weight`, `elo.recent_starts_limit`, `elo.average_runs_per_game`, `elo.team_regression_factor`, `elo.pitcher_regression_factor`, `elo.pitcher_k_factor`, `elo.pitcher_margin_dampening`, `elo.pitcher_home_field_advantage`, `elo.margin_multipliers.*` |
| Team metrics | `metrics.offensive_rating.runs_multiplier`, `metrics.offensive_rating.batting_avg_multiplier`, `metrics.offensive_rating.home_run_multiplier`, `metrics.pitching_rating.era_scale`, `metrics.pitching_rating.era_max`, `metrics.defensive_rating.fielding_pct_multiplier`, `metrics.defensive_rating.errors_multiplier` |
| Prediction | `prediction.use_previous_season_metrics_fallback`, `prediction.spread_to_probability_coefficient`, `prediction.elo_diff_to_spread_divisor`, `prediction.total_model.base_runs`, `prediction.total_model.average_elo_baseline`, `prediction.total_model.average_elo_divisor`, `prediction.early_season.*` |
| Injury model | `prediction.injury_out_spread_penalty`, `prediction.injury_questionable_spread_penalty`, `prediction.injury_out_total_penalty`, `prediction.injury_questionable_total_penalty`, `prediction.probable_pitcher_out_spread_penalty`, `prediction.probable_pitcher_questionable_spread_penalty`, `prediction.probable_pitcher_out_total_boost`, `prediction.probable_pitcher_questionable_total_boost`, `prediction.depth_chart.*` |
| Forecasting | `playoff_forecast.regression.*`, `playoff_forecast.selection_weights.*` and related playoff forecast keys |

## Metric Column Map

This section maps persisted team-metric columns to their formula family and owning code. Where a column is a simple persisted snapshot of another calculation, the formula column references that upstream function rather than repeating the whole equation.

### NBA and WNBA team metrics

Source:
- `app/Actions/Sports/AbstractProfessionalBasketballCalculateTeamMetrics.php`

| Column | Formula / meaning | Source |
| --- | --- | --- |
| `wins`, `losses` | Win-loss record from completed games | `calculateWinLossRecord()` in `app/Concerns/FiltersTeamGames.php` |
| `offensive_efficiency` | `(total_points / total_possessions) * 100` | `calculateOffensiveEfficiency()` |
| `defensive_efficiency` | `(opponent_points / opponent_possessions) * 100` | `calculateDefensiveEfficiency()` |
| `net_rating` | `offensive_efficiency - defensive_efficiency` | `execute()` |
| `tempo` | `total_possessions / games_played` | `calculateTempo()` |
| `strength_of_schedule` | Average opponent pre-game Elo | `calculateStrengthOfSchedule()` in `app/Concerns/FiltersTeamGames.php` |
| `recent_form_rating` | Weighted recent scoring-margin form metric | `calculateRecentFormRating()` in `app/Concerns/FiltersTeamGames.php` |
| `injury_adjusted_team_rating` | Elo-style team rating after injury weighting | `calculateInjuryAdjustedTeamRating()` in `app/Concerns/FiltersTeamGames.php` |
| `injury_total_adjustment` | Team total-scoring adjustment from injuries | `calculateInjuryAdjustedTotalAdjustment()` in `app/Concerns/FiltersTeamGames.php` |
| `rest_travel_fatigue` | Rest/travel heuristic from schedule spacing and away sequence | `calculateRestTravelFatigue()` in `app/Concerns/FiltersTeamGames.php` |
| `offensive_true_epa_per_play` | Team offensive true EPA per play, if enabled | `calculateTeamTrueEpaMetrics()` in `app/Actions/Sports/Concerns/CalculatesTeamTrueEpaFromPlays.php` |
| `defensive_true_epa_per_play` | Team defensive true EPA per play, if enabled | same as above |
| `net_true_epa_per_play` | `offensive_true_epa_per_play - defensive_true_epa_per_play` in the EPA helper output | same as above |
| `calculation_date` | Date stamp of metric refresh | `execute()` |

Shared possession estimate used by the efficiency and tempo columns:

```text
possessions = FGA - ORB + TO + (possession_coefficient * FTA)
```

### CBB and WCBB team metrics

Source:
- `app/Actions/Sports/AbstractCollegeBasketballCalculateTeamMetrics.php`
- `app/Services/OpponentAdjustmentCalculator.php`

| Column | Formula / meaning | Source |
| --- | --- | --- |
| `wins`, `losses` | Win-loss record from completed games | `calculateWinLossRecord()` in `app/Concerns/FiltersTeamGames.php` |
| `offensive_efficiency` | `(total_points / total_possessions) * 100` | `calculateOffensiveEfficiency()` |
| `defensive_efficiency` | `(opponent_points / opponent_possessions) * 100` | `calculateDefensiveEfficiency()` |
| `net_rating` | `offensive_efficiency - defensive_efficiency` | `execute()` |
| `tempo` | `total_possessions / games_played` | `calculateTempo()` |
| `strength_of_schedule` | Average opponent pre-game Elo | `calculateStrengthOfSchedule()` in `app/Concerns/FiltersTeamGames.php` |
| `recent_form_rating` | Weighted recent scoring-margin form metric | `calculateRecentFormRating()` in `app/Concerns/FiltersTeamGames.php` |
| `injury_adjusted_team_rating` | Elo-style team rating after injury weighting | `calculateInjuryAdjustedTeamRating()` in `app/Concerns/FiltersTeamGames.php` |
| `injury_total_adjustment` | Team total-scoring adjustment from injuries | `calculateInjuryAdjustedTotalAdjustment()` in `app/Concerns/FiltersTeamGames.php` |
| `rest_travel_fatigue` | Rest/travel heuristic from schedule spacing and away sequence | `calculateRestTravelFatigue()` in `app/Concerns/FiltersTeamGames.php` |
| `games_played` | Count of completed games used in the calculation | `execute()` |
| `meets_minimum` | `games_played >= config('{sport}.metrics.minimum_games')` | `execute()` |
| `possession_coefficient` | Persisted config snapshot for possession estimation | `execute()` |
| `rolling_offensive_efficiency` | Offensive efficiency over the last `rolling_window_size` games | `calculateRollingMetrics()` |
| `rolling_defensive_efficiency` | Defensive efficiency over the last `rolling_window_size` games | `calculateRollingMetrics()` |
| `rolling_net_rating` | `rolling_offensive_efficiency - rolling_defensive_efficiency` | `calculateRollingMetrics()` |
| `rolling_tempo` | Tempo over the last `rolling_window_size` games | `calculateRollingMetrics()` |
| `rolling_games_count` | Number of games in the rolling window actually used | `calculateRollingMetrics()` |
| `home_offensive_efficiency` | Offensive efficiency in home games only | `calculateHomeAwayMetrics()` |
| `home_defensive_efficiency` | Defensive efficiency in home games only | `calculateHomeAwayMetrics()` |
| `away_offensive_efficiency` | Offensive efficiency in away games only | `calculateHomeAwayMetrics()` |
| `away_defensive_efficiency` | Defensive efficiency in away games only | `calculateHomeAwayMetrics()` |
| `home_games` | Count of home games in split calculation | `calculateHomeAwayMetrics()` |
| `away_games` | Count of away games in split calculation | `calculateHomeAwayMetrics()` |
| `adj_offensive_efficiency` | Iterative opponent-adjusted offense, normalized to baseline | `OpponentAdjustmentCalculator::persistAdjustedMetrics()` |
| `adj_defensive_efficiency` | Iterative opponent-adjusted defense, normalized to baseline | same as above |
| `adj_net_rating` | `adj_offensive_efficiency - adj_defensive_efficiency` | same as above |
| `adj_tempo` | Opponent-adjusted tempo after iterative normalization | same as above |
| `iteration_count` | Iterations recorded for the opponent-adjustment run | `OpponentAdjustmentCalculator::setIterationCount()` |
| `offensive_true_epa_per_play` | Team offensive true EPA per play | `calculateTeamTrueEpaMetrics()` |
| `defensive_true_epa_per_play` | Team defensive true EPA per play | same as above |
| `net_true_epa_per_play` | Net true EPA per play | same as above |
| `calculation_date` | Date stamp of metric refresh | `execute()` |

Shared possession estimate used by the college basketball efficiency and tempo columns:

```text
possessions = FGA - ORB + TO + (possession_coefficient * FTA)
```

Opponent-adjusted per-game transforms:

```text
adjusted_off_eff = raw_off_eff * (league_avg_def / opponent_current_def)
adjusted_def_eff = raw_def_eff * (league_avg_off / opponent_current_off)
adjusted_tempo   = raw_possessions * (league_avg_tempo / opponent_current_tempo)
```

### NFL team metrics

Source:
- `app/Actions/NFL/CalculateTeamMetrics.php`
- `app/Actions/Sports/Concerns/CalculatesGridironTeamMetrics.php`

| Column | Formula / meaning | Source |
| --- | --- | --- |
| `wins`, `losses` | Win-loss record from completed games | `calculateWinLossRecord()` in `app/Concerns/FiltersTeamGames.php` |
| `offensive_rating` | `points_per_game` | `execute()` |
| `defensive_rating` | `points_allowed_per_game` | `execute()` |
| `net_rating` | `offensive_rating - defensive_rating` | `execute()` |
| `points_per_game` | Average points scored | `calculateAverage()` |
| `points_allowed_per_game` | Average points allowed | `calculateAverage()` |
| `yards_per_game` | Average offensive yards | `calculateAverageYards()` |
| `yards_allowed_per_game` | Average defensive yards allowed | `calculateAverageYards()` |
| `passing_yards_per_game` | Average passing yards | `calculateAveragePassingYards()` |
| `rushing_yards_per_game` | Average rushing yards | `calculateAverageRushingYards()` |
| `turnover_differential` | `(opp_int + opp_fumbles_lost - team_int - team_fumbles_lost) / games_played` | `calculateTurnoverDifferential()` |
| `strength_of_schedule` | Average opponent pre-game Elo | `calculateStrengthOfSchedule()` in `app/Concerns/FiltersTeamGames.php` |
| `recent_form_rating` | Weighted recent scoring-margin form metric | `calculateRecentFormRating()` in `app/Concerns/FiltersTeamGames.php` |
| `injury_adjusted_team_rating` | Elo-style team rating after injury weighting | `calculateInjuryAdjustedTeamRating()` in `app/Concerns/FiltersTeamGames.php` |
| `injury_total_adjustment` | Team total-scoring adjustment from injuries | `calculateInjuryAdjustedTotalAdjustment()` in `app/Concerns/FiltersTeamGames.php` |
| `rest_travel_fatigue` | Rest/travel heuristic from schedule spacing and away sequence | `calculateRestTravelFatigue()` in `app/Concerns/FiltersTeamGames.php` |
| `predictive_rating` | `(net_rating * 0.65) + (recent_form_rating * 0.25) + (turnover_differential * 0.75) + ((season_sos - league_average_elo) / 25)` | `calculatePredictiveRating()` |
| `home_rating`, `away_rating` | Average scoring margin split by venue | `averageFromContexts()` over `margin` |
| `home_advantage_rating` | `home_rating - away_rating` | `execute()` |
| `future_strength_of_schedule` | Average opponent Elo over remaining schedule | `calculateFutureStrengthOfSchedule()` |
| `season_strength_of_schedule` | Same season-wide SOS used in base metrics | `execute()` |
| `strength_of_schedule_basic` | Simplified average opponent-strength context score | `calculateBasicStrengthOfSchedule()` |
| `in_division_strength_of_schedule` | Average opponent Elo in division games | `averageFromContexts()` |
| `non_division_strength_of_schedule` | Average opponent Elo in non-division games | `averageFromContexts()` |
| `last_5_rating`, `last_10_rating` | Average margin over last 5 or 10 games | `recentWindowMargin()` |
| `in_division_rating`, `non_division_rating` | Average margin in division or non-division games | `averageFromContexts()` |
| `luck_rating` | Record over/under-performance versus point differential expectation | `calculateLuckRating()` |
| `consistency_rating` | Margin-volatility consistency score | `calculateConsistencyRating()` |
| `vs_1_to_5_rating`, `vs_6_to_10_rating`, `vs_11_to_16_rating`, `vs_17_to_22_rating`, `vs_23_to_32_rating` | Average margin versus opponent Elo-rank bucket | `averageMarginForRankBucket()` |
| `first_half_rating`, `second_half_rating` | Average first-half and second-half margin | `averageFromContexts()` with split half margins |
| `offensive_true_epa_per_play` | Team offensive true EPA per play | `calculateTeamTrueEpaMetrics()` |
| `defensive_true_epa_per_play` | Team defensive true EPA per play | same as above |
| `net_true_epa_per_play` | Net true EPA per play | same as above |
| `calculation_date` | Date stamp of metric refresh | `execute()` |

### CFB team metrics

Source:
- `app/Actions/CFB/CalculateTeamMetrics.php`
- `app/Actions/Sports/Concerns/CalculatesGridironTeamMetrics.php`
- `app/Services/CollegeFootballData/CollegeFootballDataService.php`

| Column | Formula / meaning | Source |
| --- | --- | --- |
| `wins`, `losses` | Win-loss record from completed games, or `0/0` preseason seed row | `calculateWinLossRecord()` / `buildPreseasonMetric()` |
| `fpi` | Latest imported FPI rating for team and season | `latestFpiForTeam()` |
| `offensive_rating` | `points_per_game` | `execute()` |
| `defensive_rating` | `points_allowed_per_game` | `execute()` |
| `net_rating` | `offensive_rating - defensive_rating` | `execute()` |
| `points_per_game`, `points_allowed_per_game` | Average points scored / allowed | `calculateAverage()` |
| `yards_per_game`, `yards_allowed_per_game` | Average yards gained / allowed | `calculateAverageYards()` |
| `passing_yards_per_game`, `rushing_yards_per_game` | Average passing / rushing yards | `calculateAveragePassingYards()`, `calculateAverageRushingYards()` |
| `turnover_differential` | Shared gridiron turnover differential formula | `calculateTurnoverDifferential()` |
| `strength_of_schedule` | Average opponent pre-game Elo | `calculateStrengthOfSchedule()` in `app/Concerns/FiltersTeamGames.php` |
| `recent_form_rating` | Weighted recent scoring-margin form metric | `calculateRecentFormRating()` in `app/Concerns/FiltersTeamGames.php` |
| `injury_adjusted_team_rating` | Elo-style team rating after injury weighting | `calculateInjuryAdjustedTeamRating()` in `app/Concerns/FiltersTeamGames.php` |
| `injury_total_adjustment` | Team total-scoring adjustment from injuries | `calculateInjuryAdjustedTotalAdjustment()` in `app/Concerns/FiltersTeamGames.php` |
| `rest_travel_fatigue` | Rest/travel heuristic from schedule spacing and away sequence | `calculateRestTravelFatigue()` in `app/Concerns/FiltersTeamGames.php` |
| `cfbd_wepa_offense`, `cfbd_wepa_defense` | Imported WEPA offense / defense values | `wepaForTeam()` |
| `cfbd_wepa_net` | Imported or derived `offense - defense` WEPA net | `wepaForTeam()` |
| `cfbd_wepa_payload` | Raw WEPA payload snapshot from CFBD | `wepaForTeam()` |
| `power_rating` | `(min(18, net_rating) * 0.400) + (((strength_of_schedule ?? 1500) - 1500) * 0.020) + (recent_form_rating * 0.180) + (fpi * 0.150) + (wepa_net * 4.000)` | `calculatePowerRating()` |
| `resume_rating` | Composite resume score from win pct, SOS, game quality, location, titles, and penalties | `calculateResumeRating()` |
| `cfp_rating` | Normalized blend of power and resume ratings | `calculateCfpRating()` |
| `offensive_true_epa_per_play` | Team offensive true EPA per play | `calculateTeamTrueEpaMetrics()` |
| `defensive_true_epa_per_play` | Team defensive true EPA per play | same as above |
| `net_true_epa_per_play` | Net true EPA per play | same as above |
| `calculation_date` | Date stamp of metric refresh | `persistMetric()` |

### MLB team metrics

Source:
- `app/Actions/MLB/CalculateTeamMetrics.php`

| Column | Formula / meaning | Source |
| --- | --- | --- |
| `wins`, `losses` | Win-loss record from completed games | `calculateWinLossRecord()` in `app/Concerns/FiltersTeamGames.php` |
| `offensive_rating` | `(runs_per_game * runs_multiplier) + (batting_average * batting_avg_multiplier) + (home_run_rate * home_run_multiplier)` | `calculateOffensiveRating()` |
| `pitching_rating` | `max(0, era_max - (era * era_scale)) + strikeouts_per_game - walks_per_game` | `calculatePitchingRating()` |
| `defensive_rating` | `(fielding_pct * fielding_pct_multiplier) + putouts_per_game + assists_per_game - (errors_per_game * errors_multiplier)` | `calculateDefensiveRating()` |
| `runs_per_game` | Average runs scored | `calculateRunsPerGame()` |
| `runs_allowed_per_game` | Average runs allowed | `calculateRunsAllowedPerGame()` |
| `run_differential_per_game` | `runs_per_game - runs_allowed_per_game` | `execute()` |
| `home_runs_per_game` | Average home runs | `calculateHomeRunsPerGame()` |
| `batting_average` | `hits / at_bats` | `calculateBattingAverage()` |
| `on_base_percentage` | OBP from hits, walks, hit by pitch, and at-bats / plate components | `calculateOnBasePercentage()` |
| `slugging_percentage` | `total_bases / at_bats` | `calculateSluggingPercentage()` |
| `ops` | `on_base_percentage + slugging_percentage` | `execute()` |
| `team_era` | `(earned_runs / innings_pitched) * 9` | `calculateTeamEra()` |
| `strikeouts_pitched_per_game` | Average pitching strikeouts | `calculateStrikeoutsPitchedPerGame()` |
| `whip` | `(walks_allowed + hits_allowed) / innings_pitched` | `calculateWhip()` |
| `strength_of_schedule` | Average opponent pre-game Elo | `calculateStrengthOfSchedule()` in `app/Concerns/FiltersTeamGames.php` |
| `recent_form_rating` | Weighted recent scoring-margin form metric | `calculateRecentFormRating()` in `app/Concerns/FiltersTeamGames.php` |
| `injury_adjusted_team_rating` | Elo-style team rating after injury weighting | `calculateInjuryAdjustedTeamRating()` in `app/Concerns/FiltersTeamGames.php` |
| `injury_total_adjustment` | Team total-scoring adjustment from injuries | `calculateInjuryAdjustedTotalAdjustment()` in `app/Concerns/FiltersTeamGames.php` |
| `rest_travel_fatigue` | Rest/travel heuristic from schedule spacing and travel | `calculateRestTravelFatigue()` in `app/Concerns/FiltersTeamGames.php` |
| `season_type` | Regular / postseason analytics scope used for the row | `resolveMetricSeasonType()` |
| `calculation_date` | Date stamp of metric refresh | `execute()` |

## Related Files

Useful files when extending or auditing calculations:
- `app/Services/MetricValidator.php`
- `app/Services/Predictions/PredictionFeatureSnapshotRecorder.php`
- `app/Services/Predictions/PredictionEvaluationRecorder.php`
- `app/Services/PlayerStats/NbaPlayerEpaCalculator.php`
- `app/Services/PlayerStats/NflPlayerEpaCalculator.php`
- `app/Services/NBA/TrueEpaCalculator.php`
- `app/Services/NFL/TrueEpaCalculator.php`
- `app/Services/CFB/TrueEpaCalculator.php`

## Maintenance Note

When you change a formula:
- update this document
- update or add tests near the owning action/service
- keep config-driven coefficients documented in the relevant `config/{sport}.php` file
