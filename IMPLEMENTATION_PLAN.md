# Sports Metrics System - Comprehensive Review & Implementation Plan

## Executive Summary

This document provides a detailed architectural review of all sports implementations (NFL, NBA, CBB, WCBB, MLB, CFB, WNBA) and a comprehensive implementation plan to address identified issues. The plan is organized into 4 phases over an estimated 44-62 hours of work, prioritizing critical missing tests, then code consolidation, then long-term architectural improvements.

---

## Comprehensive Architectural Review

### 🔴 Critical Issues

#### 1. Missing Implementations (2 sports)

**CFB (College Football):**
- No `CalculateTeamMetrics` action
- No `CalculateTeamMetricsCommand` command
- Models and tables exist but no calculation logic

**WNBA (Women's National Basketball Association):**
- No `CalculateTeamMetrics` action
- No `CalculateTeamMetricsCommand` command
- Models and tables exist but no calculation logic

#### 2. Missing Tests (3 sports)

**NFL:**
- 0 tests (all calculations completely untested)
- High risk: newly created code with no validation

**WCBB:**
- 0 tests (identical to CBB but no test coverage)
- High risk: complex opponent adjustment logic untested

**MLB:**
- 0 tests (non-standard formulas completely untested)
- Critical risk: custom formulas have no validation

#### 3. MLB Formula Validity

MLB uses custom formulas that don't match industry standards:

**Offensive Rating** (line 116):
```php
($runsPerGame * 20) + ($battingAvg * 100) + ($homeRunRate * 10)
```

**Pitching Rating** (line 145):
```php
max(0, 100 - ($era * 10)) + $strikeoutsPerGame - $walksPerGame
```

**Issues:**
- Not aligned with sabermetrics standards (wRC+, wOBA, FIP, ERA+)
- Multipliers (20, 100, 10) are arbitrary
- No documentation explaining formula rationale
- Zero validation against expected ranges

**Recommendation:** Validate against industry standards or document why custom formulas are preferred.

---

### 🟡 High-Priority Code Quality Issues

#### 1. Massive Code Duplication

Every sport duplicates this exact pattern (~40 lines each):

```php
// Repeated in NFL, NBA, CBB, WCBB, MLB
$games = Game::query()
    ->where('season', $season)
    ->where('status', 'STATUS_FINAL')
    ->where(function ($query) use ($team) {
        $query->where('home_team_id', $team->id)
            ->orWhere('away_team_id', $team->id);
    })
    ->with(['teamStats', 'homeTeam', 'awayTeam'])
    ->get();

if ($games->isEmpty()) {
    return null;
}

// Gather team stats
$teamStats = [];
$opponentStats = [];
$opponentElos = [];

foreach ($games as $game) {
    $isHome = $game->home_team_id === $team->id;
    $teamStat = $game->teamStats->firstWhere('team_id', $team->id);
    $opponentId = $isHome ? $game->away_team_id : $game->home_team_id;
    $opponentStat = $game->teamStats->firstWhere('team_id', $opponentId);

    if ($teamStat) {
        $teamStats[] = $teamStat;
    }

    if ($opponentStat) {
        $opponentStats[] = $opponentStat;
    }

    $opponent = $isHome ? $game->awayTeam : $game->homeTeam;
    if ($opponent && $opponent->elo_rating) {
        $opponentElos[] = $opponent->elo_rating;
    }
}
```

**Impact:** 200+ duplicate lines across all sports

**Recommendation:** Extract to base class or trait (`FiltersTeamGames` and `GathersTeamStats`)

#### 2. Hard-Coded Values

Found throughout all files:

**Status Values:**
```php
->where('status', 'STATUS_FINAL')  // Should be config('nfl.statuses.final')
```

**MLB Multipliers:**
```php
($runsPerGame * 20) + ($battingAvg * 100) + ($homeRunRate * 10)
// Should be config('mlb.metrics.offensive_rating.runs_multiplier')
```

**Rounding Precision:**
- Varies between 1-3 decimals across sports
- Should be standardized and documented

**Recommendation:** Move all values to config files for maintainability

#### 3. Single Responsibility Violations

**CBB/WCBB `calculateOpponentAdjustments()` method:**
- 170+ lines in a single method
- Doing multiple things:
  - Metrics fetching
  - Iteration initialization
  - Convergence calculation
  - Normalization

**Recommendation:** Split into 4-5 separate methods or extract to separate service class

---

### ✅ What's Working Well

#### 1. Test Coverage (2 sports)

**NBA:**
- 8 comprehensive tests ✓
- Covers all major calculations
- Tests edge cases

**CBB:**
- 11 comprehensive tests ✓
- Includes opponent adjustments
- Tests convergence logic
- Tests rolling windows and home/away splits

#### 2. Architecture Consistency

**Correct Patterns:**
- CBB and WCBB are correctly identical (both college basketball)
- All commands follow similar CLI pattern
- All actions follow similar structure
- Action pattern properly separates business logic

#### 3. Logic Soundness

**Well-Implemented Concepts:**
- Dean Oliver's possession formula correctly implemented
- Division by zero guards in place
- Null coalescing used consistently
- Proper use of Eloquent relationships

---

## 📊 Sports Implementation Status

| Sport | Action | Command | Tests | Data | Status                   |
|-------|--------|---------|-------|------|--------------------------|
| NFL   | ✓      | ✓       | ✗     | ✓    | Needs Tests              |
| NBA   | ✓      | ✓       | ✓     | ✓    | Complete                 |
| CBB   | ✓      | ✓       | ✓     | ✓    | Complete                 |
| WCBB  | ✓      | ✓       | ✗     | ✓    | Needs Tests              |
| MLB   | ✓      | ✓       | ✗     | ?    | Needs Tests & Validation |
| CFB   | ✗      | ✗       | ✗     | ✗    | Not Implemented          |
| WNBA  | ✗      | ✗       | ✗     | ✗    | Not Implemented          |

---

## 🎯 Recommendations Priority Order

### Immediate Actions (Critical Priority)

1. **Write Tests for NFL** (`app/Actions/NFL/CalculateTeamMetrics.php:1-201`)
   - Test turnover differential calculation
   - Test yard metrics (total, passing, rushing)
   - Test points-based ratings
   - Validate formulas with known inputs

2. **Validate MLB Formulas** (`app/Actions/MLB/CalculateTeamMetrics.php:89-178`)
   - Compare against fangraphs/sabermetrics standards
   - Document formula reasoning
   - Consider using wRC+ or wOBA for offense
   - Consider using FIP or ERA+ for pitching

3. **Write Tests for WCBB** (`app/Actions/WCBB/CalculateTeamMetrics.php:1-443`)
   - Clone CBB test structure
   - Adjust for WCBB-specific config
   - Validate opponent adjustment logic

### Short-Term Refactoring (High Priority)

4. **Extract Base Class/Trait**
   Create `BaseTeamMetricsCalculator` or trait with common methods:
   - `getCompletedGamesForTeam()`
   - `gatherTeamStats()`
   - `calculateStrengthOfSchedule()`
   - `executeForAllTeams()`

5. **Move Hard-Coded Values to Config**
   - Replace all `'STATUS_FINAL'` with config values
   - Move MLB multipliers to `config/mlb.php`
   - Document all config values

6. **Standardize Rounding**
   - Use 1 decimal for all efficiency/rating metrics
   - Use 3 decimals for SOS
   - Use 3 decimals for batting average
   - Use 2 decimals for ERA

### Long-Term Improvements (Medium Priority)

7. **Implement CFB & WNBA**
   - CFB: Use NFL-style (yards-based) when data available
   - WNBA: Use NBA-style (possession-based) when data available

8. **Split Large Methods**
   - Break CBB/WCBB `calculateOpponentAdjustments()` into smaller methods
   - Improve testability and maintainability

---

## 📝 Files That Need Work

### Files to Create

**Tests:**
- `tests/Feature/NFL/CalculateTeamMetricsTest.php`
- `tests/Feature/WCBB/CalculateTeamMetricsTest.php`
- `tests/Feature/MLB/CalculateTeamMetricsTest.php`
- `tests/Feature/CFB/CalculateTeamMetricsTest.php` (future)
- `tests/Feature/WNBA/CalculateTeamMetricsTest.php` (future)

**Traits:**
- `app/Concerns/FiltersTeamGames.php`
- `app/Concerns/GathersTeamStats.php`
- `app/Console/Commands/Concerns/DisplaysTeamMetrics.php`

**Services:**
- `app/Services/OpponentAdjustmentCalculator.php`
- `app/Services/MetricValidator.php`

**Config:**
- Update `config/mlb.php` with formula multipliers

**Actions & Commands (Future):**
- `app/Actions/CFB/CalculateTeamMetrics.php`
- `app/Console/Commands/CFB/CalculateTeamMetricsCommand.php`
- `app/Actions/WNBA/CalculateTeamMetrics.php`
- `app/Console/Commands/WNBA/CalculateTeamMetricsCommand.php`

### Files to Refactor

**All CalculateTeamMetrics Actions:**
- `app/Actions/NFL/CalculateTeamMetrics.php` - Extract common code
- `app/Actions/NBA/CalculateTeamMetrics.php` - Extract common code
- `app/Actions/CBB/CalculateTeamMetrics.php` - Extract common code, split large method
- `app/Actions/WCBB/CalculateTeamMetrics.php` - Extract common code, split large method
- `app/Actions/MLB/CalculateTeamMetrics.php` - Extract common code, validate formulas

**All CalculateTeamMetricsCommand Classes:**
- `app/Console/Commands/NFL/CalculateTeamMetricsCommand.php` - Consolidate output
- `app/Console/Commands/NBA/CalculateTeamMetricsCommand.php` - Consolidate output
- `app/Console/Commands/CBB/CalculateTeamMetricsCommand.php` - Consolidate output
- `app/Console/Commands/WCBB/CalculateTeamMetricsCommand.php` - Consolidate output
- `app/Console/Commands/MLB/CalculateTeamMetricsCommand.php` - Consolidate output

### Specific Fixes Needed

**Replace Hard-Coded Status:**
- Find: `->where('status', 'STATUS_FINAL')`
- Replace: `->where('status', config('[sport].statuses.final'))`
- Files: All 5 CalculateTeamMetrics actions

**Add Error Handling:**
- `app/Actions/CBB/CalculateTeamMetrics.php` (lines 313-319)
- `app/Actions/WCBB/CalculateTeamMetrics.php` (lines 313-319)
- Add array size checks before division in opponent adjustments

---

# Implementation Plan

## Overview

This plan addresses 7 sports implementations across 4 phases over an estimated 44-62 hours of work. The plan prioritizes critical missing tests, then code consolidation, then long-term architectural improvements.

---

## Phase 1: Critical Fixes & Testing (16-20 hours)

### Objective
Eliminate critical risks by adding test coverage for untested code and fixing immediate bugs.

---

### Task 1.1: Write NFL Team Metrics Tests (4-5 hours)

**File:** `tests/Feature/NFL/CalculateTeamMetricsTest.php` (CREATE)

**Test Cases:**

1. `it_calculates_basic_team_metrics()`
   - Verify offensive_rating = points per game
   - Verify defensive_rating = points allowed per game
   - Verify net_rating = offensive - defensive

2. `it_calculates_yards_metrics()`
   - Verify yards_per_game calculation
   - Verify passing_yards_per_game calculation
   - Verify rushing_yards_per_game calculation
   - Verify yards_allowed_per_game calculation

3. `it_calculates_turnover_differential()`
   - Create games with interceptions and fumbles
   - Verify formula: (opponent turnovers - team turnovers) / games
   - Test edge case: verify team and opponent stats have same count

4. `it_calculates_strength_of_schedule()`
   - Verify average of opponent ELO ratings
   - Test with varying opponent strengths

5. `it_returns_null_when_no_completed_games()`
   - Verify null returned for teams without games

6. `it_filters_non_final_games()`
   - Create mix of final and in-progress games
   - Verify only STATUS_FINAL games included

7. `it_updates_existing_metrics()`
   - Create metric, run again, verify upsert behavior

8. `it_handles_multiple_seasons()`
   - Verify metrics calculated separately per season

**Acceptance Criteria:**
- ✓ All 8 tests passing
- ✓ Code coverage >90% for CalculateTeamMetrics

---

### Task 1.2: Write WCBB Team Metrics Tests (3-4 hours)

**File:** `tests/Feature/WCBB/CalculateTeamMetricsTest.php` (CREATE)

**Approach:** Clone CBB tests, adjust for WCBB-specific config

**Test Cases:** Same 11 tests as CBB:
1. Basic metrics calculation
2. Rolling window metrics
3. Home/away splits
4. Minimum games threshold
5. Possession estimation
6. Non-final game filtering
7. Upsert behavior
8. Multi-season handling
9. Opponent-adjusted metrics calculation
10. Convergence iteration count
11. Normalization to baseline

**Acceptance Criteria:**
- ✓ All 11 tests passing
- ✓ Identical coverage to CBB tests

---

### Task 1.3: Write MLB Team Metrics Tests (4-5 hours)

**File:** `tests/Feature/MLB/CalculateTeamMetricsTest.php` (CREATE)

**Test Cases:**

1. `it_calculates_offensive_rating()`
   - Verify formula: (runs/game * 20) + (BA * 100) + (HR rate * 10)
   - Test with known inputs, verify output

2. `it_calculates_pitching_rating()`
   - Verify ERA component: max(0, 100 - ERA*10)
   - Verify K/BB components
   - Test boundary: ERA > 10 caps at 0

3. `it_calculates_defensive_rating()`
   - Verify fielding percentage calculation
   - Verify putouts/assists/errors weighting

4. `it_calculates_runs_metrics()`
   - Verify runs_per_game
   - Verify runs_allowed_per_game

5. `it_calculates_batting_average()`
   - Verify hits / at_bats calculation

6. `it_calculates_team_era()`
   - Verify (earned_runs / innings) * 9

7. `it_returns_null_when_no_games()`

8. `it_handles_multiple_seasons()`

**Acceptance Criteria:**
- ✓ All 8 tests passing
- ✓ Formulas validated against sample data

---

### Task 1.4: Validate MLB Formulas Against Industry Standards (3-4 hours)

**Research Required:**
- Compare offensive_rating to wRC+ or wOBA
- Compare pitching_rating to ERA+, FIP, or WHIP
- Compare defensive_rating to DRS or UZR

**Files to Update:**
- `app/Actions/MLB/CalculateTeamMetrics.php` (lines 89-178)
- `config/mlb.php` (ADD formula documentation)

**Deliverables:**
1. Documentation comment explaining each formula's basis
2. Config values for all multipliers (20, 100, 10, etc.)
3. Reference links to industry standards
4. Decision: Keep custom formulas OR switch to standard metrics

**Example Config Update:**
```php
// config/mlb.php
'metrics' => [
    'offensive_rating' => [
        'runs_multiplier' => 20,
        'batting_avg_multiplier' => 100,
        'home_run_multiplier' => 10,
        'note' => 'Custom weighted formula emphasizing BA and R/G',
        'reference' => 'Internal formula - not based on sabermetrics',
    ],
    'pitching_rating' => [
        'era_scale' => 10,
        'era_max' => 100,
        'note' => 'Inverse ERA with K/BB adjustment',
        'reference' => 'Custom formula',
    ],
],
```

**Acceptance Criteria:**
- ✓ All formulas documented
- ✓ Decision made on keeping vs changing formulas
- ✓ Config updated with multipliers

---

### Task 1.5: Fix CBB/WCBB Opponent Adjustment Bug (1 hour)

**Files:**
- `app/Actions/CBB/CalculateTeamMetrics.php` (lines 313-319)
- `app/Actions/WCBB/CalculateTeamMetrics.php` (lines 313-319)

**Current Code:**
```php
while (!$converged && $iterationCount < config('cbb.metrics.max_adjustment_iterations')) {
    $leagueAvgDefense = array_sum($currentDefense) / count($currentDefense);
    $leagueAvgOffense = array_sum($currentOffense) / count($currentOffense);
    $leagueAvgTempo = array_sum($currentTempo) / count($currentTempo);
```

**Fixed Code:**
```php
while (!$converged && $iterationCount < config('cbb.metrics.max_adjustment_iterations')) {
    // Defensive check - should never happen due to earlier isEmpty check
    if (empty($currentDefense) || empty($currentOffense) || empty($currentTempo)) {
        \Log::warning('Empty metrics array during opponent adjustment', [
            'season' => $season,
            'iteration' => $iterationCount,
        ]);
        break;
    }

    $leagueAvgDefense = array_sum($currentDefense) / count($currentDefense);
    $leagueAvgOffense = array_sum($currentOffense) / count($currentOffense);
    $leagueAvgTempo = array_sum($currentTempo) / count($currentTempo);
```

**Acceptance Criteria:**
- ✓ No division by zero possible
- ✓ Warning logged if unexpected state
- ✓ Add test case for empty metrics edge case

---

### Task 1.6: Replace Hard-Coded STATUS_FINAL (2 hours)

**Affected Files:** ALL Action classes

**Current Pattern:**
```php
->where('status', 'STATUS_FINAL')
```

**New Pattern:**
```php
->where('status', config('nfl.statuses.final'))
```

**Config Files to Create/Update:**
```php
// config/nfl.php
'statuses' => ['final' => 'STATUS_FINAL'],

// config/nba.php
'statuses' => ['final' => 'STATUS_FINAL'],

// config/cbb.php, wcbb.php, mlb.php (same pattern)
```

**Files to Update:**
1. `app/Actions/NFL/CalculateTeamMetrics.php` (line 17)
2. `app/Actions/NBA/CalculateTeamMetrics.php` (line 17)
3. `app/Actions/CBB/CalculateTeamMetrics.php` (line 17)
4. `app/Actions/WCBB/CalculateTeamMetrics.php` (line 17)
5. `app/Actions/MLB/CalculateTeamMetrics.php` (line 17)

**Acceptance Criteria:**
- ✓ All status checks use config values
- ✓ Existing tests still pass
- ✓ No hard-coded 'STATUS_FINAL' strings remain

---

## Phase 2: Code Consolidation & Cleanup (12-16 hours)

### Objective
Reduce code duplication and improve maintainability through extraction of common patterns.

---

### Task 2.1: Extract Common Game Filtering (3-4 hours)

**Create Trait:** `app/Concerns/FiltersTeamGames.php`

```php
<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

trait FiltersTeamGames
{
    /**
     * Get completed games for a team in a specific season.
     *
     * @param Model $team The team model
     * @param int $season The season year
     * @param string $sport Sport abbreviation (NFL, NBA, etc.)
     * @return Collection Collection of completed games
     */
    protected function getCompletedGamesForTeam(
        Model $team,
        int $season,
        string $sport
    ): Collection {
        $gameModel = "App\\Models\\{$sport}\\Game";

        return $gameModel::query()
            ->where('season', $season)
            ->where('status', config(strtolower($sport).'.statuses.final'))
            ->where(function ($query) use ($team) {
                $query->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->with(['teamStats', 'homeTeam', 'awayTeam'])
            ->get();
    }
}
```

**Update All Actions:**
```php
use App\Concerns\FiltersTeamGames;

class CalculateTeamMetrics
{
    use FiltersTeamGames;

    public function execute(Team $team, int $season): ?TeamMetric
    {
        $games = $this->getCompletedGamesForTeam($team, $season, 'NFL');

        if ($games->isEmpty()) {
            return null;
        }

        // ... rest of calculations
    }
}
```

**Files to Update:**
1. `app/Actions/NFL/CalculateTeamMetrics.php`
2. `app/Actions/NBA/CalculateTeamMetrics.php`
3. `app/Actions/CBB/CalculateTeamMetrics.php`
4. `app/Actions/WCBB/CalculateTeamMetrics.php`
5. `app/Actions/MLB/CalculateTeamMetrics.php`

**Acceptance Criteria:**
- ✓ All tests still pass
- ✓ 40+ lines removed per file
- ✓ Consistent game filtering across all sports

---

### Task 2.2: Extract Common Team Stats Gathering (3-4 hours)

**Add to Trait:** `app/Concerns/FiltersTeamGames.php`

```php
/**
 * Gather team and opponent stats from games.
 *
 * @param Collection $games Collection of games
 * @param Model $team The team to gather stats for
 * @return array ['teamStats' => [], 'opponentStats' => [], 'opponentElos' => []]
 */
protected function gatherTeamStatsFromGames(
    Collection $games,
    Model $team
): array {
    $teamStats = [];
    $opponentStats = [];
    $opponentElos = [];

    foreach ($games as $game) {
        $isHome = $game->home_team_id === $team->id;

        $teamStat = $game->teamStats->firstWhere('team_id', $team->id);
        $opponentId = $isHome ? $game->away_team_id : $game->home_team_id;
        $opponentStat = $game->teamStats->firstWhere('team_id', $opponentId);

        if ($teamStat) {
            $teamStats[] = $teamStat;
        }

        if ($opponentStat) {
            $opponentStats[] = $opponentStat;
        }

        $opponent = $isHome ? $game->awayTeam : $game->homeTeam;
        if ($opponent && $opponent->elo_rating) {
            $opponentElos[] = $opponent->elo_rating;
        }
    }

    return compact('teamStats', 'opponentStats', 'opponentElos');
}
```

**Usage in Actions:**
```php
$games = $this->getCompletedGamesForTeam($team, $season, 'NFL');

if ($games->isEmpty()) {
    return null;
}

extract($this->gatherTeamStatsFromGames($games, $team));

if (empty($teamStats)) {
    return null;
}

// Continue with calculations using $teamStats, $opponentStats, $opponentElos
```

**Acceptance Criteria:**
- ✓ All actions use shared method
- ✓ 20+ lines removed per file
- ✓ Consistent stats gathering

---

### Task 2.3: Extract Strength of Schedule Calculation (1 hour)

**Add to Trait:** `app/Concerns/FiltersTeamGames.php`

```php
/**
 * Calculate strength of schedule from opponent ELOs.
 *
 * @param array $opponentElos Array of opponent ELO ratings
 * @param int $precision Decimal places to round to (default 3)
 * @return float|null Average opponent ELO or null if no opponents
 */
protected function calculateStrengthOfSchedule(
    array $opponentElos,
    int $precision = 3
): ?float {
    if (empty($opponentElos)) {
        return null;
    }

    return round(array_sum($opponentElos) / count($opponentElos), $precision);
}
```

**Standardize Precision:**
- All sports use 3 decimals for SOS
- Update MLB from 2 to 3 decimals for consistency

**Acceptance Criteria:**
- ✓ Consistent precision across all sports
- ✓ Method removed from 5 files
- ✓ Single source of truth for SOS calculation

---

### Task 2.4: Consolidate Command Output Logic (3-4 hours)

**Create Trait:** `app/Console/Commands/Concerns/DisplaysTeamMetrics.php`

```php
<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Collection;

trait DisplaysTeamMetrics
{
    /**
     * Display top teams table.
     *
     * @param int $season
     * @param string $modelClass TeamMetric model class
     * @param string $orderByColumn Column to order by
     * @param int $limit Number of teams to display
     * @param array $columns ['headers' => [], 'fields' => []]
     */
    protected function displayTopTeamsByRating(
        int $season,
        string $modelClass,
        string $orderByColumn,
        int $limit = 10,
        array $columns = []
    ): void {
        $topTeams = $modelClass::query()
            ->where('season', $season)
            ->with('team')
            ->orderBy($orderByColumn, 'desc')
            ->limit($limit)
            ->get();

        if ($topTeams->isEmpty()) {
            $this->warn('No metrics found for this season.');
            return;
        }

        $this->table(
            $columns['headers'],
            $topTeams->map(fn ($metric, $index) =>
                $this->formatMetricRow($metric, $index + 1, $columns['fields'])
            )
        );
    }

    /**
     * Display progress bar for bulk operations.
     *
     * @param Collection $items Items to process
     * @param callable $callback Function to call for each item
     * @return int Number of items successfully processed
     */
    protected function runWithProgressBar(
        Collection $items,
        callable $callback
    ): int {
        $bar = $this->output->createProgressBar($items->count());
        $bar->start();

        $processed = 0;
        foreach ($items as $item) {
            if ($callback($item)) {
                $processed++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        return $processed;
    }

    /**
     * Format metric row for table display.
     *
     * @param mixed $metric TeamMetric model
     * @param int $rank Team rank
     * @param array $fields Fields to display
     * @return array Formatted row
     */
    protected function formatMetricRow($metric, int $rank, array $fields): array
    {
        $row = [$rank, $this->getTeamDisplayName($metric->team)];

        foreach ($fields as $field => $decimals) {
            $row[] = round($metric->$field, $decimals);
        }

        return $row;
    }

    /**
     * Get team display name.
     */
    protected function getTeamDisplayName($team): string
    {
        // Handle different team name structures
        if (isset($team->city) && isset($team->name)) {
            return "{$team->city} {$team->name}";
        }

        if (isset($team->location) && isset($team->name)) {
            return "{$team->location} {$team->name}";
        }

        return $team->name ?? 'Unknown Team';
    }
}
```

**Update All Commands:**
```php
use App\Console\Commands\Concerns\DisplaysTeamMetrics;

class CalculateTeamMetricsCommand extends Command
{
    use DisplaysTeamMetrics;

    public function handle(): int
    {
        // Use shared methods
        $calculated = $this->runWithProgressBar(
            $teams,
            fn($team) => $calculateMetrics->execute($team, $season)
        );

        $this->info("Calculated metrics for {$calculated} teams.");

        $this->displayTopTeamsByRating(
            $season,
            TeamMetric::class,
            'net_rating',
            10,
            [
                'headers' => ['Rank', 'Team', 'Off Rtg', 'Def Rtg', 'Net Rtg'],
                'fields' => [
                    'offensive_rating' => 1,
                    'defensive_rating' => 1,
                    'net_rating' => 1,
                ],
            ]
        );

        return Command::SUCCESS;
    }
}
```

**Acceptance Criteria:**
- ✓ 50+ lines removed per command
- ✓ Consistent output format across all sports
- ✓ Easier to maintain

---

### Task 2.5: Move MLB Hard-Coded Multipliers to Config (1 hour)

**Update:** `config/mlb.php`

```php
<?php

return [
    'statuses' => [
        'final' => 'STATUS_FINAL',
    ],

    'metrics' => [
        'offensive_rating' => [
            'runs_multiplier' => env('MLB_OFFENSIVE_RUNS_MULT', 20),
            'batting_avg_multiplier' => env('MLB_OFFENSIVE_BA_MULT', 100),
            'home_run_multiplier' => env('MLB_OFFENSIVE_HR_MULT', 10),
        ],
        'pitching_rating' => [
            'era_scale' => env('MLB_PITCHING_ERA_SCALE', 10),
            'era_max' => env('MLB_PITCHING_ERA_MAX', 100),
        ],
        'defensive_rating' => [
            'fielding_pct_multiplier' => env('MLB_DEFENSIVE_FLD_MULT', 100),
            'errors_multiplier' => env('MLB_DEFENSIVE_ERR_MULT', 10),
        ],
    ],
];
```

**Update:** `app/Actions/MLB/CalculateTeamMetrics.php`

```php
protected function calculateOffensiveRating(array $teamStats): float
{
    // ... calculations ...

    return ($runsPerGame * config('mlb.metrics.offensive_rating.runs_multiplier'))
        + ($battingAvg * config('mlb.metrics.offensive_rating.batting_avg_multiplier'))
        + ($homeRunRate * config('mlb.metrics.offensive_rating.home_run_multiplier'));
}

protected function calculatePitchingRating(array $teamStats): float
{
    // ... calculations ...

    $eraComponent = max(
        0,
        config('mlb.metrics.pitching_rating.era_max')
        - ($era * config('mlb.metrics.pitching_rating.era_scale'))
    );

    return $eraComponent + $strikeoutsPerGame - $walksPerGame;
}
```

**Acceptance Criteria:**
- ✓ No hard-coded multipliers in code
- ✓ All values configurable via env
- ✓ Default values match current behavior

---

### Task 2.6: Standardize Rounding Precision (1 hour)

**Standard:**
- Efficiency/Rating metrics: **1 decimal**
- Strength of Schedule: **3 decimals**
- Batting Average: **3 decimals**
- ERA: **2 decimals**

**Update All Actions:**
```php
// Standardize to 1 decimal for ratings
'offensive_rating' => round($offensiveRating, 1),
'defensive_rating' => round($defensiveRating, 1),
'net_rating' => round($netRating, 1),
'points_per_game' => round($pointsPerGame, 1),

// 3 decimals for SOS (already done in trait)
'strength_of_schedule' => $this->calculateStrengthOfSchedule($opponentElos, 3),

// 3 decimals for batting average
'batting_average' => round($battingAverage, 3),

// 2 decimals for ERA
'team_era' => round($era, 2),
```

**Acceptance Criteria:**
- ✓ Consistent precision documented in comments
- ✓ All commands display same decimal places
- ✓ Database stores correct precision

---

## Phase 3: Long-Term Improvements (12-20 hours)

### Objective
Improve architecture, implement missing sports, enhance testability.

---

### Task 3.1: Implement CFB Team Metrics (4-5 hours)

**Files to CREATE:**
1. `app/Actions/CFB/CalculateTeamMetrics.php`
2. `app/Console/Commands/CFB/CalculateTeamMetricsCommand.php`
3. `tests/Feature/CFB/CalculateTeamMetricsTest.php`

**Approach:** Clone NFL implementation (yards-based, football metrics)

**Metrics:**
- `offensive_rating` (points per game)
- `defensive_rating` (points allowed per game)
- `net_rating`
- `yards_per_game`
- `yards_allowed_per_game`
- `passing_yards_per_game`
- `rushing_yards_per_game`
- `turnover_differential`
- `strength_of_schedule`

**Example Action:**
```php
<?php

namespace App\Actions\CFB;

use App\Concerns\FiltersTeamGames;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;

class CalculateTeamMetrics
{
    use FiltersTeamGames;

    public function execute(Team $team, int $season): ?TeamMetric
    {
        $games = $this->getCompletedGamesForTeam($team, $season, 'CFB');

        if ($games->isEmpty()) {
            return null;
        }

        extract($this->gatherTeamStatsFromGames($games, $team));

        if (empty($teamStats)) {
            return null;
        }

        // Calculate CFB-specific metrics (same as NFL)
        // ...

        return TeamMetric::updateOrCreate(
            ['team_id' => $team->id, 'season' => $season],
            [/* metrics */]
        );
    }
}
```

**Acceptance Criteria:**
- ✓ Command runs successfully
- ✓ 8+ tests passing
- ✓ Uses shared traits from Phase 2
- ✓ Follows established patterns

---

### Task 3.2: Implement WNBA Team Metrics (4-5 hours)

**Files to CREATE:**
1. `app/Actions/WNBA/CalculateTeamMetrics.php`
2. `app/Console/Commands/WNBA/CalculateTeamMetricsCommand.php`
3. `tests/Feature/WNBA/CalculateTeamMetricsTest.php`

**Approach:** Clone NBA implementation (possession-based basketball metrics)

**Metrics:**
- `offensive_efficiency` (points per 100 possessions)
- `defensive_efficiency` (points allowed per 100 possessions)
- `net_rating`
- `tempo` (possessions per game)
- `strength_of_schedule`

**Config:**
```php
// config/wnba.php
'metrics' => [
    'possession_coefficient' => 0.44, // Same as NBA
    'baseline_efficiency' => 100.0,
],
```

**Example Action:**
```php
<?php

namespace App\Actions\WNBA;

use App\Concerns\FiltersTeamGames;
use App\Models\WNBA\Team;
use App\Models\WNBA\TeamMetric;

class CalculateTeamMetrics
{
    use FiltersTeamGames;

    public function execute(Team $team, int $season): ?TeamMetric
    {
        $games = $this->getCompletedGamesForTeam($team, $season, 'WNBA');

        if ($games->isEmpty()) {
            return null;
        }

        // Use same possession formula as NBA
        // Dean Oliver's formula: FGA - ORB + TO + (0.44 * FTA)

        return TeamMetric::updateOrCreate(
            ['team_id' => $team->id, 'season' => $season],
            [/* metrics */]
        );
    }
}
```

**Acceptance Criteria:**
- ✓ Command runs successfully
- ✓ 8+ tests passing
- ✓ Uses shared traits
- ✓ Same possession formula as NBA

---

### Task 3.3: Split CBB/WCBB Opponent Adjustment into Separate Class (3-4 hours)

**Create:** `app/Services/OpponentAdjustmentCalculator.php`

```php
<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class OpponentAdjustmentCalculator
{
    private int $season;
    private string $sport;
    private int $maxIterations;
    private float $convergenceThreshold;

    public function __construct(string $sport, int $season)
    {
        $this->sport = $sport;
        $this->season = $season;
        $this->maxIterations = config("{$sport}.metrics.max_adjustment_iterations", 10);
        $this->convergenceThreshold = config("{$sport}.metrics.convergence_threshold", 0.1);
    }

    /**
     * Calculate opponent-adjusted metrics for all teams.
     *
     * @param Collection $metrics Collection of TeamMetric models
     * @param Collection $games Collection of Game models
     */
    public function calculate(Collection $metrics, Collection $games): void
    {
        Log::info('Starting opponent adjustment', [
            'sport' => $this->sport,
            'season' => $this->season,
            'teams_count' => $metrics->count(),
        ]);

        $currentMetrics = $this->initializeMetrics($metrics);
        $convergenceData = $this->runIterativeAdjustment($currentMetrics, $games);
        $this->normalizeToBaseline($currentMetrics);
        $this->saveAdjustedMetrics($currentMetrics, $metrics);

        Log::info('Opponent adjustment complete', $convergenceData);
    }

    /**
     * Initialize metrics arrays for iteration.
     */
    private function initializeMetrics(Collection $metrics): array
    {
        $currentOffense = [];
        $currentDefense = [];
        $currentTempo = [];

        foreach ($metrics as $metric) {
            $currentOffense[$metric->team_id] = $metric->offensive_efficiency;
            $currentDefense[$metric->team_id] = $metric->defensive_efficiency;
            $currentTempo[$metric->team_id] = $metric->tempo;
        }

        return compact('currentOffense', 'currentDefense', 'currentTempo');
    }

    /**
     * Run iterative adjustment until convergence.
     */
    private function runIterativeAdjustment(array &$currentMetrics, Collection $games): array
    {
        $converged = false;
        $iterationCount = 0;

        while (!$converged && $iterationCount < $this->maxIterations) {
            $newMetrics = $this->performIteration($currentMetrics, $games);
            $maxChange = $this->calculateMaxChange($currentMetrics, $newMetrics);

            $currentMetrics = $newMetrics;
            $converged = $maxChange < $this->convergenceThreshold;
            $iterationCount++;
        }

        return [
            'iterations' => $iterationCount,
            'converged' => $converged,
            'max_change' => $maxChange ?? 0,
        ];
    }

    /**
     * Perform single iteration of opponent adjustment.
     */
    private function performIteration(array $currentMetrics, Collection $games): array
    {
        // Perform adjustment calculations
        // ...
        return $newMetrics;
    }

    /**
     * Calculate maximum change between iterations.
     */
    private function calculateMaxChange(array $old, array $new): float
    {
        $maxChange = 0;

        foreach ($old['currentOffense'] as $teamId => $oldValue) {
            $change = abs($new['currentOffense'][$teamId] - $oldValue);
            $maxChange = max($maxChange, $change);
        }

        return $maxChange;
    }

    /**
     * Normalize metrics to baseline (typically 100).
     */
    private function normalizeToBaseline(array &$metrics): void
    {
        $baseline = config("{$this->sport}.metrics.baseline_efficiency", 100.0);
        // Normalization logic
        // ...
    }

    /**
     * Save adjusted metrics back to database.
     */
    private function saveAdjustedMetrics(array $adjusted, Collection $metrics): void
    {
        foreach ($metrics as $metric) {
            $metric->update([
                'offensive_efficiency' => $adjusted['currentOffense'][$metric->team_id],
                'defensive_efficiency' => $adjusted['currentDefense'][$metric->team_id],
                'tempo' => $adjusted['currentTempo'][$metric->team_id],
            ]);
        }
    }
}
```

**Update CBB/WCBB Actions:**
```php
public function executeForAllTeams(int $season): int
{
    $teams = Team::all();
    $calculated = 0;

    foreach ($teams as $team) {
        $metric = $this->execute($team, $season);
        if ($metric) {
            $calculated++;
        }
    }

    // Use service for opponent adjustments
    $adjuster = new OpponentAdjustmentCalculator('cbb', $season);
    $adjuster->calculate(
        TeamMetric::where('season', $season)->get(),
        Game::where('season', $season)->get()
    );

    return $calculated;
}
```

**Acceptance Criteria:**
- ✓ 170-line method split into 4-5 smaller methods
- ✓ Each method <50 lines
- ✓ Easier to unit test
- ✓ All existing tests still pass
- ✓ Logging added for debugging

---

### Task 3.4: Add Comprehensive Logging (2-3 hours)

**Add to All Actions:**

```php
use Illuminate\Support\Facades\Log;

public function execute(Team $team, int $season): ?TeamMetric
{
    $games = $this->getCompletedGamesForTeam($team, $season, 'NFL');

    if ($games->isEmpty()) {
        Log::info('No completed games found for team', [
            'team_id' => $team->id,
            'team_name' => $team->name,
            'season' => $season,
            'sport' => 'nfl',
        ]);
        return null;
    }

    // ... calculations ...

    Log::info('Team metrics calculated', [
        'team_id' => $team->id,
        'team_name' => $team->name,
        'season' => $season,
        'games_count' => $games->count(),
        'offensive_rating' => round($offensiveRating, 1),
        'defensive_rating' => round($defensiveRating, 1),
        'net_rating' => round($netRating, 1),
    ]);

    return TeamMetric::updateOrCreate(...);
}
```

**Add to Opponent Adjustment:**
```php
Log::info('Starting opponent adjustment', [
    'sport' => $this->sport,
    'season' => $this->season,
    'teams_count' => $metrics->count(),
    'max_iterations' => $this->maxIterations,
]);

// After convergence
Log::info('Opponent adjustment complete', [
    'sport' => $this->sport,
    'season' => $this->season,
    'iterations' => $iterationCount,
    'converged' => $converged,
    'max_change' => $maxChange,
]);
```

**Acceptance Criteria:**
- ✓ All metric calculations logged
- ✓ Convergence iterations logged
- ✓ Useful context included (team names, counts, etc.)
- ✓ Helpful for debugging anomalies

---

### Task 3.5: Add Metric Validation & Warnings (2-3 hours)

**Add Validator:** `app/Services/MetricValidator.php`

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class MetricValidator
{
    private const THRESHOLDS = [
        'nfl' => [
            'offensive_rating' => ['min' => 10, 'max' => 40],
            'defensive_rating' => ['min' => 10, 'max' => 40],
            'net_rating' => ['min' => -20, 'max' => 20],
            'yards_per_game' => ['min' => 200, 'max' => 500],
            'turnover_differential' => ['min' => -3, 'max' => 3],
        ],
        'nba' => [
            'offensive_efficiency' => ['min' => 90, 'max' => 130],
            'defensive_efficiency' => ['min' => 90, 'max' => 130],
            'tempo' => ['min' => 90, 'max' => 110],
        ],
        'cbb' => [
            'offensive_efficiency' => ['min' => 80, 'max' => 130],
            'defensive_efficiency' => ['min' => 80, 'max' => 130],
            'tempo' => ['min' => 60, 'max' => 85],
        ],
        'mlb' => [
            'offensive_rating' => ['min' => 0, 'max' => 200],
            'pitching_rating' => ['min' => 0, 'max' => 150],
            'team_era' => ['min' => 2.0, 'max' => 7.0],
            'batting_average' => ['min' => 0.200, 'max' => 0.350],
        ],
    ];

    /**
     * Validate metrics are within expected ranges.
     *
     * @param array $metrics Key-value pairs of metric names and values
     * @param string $sport Sport abbreviation
     * @param array $context Additional context for logging
     */
    public function validate(array $metrics, string $sport, array $context = []): void
    {
        $sport = strtolower($sport);

        if (!isset(self::THRESHOLDS[$sport])) {
            Log::warning('No validation thresholds defined for sport', [
                'sport' => $sport,
            ]);
            return;
        }

        foreach ($metrics as $key => $value) {
            if (isset(self::THRESHOLDS[$sport][$key])) {
                $threshold = self::THRESHOLDS[$sport][$key];

                if ($value < $threshold['min'] || $value > $threshold['max']) {
                    Log::warning('Metric out of expected range', array_merge([
                        'sport' => $sport,
                        'metric' => $key,
                        'value' => $value,
                        'expected_min' => $threshold['min'],
                        'expected_max' => $threshold['max'],
                    ], $context));
                }
            }
        }
    }

    /**
     * Get thresholds for a specific sport.
     */
    public static function getThresholds(string $sport): array
    {
        return self::THRESHOLDS[strtolower($sport)] ?? [];
    }
}
```

**Use in Actions:**
```php
use App\Services\MetricValidator;

public function execute(Team $team, int $season): ?TeamMetric
{
    // ... calculations ...

    // Validate before saving
    $validator = new MetricValidator();
    $validator->validate([
        'offensive_rating' => $offensiveRating,
        'defensive_rating' => $defensiveRating,
        'net_rating' => $netRating,
        'yards_per_game' => $yardsPerGame,
        'turnover_differential' => $turnoverDifferential,
    ], 'nfl', [
        'team_id' => $team->id,
        'team_name' => $team->name,
        'season' => $season,
    ]);

    return TeamMetric::updateOrCreate(...);
}
```

**Acceptance Criteria:**
- ✓ Warnings logged for anomalous values
- ✓ Helps catch bugs in calculations
- ✓ Thresholds documented in validator
- ✓ Easy to adjust thresholds per sport

---

## Phase 4: Documentation & Final Review (4-6 hours)

### Task 4.1: Document All Formulas (2 hours)

**Add PHPDoc blocks to all calculation methods:**

```php
/**
 * Calculate offensive rating for NFL team.
 *
 * Formula: Points scored per game (simple average)
 *
 * Expected Range: 10-40 points per game
 *
 * @param array<int, TeamStat> $teamStats Collection of team statistics
 * @return float Points per game
 */
protected function calculateOffensiveRating(array $teamStats): float
{
    // ...
}

/**
 * Calculate turnover differential.
 *
 * Formula: (Opponent Turnovers - Team Turnovers) / Games
 * Where Turnovers = Interceptions + Fumbles Lost
 *
 * A positive value means the team forces more turnovers than it commits,
 * which is generally correlated with winning percentage.
 *
 * Expected Range: -3 to +3 per game
 *
 * @param array<int, TeamStat> $teamStats Team's statistics
 * @param array<int, TeamStat> $opponentStats Opponent statistics
 * @return float Average turnover differential per game
 */
protected function calculateTurnoverDifferential(
    array $teamStats,
    array $opponentStats
): float {
    // ...
}

/**
 * Calculate possession estimate using Dean Oliver's formula.
 *
 * Formula: FGA - ORB + TO + (0.44 * FTA)
 *
 * This formula estimates the number of possessions a team used.
 * The 0.44 coefficient accounts for the fact that not all free throw
 * attempts end a possession.
 *
 * Reference: Basketball on Paper by Dean Oliver
 * Expected Range: 90-110 possessions per game (NBA)
 *
 * @param TeamStat $stat Team statistics for a single game
 * @return float Estimated possessions
 */
protected function calculatePossessions(TeamStat $stat): float
{
    // ...
}
```

**Documentation Checklist:**
- ✓ Every calculation method has PHPDoc
- ✓ Formula explained in plain English
- ✓ Expected value ranges noted
- ✓ References cited where applicable (Oliver, sabermetrics, etc.)
- ✓ Edge cases mentioned

---

### Task 4.2: Create Architecture Documentation (2 hours)

**Create:** `docs/team-metrics-architecture.md`

**Contents:**

1. **System Overview**
   - Purpose of team metrics system
   - Sports supported
   - Data flow from ESPN API to predictions

2. **Data Flow Diagram**
   ```
   ESPN API → Sync Commands → Games/TeamStats Tables
                ↓
        CalculateTeamMetrics Actions
                ↓
           TeamMetric Models
                ↓
        Predictions & Dashboard
   ```

3. **Sports-Specific Implementations**
   - NFL: Points-based ratings, turnover differential
   - NBA: Possession-based efficiency
   - CBB/WCBB: Opponent-adjusted with convergence
   - MLB: Custom formulas (runs, BA, HR, ERA)
   - CFB: Same as NFL (football)
   - WNBA: Same as NBA (basketball)

4. **Configuration Reference**
   ```php
   // config/nfl.php
   'statuses' => ['final' => 'STATUS_FINAL']

   // config/mlb.php
   'metrics' => [
       'offensive_rating' => [
           'runs_multiplier' => 20,
           // ...
       ]
   ]
   ```

5. **Testing Strategy**
   - Feature tests for all Action classes
   - Test edge cases (no games, no stats)
   - Test formula accuracy with known inputs
   - Test opponent adjustment convergence

6. **Common Patterns**
   - Use `FiltersTeamGames` trait for game queries
   - Use `GathersTeamStats` trait for stat collection
   - Use `DisplaysTeamMetrics` trait for command output
   - Always validate metrics with `MetricValidator`

7. **Troubleshooting Guide**
   - "No completed games found" → Run sync command first
   - "Opponent adjustment not converging" → Check max iterations config
   - "Metrics out of range" → Check validation thresholds
   - "Missing team stats" → Verify ESPN API data

**Acceptance Criteria:**
- ✓ New developers can understand system from docs
- ✓ Diagrams show data flow clearly
- ✓ Examples provided for each sport
- ✓ Troubleshooting section covers common issues

---

### Task 4.3: Run Full Test Suite & Verify Coverage (1-2 hours)

**Commands:**
```bash
# Run all tests
php artisan test --coverage

# Verify coverage targets
php artisan test --coverage --min=80

# Run specific sport tests
php artisan test tests/Feature/NFL/
php artisan test tests/Feature/NBA/
php artisan test tests/Feature/CBB/
php artisan test tests/Feature/WCBB/
php artisan test tests/Feature/MLB/
```

**Coverage Targets:**
- Overall coverage: >80%
- CalculateTeamMetrics classes: >90%
- Commands: >70%
- Traits: >85%

**Verification Checklist:**
- ✓ All tests passing
- ✓ No skipped tests
- ✓ No warnings or deprecations
- ✓ Coverage targets met
- ✓ No regressions from refactoring

**Acceptance Criteria:**
- ✓ All tests passing (100% pass rate)
- ✓ Coverage meets or exceeds targets
- ✓ Coverage report generated and reviewed

---

### Task 4.4: Final Code Review & Cleanup (1-2 hours)

**Cleanup Checklist:**
- ✓ No hard-coded values (use config)
- ✓ No duplicate code (extracted to traits)
- ✓ Consistent naming conventions
- ✓ All methods <50 lines
- ✓ All classes follow Single Responsibility Principle
- ✓ Laravel Pint passes with no changes
- ✓ No unused imports
- ✓ No commented-out code
- ✓ Config values documented
- ✓ All public methods have PHPDoc

**Run Quality Tools:**
```bash
# Format code
vendor/bin/pint

# Run tests
php artisan test

# Check for unused code
# (manual review or use tool like PHP_CodeSniffer)
```

**Final Review:**
1. Review all changes in git diff
2. Verify no breaking changes
3. Verify all recommendations addressed
4. Check documentation is complete
5. Verify config files have sensible defaults

**Acceptance Criteria:**
- ✓ Pint passes with 0 changes needed
- ✓ All tests pass
- ✓ All checklist items complete
- ✓ Code ready for production

---

## Implementation Timeline

| Phase                      | Duration    | Dependencies     | Deliverables                      |
|----------------------------|-------------|------------------|-----------------------------------|
| Phase 1: Critical Fixes    | 16-20 hours | None             | Tests for NFL/WCBB/MLB, bug fixes |
| Phase 2: Consolidation     | 12-16 hours | Phase 1 complete | Traits, config updates            |
| Phase 3: Long-Term         | 12-20 hours | Phase 2 complete | CFB/WNBA, service classes         |
| Phase 4: Documentation     | 4-6 hours   | Phase 3 complete | Docs, final review                |
| **Total**                  | **44-62 hours** | Sequential   | Complete refactored system        |

---

## Rollout Strategy

### Week 1: Phase 1 (Critical)

**Day 1-2: NFL Tests**
- Write 8 comprehensive tests
- Verify formula accuracy
- Document findings

**Day 3: WCBB Tests**
- Clone CBB test structure
- Adjust for WCBB config
- Verify opponent adjustments

**Day 4-5: MLB Tests + Validation**
- Write 8 tests for MLB
- Research sabermetrics standards
- Document formula decisions
- Update config

### Week 2: Phase 1-2 (Critical + Consolidation)

**Day 1: Fixes**
- Fix CBB/WCBB opponent adjustment bug
- Replace all STATUS_FINAL with config

**Day 2-3: Extract Traits**
- Create FiltersTeamGames trait
- Create GathersTeamStats trait
- Update all actions to use traits
- Run tests to verify no regressions

**Day 4-5: Consolidate Commands + Config**
- Create DisplaysTeamMetrics trait
- Update all commands
- Move MLB multipliers to config
- Standardize rounding precision

### Week 3: Phase 3 (Long-Term)

**Day 1-2: CFB Implementation**
- Create CFB Action
- Create CFB Command
- Write 8 CFB tests
- Verify uses shared traits

**Day 3-4: WNBA Implementation**
- Create WNBA Action
- Create WNBA Command
- Write 8 WNBA tests
- Verify matches NBA patterns

**Day 5: Split Opponent Adjustment**
- Create OpponentAdjustmentCalculator service
- Update CBB/WCBB to use service
- Add comprehensive logging
- Verify tests still pass

### Week 4: Phase 3-4 (Long-Term + Documentation)

**Day 1: Logging + Validation**
- Add logging to all actions
- Create MetricValidator service
- Add validation to all actions
- Test warning logs

**Day 2-3: Documentation**
- Add PHPDoc to all methods
- Create architecture documentation
- Document all formulas
- Create troubleshooting guide

**Day 4-5: Final Review + Cleanup**
- Run full test suite
- Verify coverage targets
- Run Laravel Pint
- Final code review
- Document completion

---

## Risk Assessment

### High Risk

**MLB Formula Changes**
- Risk: May break existing dashboards/reports
- Impact: High - users may rely on current numbers
- Mitigation:
  - Validate with stakeholders before changing
  - Consider keeping old formulas as "legacy" option
  - Document why changes were made
  - Provide migration guide

**Refactoring Breaking Tests**
- Risk: Shared code could break sport-specific logic
- Impact: High - broken calculations affect predictions
- Mitigation:
  - Run tests after each phase, not just at end
  - Use git branches for each major change
  - Review diffs carefully
  - Test in staging before production

### Medium Risk

**CFB/WNBA Data Availability**
- Risk: May not have game/stat data yet
- Impact: Medium - can't test without data
- Mitigation:
  - Implement structure first
  - Mark as "data pending" in status
  - Create tests with mock data
  - Document data requirements

**Performance of Opponent Adjustments**
- Risk: Iterative calculations are computationally expensive
- Impact: Medium - slow metric calculations
- Mitigation:
  - Add monitoring/logging
  - Consider caching intermediate results
  - Profile performance before/after
  - Optimize iteration logic if needed

### Low Risk

**Config Changes**
- Risk: Moving values to config could break existing code
- Impact: Low - values stay the same
- Mitigation:
  - Keep defaults matching current hard-coded values
  - Test thoroughly
  - Document all config values

**Trait Extraction**
- Risk: Shared code may not fit all sports perfectly
- Impact: Low - can override methods if needed
- Mitigation:
  - Make trait methods protected (can be overridden)
  - Document customization points
  - Test each sport individually

---

## Success Metrics

### Quantitative Metrics

1. **Test Coverage**
   - Target: >80% overall, >90% for Actions
   - Measure: `php artisan test --coverage`

2. **Code Reduction**
   - Target: Remove >200 duplicate lines
   - Measure: Line count before/after refactoring

3. **Maintainability**
   - Target: All classes <300 lines, methods <50 lines
   - Measure: Manual review of file sizes

4. **Consistency**
   - Target: All 7 sports use same patterns/traits
   - Measure: Code review checklist

5. **Documentation Coverage**
   - Target: 100% of public methods documented
   - Measure: PHPDoc coverage analysis

### Qualitative Metrics

6. **Code Quality**
   - Laravel Pint passes with no changes
   - No hard-coded values remain
   - Single Responsibility Principle followed

7. **Developer Experience**
   - New developers can understand system from docs
   - Adding new sports follows clear pattern
   - Tests provide good examples

8. **Production Readiness**
   - All tests passing in CI/CD
   - No breaking changes
   - Metrics validate correctly

---

## Next Steps

1. **Review with Stakeholders**
   - Present plan to team
   - Get approval on timeline
   - Confirm MLB formula changes acceptable
   - Assign tasks to developers

2. **Set Up Project Tracking**
   - Create tasks in Jira/Linear/GitHub Projects
   - Assign owners to each phase
   - Set up milestones for each week
   - Schedule daily standups

3. **Prepare Environment**
   - Create git branches for each phase
   - Set up staging environment for testing
   - Configure CI/CD for test runs
   - Prepare monitoring/logging tools

4. **Begin Implementation**
   - Start with Phase 1, Task 1.1: NFL Tests
   - Follow rollout strategy week by week
   - Review progress at end of each phase
   - Adjust timeline if needed

---

## Conclusion

This implementation plan provides a comprehensive, phased approach to addressing all identified issues in the sports metrics system. By prioritizing critical testing first, then consolidating code, and finally implementing long-term improvements, we minimize risk while delivering value incrementally.

The plan is designed to be:
- **Pragmatic**: Addresses real issues without over-engineering
- **Incremental**: Delivers value at the end of each phase
- **Well-Tested**: Ensures quality through comprehensive test coverage
- **Documented**: Leaves the codebase better than we found it

**Total Estimated Time**: 44-62 hours over 4 weeks

**Expected Outcome**: A clean, consistent, well-tested sports metrics system that's easy to maintain and extend.

---

**Document Version**: 1.0
**Last Updated**: 2026-02-09
**Status**: Ready for Review
