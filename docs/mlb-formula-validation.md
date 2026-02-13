# MLB Team Metrics Formula Validation

**Date:** 2026-02-09
**Task:** Validate MLB metric calculation formulas against baseball analytics standards

## Executive Summary

The current MLB team metrics calculations use custom formulas that deviate from industry-standard sabermetric approaches. While the formulas are functional, they have several issues that limit their accuracy and comparability to established baseball analytics.

## Current Implementation Analysis

### 1. Offensive Rating

**Current Formula** (`app/Actions/MLB/CalculateTeamMetrics.php:116`):
```php
return ($runsPerGame * 20) + ($battingAvg * 100) + ($homeRunRate * 10);
```

**Components:**
- Runs per game (weighted 20x)
- Batting average (weighted 100x)
- Home runs per game (weighted 10x)

**Issues:**
1. **Batting average is outdated** - Doesn't value walks or distinguish between types of hits
2. **Arbitrary weights** - The multipliers (20, 100, 10) have no statistical justification
3. **Missing key components**:
   - On-base percentage (OBP) - critical for measuring offensive value
   - Slugging percentage (SLG) - distinguishes extra-base hits
   - Doubles and triples - available in data but unused
4. **Not comparable** - Can't be benchmarked against league averages or other teams using standard metrics

**Recommended Improvements:**

**Option A: OPS (Simple, Industry Standard)**
```php
protected function calculateOffensiveRating(array $teamStats): float
{
    $totalHits = 0;
    $totalAtBats = 0;
    $totalWalks = 0;
    $totalSingles = 0;
    $totalDoubles = 0;
    $totalTriples = 0;
    $totalHomeRuns = 0;

    foreach ($teamStats as $stat) {
        $hits = $stat->hits ?? 0;
        $doubles = $stat->doubles ?? 0;
        $triples = $stat->triples ?? 0;
        $homeRuns = $stat->home_runs ?? 0;

        $totalHits += $hits;
        $totalAtBats += $stat->at_bats ?? 0;
        $totalWalks += $stat->walks ?? 0;
        $totalDoubles += $doubles;
        $totalTriples += $triples;
        $totalHomeRuns += $homeRuns;
        $totalSingles += ($hits - $doubles - $triples - $homeRuns);
    }

    if ($totalAtBats == 0) {
        return 0;
    }

    // Calculate OBP: (H + BB) / (AB + BB)
    $obp = ($totalHits + $totalWalks) / ($totalAtBats + $totalWalks);

    // Calculate SLG: Total Bases / AB
    $totalBases = $totalSingles + ($totalDoubles * 2) + ($totalTriples * 3) + ($totalHomeRuns * 4);
    $slg = $totalBases / $totalAtBats;

    // OPS = OBP + SLG (typically ranges from 0.600 to 0.900)
    // Scale to ~100 for consistency with other ratings
    return ($obp + $slg) * 100;
}
```

**Rationale:**
- OPS is the industry standard for measuring offensive production
- Simple calculation using available data
- Comparable across teams and seasons
- Properly values walks and extra-base hits
- Typical OPS ranges: .600 (poor) to .900+ (excellent)

### 2. Pitching Rating

**Current Formula** (`app/Actions/MLB/CalculateTeamMetrics.php:145-147`):
```php
$eraComponent = max(0, 100 - ($era * 10));
return $eraComponent + $strikeoutsPerGame - $walksPerGame;
```

**Components:**
- ERA inverted and scaled: `max(0, 100 - (ERA × 10))`
- Strikeouts per game (additive)
- Walks per game (subtractive)

**Issues:**
1. **ERA is defense-dependent** - Affected by fielding quality and luck (BABIP)
2. **Arbitrary transformation** - The `(100 - ERA × 10)` formula has no statistical basis
3. **Ignores home runs** - Critical component available in data (`home_runs_allowed`)
4. **Not fielding-independent** - Can't isolate pitching quality from team defense
5. **Weights are arbitrary** - K's and walks weighted equally, which isn't accurate

**Recommended Improvements:**

**Option B: FIP (Fielding Independent Pitching - Standard)**
```php
protected function calculatePitchingRating(array $teamStats): float
{
    $totalHomeRunsAllowed = 0;
    $totalWalksAllowed = 0;
    $totalStrikeouts = 0;
    $totalInningsPitched = 0;

    foreach ($teamStats as $stat) {
        $totalHomeRunsAllowed += $stat->home_runs_allowed ?? 0;
        $totalWalksAllowed += $stat->walks_allowed ?? 0;
        $totalStrikeouts += $stat->strikeouts_pitched ?? 0;
        $totalInningsPitched += $stat->innings_pitched ?? 0;
    }

    if ($totalInningsPitched == 0) {
        return 0;
    }

    // FIP = ((13 × HR) + (3 × BB) - (2 × K)) / IP + constant
    // Constant typically ~3.10 to align with league average ERA
    $fipConstant = 3.10;

    $fip = (
        (13 * $totalHomeRunsAllowed) +
        (3 * $totalWalksAllowed) -
        (2 * $totalStrikeouts)
    ) / $totalInningsPitched + $fipConstant;

    // Invert so lower FIP = higher rating
    // Scale to ~100 (league average FIP ~4.00 becomes 100)
    // Good FIP: 3.00-3.50, Poor FIP: 5.00+
    return max(0, (4.00 / $fip) * 100);
}
```

**Rationale:**
- FIP is the industry standard for pitching evaluation
- Isolates pitcher performance from defense
- Uses only outcomes pitchers control: K's, BB's, HR's
- Weights are empirically derived from run values
- Comparable across teams, parks, and eras

**Sources:**
- [ERA- / FIP- / xFIP-](https://library.fangraphs.com/pitching/era-fip-xfip/)
- [FIP Sabermetrics Library](https://library.fangraphs.com/pitching/fip/)
- [Expected Fielding Independent Pitching (xFIP)](https://www.mlb.com/glossary/advanced-stats/expected-fielding-independent-pitching)

### 3. Defensive Rating

**Current Formula** (`app/Actions/MLB/CalculateTeamMetrics.php:173-177`):
```php
$fieldingPct = ($totalPutouts + $totalAssists - $totalErrors) /
               ($totalPutouts + $totalAssists + $totalErrors);

return ($fieldingPct * 100) + $putoutsPerGame + $assistsPerGame - ($errorsPerGame * 10);
```

**Components:**
- Fielding percentage (weighted 100x)
- Putouts per game (additive)
- Assists per game (additive)
- Errors per game (weighted -10x)

**Issues:**
1. **Fielding percentage is outdated** - Doesn't measure defensive range (can't catch what you don't reach)
2. **Position-dependent metrics** - Putouts/assists vary by position (first basemen get more putouts than outfielders)
3. **Team-level conflation** - Mixes team defense with individual performance
4. **Advanced metrics unavailable** - UZR and DRS require tracking data not in the dataset

**Assessment:**
- Given data limitations, the current formula is **acceptable**
- Advanced defensive metrics (UZR, DRS) require play-by-play tracking data
- Fielding percentage and error rate are reasonable proxies at the team level
- The weights are arbitrary but the approach is defensible

**Minor Improvements:**
```php
protected function calculateDefensiveRating(array $teamStats): float
{
    $totalErrors = 0;
    $totalPutouts = 0;
    $totalAssists = 0;
    $totalChances = 0;
    $gameCount = count($teamStats);

    foreach ($teamStats as $stat) {
        $errors = $stat->errors ?? 0;
        $putouts = $stat->putouts ?? 0;
        $assists = $stat->assists ?? 0;

        $totalErrors += $errors;
        $totalPutouts += $putouts;
        $totalAssists += $assists;
        $totalChances += ($putouts + $assists + $errors);
    }

    if ($gameCount == 0 || $totalChances == 0) {
        return 0;
    }

    // Fielding percentage
    $fieldingPct = ($totalPutouts + $totalAssists) / $totalChances;

    // Errors per game (lower is better)
    $errorsPerGame = $totalErrors / $gameCount;

    // Scale to ~100 (excellent fielding ~.985 becomes ~98.5)
    // Deduct points for errors (typical: 0.5-1.5 errors/game)
    return ($fieldingPct * 100) - ($errorsPerGame * 2);
}
```

**Rationale:**
- Simplified formula with clearer logic
- Fielding percentage as primary component (ranges .970-.985 for teams)
- Error rate as penalty (scaled reasonably)
- Removed putouts/assists per game (position-dependent and redundant with fielding %)

## Data Availability

**Current TeamStat Fields:**
```php
// Batting
'runs', 'hits', 'at_bats', 'doubles', 'triples', 'home_runs', 'walks', 'strikeouts'

// Pitching
'innings_pitched', 'earned_runs', 'walks_allowed', 'strikeouts_pitched', 'home_runs_allowed'

// Fielding
'errors', 'putouts', 'assists'
```

**Missing for Full wOBA/wRC+:**
- Hit-by-pitch (HBP)
- Sacrifice flies (SF)
- Caught stealing (CS)
- Intentional walks (IBB)

**Missing for Advanced Defense:**
- Play-by-play tracking data
- Positional adjustments
- Park factors

## Recommendations

### Priority 1: Immediate Improvements (High Impact, Low Effort)

1. **Replace Offensive Rating with OPS**
   - File: `app/Actions/MLB/CalculateTeamMetrics.php:89-117`
   - Uses existing data
   - Industry standard metric
   - Comparable across teams/seasons

2. **Replace Pitching Rating with FIP**
   - File: `app/Actions/MLB/CalculateTeamMetrics.php:119-148`
   - Uses existing data (adds home_runs_allowed)
   - Fielding-independent
   - Predictive of future performance

3. **Simplify Defensive Rating**
   - File: `app/Actions/MLB/CalculateTeamMetrics.php:150-178`
   - Focus on fielding percentage and error rate
   - Remove redundant components

### Priority 2: Database Schema Updates (For Future Enhancement)

Consider adding to `mlb_team_stats` table:
- `hit_by_pitch` - For better OBP calculation
- `sacrifice_flies` - For accurate OBP
- `fly_balls` - For xFIP calculation
- `ground_balls` - For ground ball rate metrics

### Priority 3: Configuration (For Maintainability)

Create `config/mlb.php`:
```php
return [
    'metrics' => [
        'fip_constant' => 3.10, // Adjust annually based on league ERA
        'league_avg_ops' => 0.720, // Update each season
        'league_avg_fip' => 4.00, // Update each season
    ],
];
```

## Impact Assessment

### Breaking Changes
- **Offensive/Pitching/Defensive rating values will change significantly**
- Existing predictions and historical comparisons will be invalid
- Requires data migration or new columns for backward compatibility

### Mitigation Strategy
Add new columns to preserve historical data:
```php
// Migration: add new columns
'ops_rating' => null,           // New OPS-based rating
'fip_rating' => null,           // New FIP-based rating
'legacy_offensive_rating' => null, // Old formula (deprecated)
'legacy_pitching_rating' => null,  // Old formula (deprecated)
```

## Testing Requirements

After implementing changes:

1. **Update test expectations** in `tests/Feature/MLB/CalculateTeamMetricsTest.php`
2. **Add formula validation tests**:
   - OPS calculation matches known values
   - FIP calculation matches FanGraphs/Baseball Reference
   - Values are in expected ranges
3. **Add regression tests** using real team data
4. **Benchmark against FanGraphs** for 2025 season data

## Conclusion

The current MLB formulas use custom approaches that deviate from baseball analytics standards. Implementing OPS and FIP would:

1. **Improve accuracy** - Better reflect team performance
2. **Enable comparisons** - Benchmark against industry data
3. **Increase credibility** - Use recognized sabermetric standards
4. **Simplify maintenance** - Well-documented industry formulas

**Recommended Action:** Implement Priority 1 improvements (OPS and FIP) before proceeding with Phase 2 of the refactoring plan.
