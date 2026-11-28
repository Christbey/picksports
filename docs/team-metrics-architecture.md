# Team Metrics Architecture

## System Overview

The team metrics system calculates performance metrics for sports teams across multiple leagues. It processes game and team statistics from the ESPN API, applies sport-specific formulas, and generates metrics used for predictions and dashboards.

### Supported Sports
- **NFL** (National Football League) - Points-based ratings with turnover differential
- **NBA** (National Basketball Association) - Possession-based efficiency metrics
- **CBB** (College Basketball - Men's) - Opponent-adjusted efficiency with convergence
- **WCBB** (College Basketball - Women's) - Opponent-adjusted efficiency with convergence
- **MLB** (Major League Baseball) - Custom formulas for offensive/pitching/defensive ratings
- **CFB** (College Football) - Same approach as NFL
- **WNBA** (Women's National Basketball Association) - Same approach as NBA

### Purpose
1. **Standardize team performance measurement** across different sports
2. **Enable predictive modeling** by quantifying team strength
3. **Support data-driven insights** for dashboards and analytics
4. **Track team performance trends** over time

---

## Data Flow Diagram

```
ESPN API → Sync Commands → Games/TeamStats Tables
               ↓
       CalculateTeamMetrics Actions
               ↓
          TeamMetric Models
               ↓
       Predictions & Dashboard
```

### Detailed Flow

1. **Data Ingestion**: ESPN API provides games and statistics via sync commands
2. **Storage**: Games and TeamStats are stored in sport-specific database tables
3. **Calculation**: CalculateTeamMetrics actions process raw stats into metrics
4. **Persistence**: Metrics are stored in sport-specific TeamMetric models
5. **Consumption**: Predictions engine and dashboards use calculated metrics

---

## Architecture Patterns

### Actions Pattern
All metric calculations use the **Action pattern** (`app/Actions/{Sport}/CalculateTeamMetrics.php`):
- `execute(Team $team, int $season): ?TeamMetric` - Calculate metrics for one team
- `executeForAllTeams(int $season): int` - Calculate metrics for all teams

### Traits for Code Reuse
- **FiltersTeamGames**: Queries completed games for a team/season
- **GathersTeamStats**: Extracts team and opponent statistics from games
- **DisplaysTeamMetrics**: Formats command output for Artisan commands

### Service Layer
- **OpponentAdjustmentCalculator**: Iterative opponent-adjustment for CBB/WCBB (Ken Pomeroy methodology)
- **MetricValidator**: Validates metrics are within expected sport-specific ranges

### Configuration
Sport-specific settings in `config/{sport}.php`:
- Game statuses
- Possession coefficients
- Metric calculation multipliers
- Validation thresholds
- Opponent adjustment parameters

---

## Sports-Specific Implementations

### NFL & CFB (Football)
**Metrics:**
- Offensive Rating: Points per game
- Defensive Rating: Points allowed per game
- Net Rating: Offensive - Defensive rating
- Yards Per Game: Total offensive yards average
- Turnover Differential: (Opponent turnovers - Team turnovers) / games

**Formula Approach**: Simple averages and differentials

**Files:**
- `app/Actions/NFL/CalculateTeamMetrics.php`
- `app/Actions/CFB/CalculateTeamMetrics.php`

---

### NBA & WNBA (Basketball)
**Metrics:**
- Offensive Efficiency: Points per 100 possessions
- Defensive Efficiency: Opponent points per 100 possessions
- Net Rating: Offensive - Defensive efficiency
- Tempo: Average possessions per game

**Formula Approach**: Possession-based using Dean Oliver's formula
- `Possessions = FGA - ORB + TO + (0.44 * FTA)`

**Files:**
- `app/Actions/NBA/CalculateTeamMetrics.php`
- `app/Actions/WNBA/CalculateTeamMetrics.php`

**Configuration:**
- `config/nba.php` - `possession_coefficient: 0.44`
- `config/wnba.php` - `possession_coefficient: 0.44`

---

### CBB & WCBB (College Basketball)
**Metrics:**
- Offensive Efficiency: Points per 100 possessions (raw and opponent-adjusted)
- Defensive Efficiency: Opponent points per 100 possessions (raw and opponent-adjusted)
- Net Rating: Offensive - Defensive efficiency
- Tempo: Average possessions per game
- Rolling metrics: Last N games efficiency/tempo
- Home/Away splits: Separate metrics for home and away games

**Formula Approach**:
1. Calculate raw efficiency metrics
2. Apply iterative opponent adjustment (Ken Pomeroy methodology)
3. Normalize to baseline (100.0 for college basketball)

**Opponent Adjustment Process**:
1. Initialize adjusted values from raw values
2. Iterate:
   - For each team, calculate average opponent rating
   - Apply damping factor to smooth convergence
   - Update adjusted ratings
3. Continue until convergence or max iterations reached
4. Normalize all values to baseline

**Special Features**:
- Minimum games threshold before metrics are calculated
- Possession coefficient tuned to 0.40 for college game (vs 0.44 for NBA)
- Separate rolling window metrics (last N games)
- Home/away performance splits

**Files:**
- `app/Actions/CBB/CalculateTeamMetrics.php`
- `app/Actions/WCBB/CalculateTeamMetrics.php`
- `app/Services/OpponentAdjustmentCalculator.php`

**Configuration:**
- `config/cbb.php`:
  - `metrics.minimum_games: 5`
  - `metrics.possession_coefficient: 0.40`
  - `metrics.rolling_window_size: 10`
  - `metrics.max_adjustment_iterations: 10`
  - `metrics.adjustment_convergence_threshold: 0.1`
  - `metrics.adjustment_damping_factor: 0.5`
  - `normalization_baseline: 100.0`

---

### MLB (Baseball)

The current offense and pitching ratings are configurable, 100-centered custom indices. Offense combines runs/game, OPS and home runs/game relative to configured league reference values. Pitching combines runs allowed/game, ERA, WHIP, strikeouts-minus-walks/game and home runs allowed/game; stronger run prevention increases the index. Both normally clamp to 50–180, with explicit zero sentinels for missing games/innings or nonfinite results. They are not OPS+, FIP, calibrated probabilities or a claim of forecasting improvement.

The defensive index retains the custom fielding/putout/assist/error proxy. See [the exact equations and missing-component limitations](calculations-reference.md#mlb-team-metrics). Rates and indices are distinct; normalized baseball innings are required for ERA/WHIP.

Owning source: `app/Actions/MLB/CalculateTeamMetrics.php`; coefficients: `config/mlb.php` under `metrics.offensive_rating`, `metrics.pitching_rating`, and `metrics.defensive_rating`. This source check is dated November 25, 2026, not a live configuration audit.

---

## Configuration Reference

### Game Status Configuration
All sports use status configuration to identify completed games:

```php
// config/nfl.php
'statuses' => [
    'final' => 'STATUS_FINAL',
]
```

### MLB metric configuration defaults

```php
'metrics' => [
    'offensive_rating' => [
        'baseline' => 100.0, 'min' => 50.0, 'max' => 180.0,
        'league_runs_per_game' => 4.40, 'league_ops' => 0.720,
        'league_home_runs_per_game' => 1.10,
        'runs_per_game_weight' => 12.0, 'ops_weight' => 120.0,
        'home_run_rate_weight' => 5.0,
    ],
    'pitching_rating' => [
        'baseline' => 100.0, 'min' => 50.0, 'max' => 180.0,
        'league_runs_allowed_per_game' => 4.40, 'league_era' => 4.20,
        'league_whip' => 1.30, 'league_k_minus_walks_per_game' => 5.20,
        'league_home_runs_allowed_per_game' => 1.10,
        'runs_allowed_weight' => 8.0, 'era_weight' => 7.0,
        'whip_weight' => 35.0, 'k_minus_walk_weight' => 3.0,
        'home_runs_allowed_weight' => 4.0,
    ],
    'defensive_rating' => [
        'fielding_pct_multiplier' => 100,
        'errors_multiplier' => 10,
    ],
]
```

These are source defaults, not verified effective production values. The configuration file owns environment override names.

### CBB/WCBB Opponent Adjustment Configuration
```php
// config/cbb.php
'metrics' => [
    'minimum_games' => 5,
    'possession_coefficient' => 0.40,
    'rolling_window_size' => 10,
    'max_adjustment_iterations' => 10,
    'adjustment_convergence_threshold' => 0.1,
    'adjustment_damping_factor' => 0.5,
],
'normalization_baseline' => 100.0,
```

---

## Testing Strategy

### Feature Tests
All `CalculateTeamMetrics` actions have feature tests:
- `tests/Feature/{Sport}/CalculateTeamMetricsTest.php`

### Test Coverage
- **Action classes**: Test metric calculations with known inputs
- **Edge cases**: No games, no stats, insufficient data
- **Formula accuracy**: Verify calculations match expected values
- **Opponent adjustment**: Test convergence behavior
- **Service classes**: Isolated tests for OpponentAdjustmentCalculator and MetricValidator

### Running Tests
```bash
# All tests
php artisan test

# Sport-specific tests
php artisan test tests/Feature/NFL/
php artisan test tests/Feature/NBA/
php artisan test tests/Feature/CBB/
php artisan test tests/Feature/WCBB/
php artisan test tests/Feature/MLB/
```

---

## Common Patterns

### 1. Querying Completed Games
Use the `FiltersTeamGames` trait:
```php
$games = $this->getCompletedGamesForTeam($team, $season, 'NFL');
```

### 2. Gathering Team Statistics
Use the `GathersTeamStats` trait:
```php
extract($this->gatherTeamStatsFromGames($games, $team));
// Provides: $teamStats, $opponentStats, $opponentElos
```

### 3. Calculating Strength of Schedule
```php
$strengthOfSchedule = $this->calculateStrengthOfSchedule($opponentElos);
```

### 4. Validating Metrics
Always validate before saving:
```php
$validator = new MetricValidator();
$validator->validate([
    'offensive_rating' => $offensiveRating,
    'defensive_rating' => $defensiveRating,
    // ...
], 'nfl', [
    'team_id' => $team->id,
    'season' => $season,
]);
```

### 5. Logging
Log key events:
```php
Log::info('Team metrics calculated', [
    'team_id' => $team->id,
    'team_name' => $team->name,
    'season' => $season,
    'games_count' => $games->count(),
    'offensive_rating' => round($offensiveRating, 1),
]);
```

---

## Troubleshooting Guide

### "No completed games found"
**Cause**: No games marked as final for the team/season
**Solution**: Run the sync command for that sport first
```bash
php artisan nfl:sync-games --season=2024
```

### "Opponent adjustment not converging"
**Cause**: Iteration limit reached before convergence threshold met
**Solution**: Increase `max_adjustment_iterations` in config or adjust `adjustment_damping_factor`

### "Metrics out of range"
**Cause**: Calculated value exceeds validation thresholds
**Solution**:
1. Check for data quality issues in TeamStats
2. Verify formulas are correct
3. Adjust thresholds in `MetricValidator` if expectations were wrong

### "Missing team stats"
**Cause**: ESPN API data incomplete or sync failed
**Solution**:
1. Check ESPN API response
2. Re-run sync command with `--force` flag
3. Verify TeamStat records exist in database

### "Metrics not updating"
**Cause**: `calculation_date` not changing after re-calculation
**Solution**: Metrics use `updateOrCreate()` - verify the calculation is actually running and completing

---

## Key Files Reference

### Actions
- `app/Actions/NFL/CalculateTeamMetrics.php`
- `app/Actions/NBA/CalculateTeamMetrics.php`
- `app/Actions/CBB/CalculateTeamMetrics.php`
- `app/Actions/WCBB/CalculateTeamMetrics.php`
- `app/Actions/MLB/CalculateTeamMetrics.php`
- `app/Actions/CFB/CalculateTeamMetrics.php`
- `app/Actions/WNBA/CalculateTeamMetrics.php`

### Services
- `app/Services/OpponentAdjustmentCalculator.php`
- `app/Services/MetricValidator.php`

### Traits
- `app/Concerns/FiltersTeamGames.php`
- `app/Concerns/GathersTeamStats.php`
- `app/Concerns/DisplaysTeamMetrics.php`

### Configuration
- `config/nfl.php`, `config/nba.php`, `config/cbb.php`, `config/wcbb.php`, `config/mlb.php`

### Commands
- `app/Console/Commands/{Sport}/CalculateTeamMetricsCommand.php`

---

## Future Enhancements

### Potential Improvements
1. **Machine Learning Integration**: Use calculated metrics as features for ML models
2. **Real-time Updates**: Calculate metrics incrementally as games complete
3. **Historical Trending**: Track metric changes over multiple seasons
4. **Advanced Metrics**: Implement sport-specific advanced stats (e.g., PER, DVOA)
5. **Performance Optimization**: Cache frequently-accessed metrics
6. **API Endpoints**: Expose metrics via REST API for external consumption

### Scalability Considerations
- Queue metric calculations for batch processing
- Implement database indexing on frequently-queried columns
- Consider read replicas for high-traffic dashboards
- Archive historical metrics to separate tables

---

## References

### Basketball Metrics
- **Dean Oliver's "Basketball on Paper"** - Possession formula and efficiency ratings
- **Ken Pomeroy's Methodology** - Opponent-adjusted ratings and convergence algorithm

### Baseball Metrics
- **Sabermetrics** - Weighted offensive/pitching formulas
- **Fielding Percentage** - Defensive rating calculations

### Configuration
- Sport-specific config files (`config/{sport}.php`)
- Validation thresholds in `MetricValidator.php`

---

**Last Updated**: February 2026
**Maintainer**: Development Team
