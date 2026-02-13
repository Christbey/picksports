# Laravel Concerns/Traits - Complete Guide

## What Problem Do Traits Solve?

### Your Current Situation: Code Duplication

You have **200+ duplicate lines** across 5 sports implementations. Every time you want to:
- Fix a bug in game filtering
- Add logging
- Change the query
- Handle a new edge case

You must update **5 identical copies** of the same code.

**Current duplication count:**
- Game filtering: ~40 lines × 5 files = 200 lines
- Stats gathering: ~30 lines × 5 files = 150 lines
- SOS calculation: ~10 lines × 5 files = 50 lines
- **Total: 400+ duplicate lines**

---

## What is a Trait?

A **trait** is PHP's mechanism for code reuse. It's like "copy-paste that PHP automatically manages for you."

**Key Concepts:**
- Traits are NOT inheritance (not `extends`)
- Traits are "included" into classes with `use`
- Multiple traits can be used in one class
- Trait methods become part of the class

**Think of it as:**
```
Traditional inheritance:    Parent → Child (vertical, single parent)
Traits:                    Trait + Trait + Trait → Class (horizontal, multiple sources)
```

---

## Laravel's "Concerns" Convention

Laravel uses `app/Concerns/` to organize traits. It's just a naming convention:

```
app/Concerns/          ← Laravel convention for domain-agnostic traits
app/Models/Concerns/   ← Traits specific to models (HasUuids, SoftDeletes, etc.)
app/Console/Commands/Concerns/  ← Traits specific to commands
```

**Why "Concerns"?** Each trait represents a "concern" (responsibility) that can be shared across classes.

---

## Real Example from Your Codebase

### BEFORE: Duplicated Code (5 files with identical logic)

**File 1: `app/Actions/NFL/CalculateTeamMetrics.php`**
```php
<?php

namespace App\Actions\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;

class CalculateTeamMetrics
{
    public function execute(Team $team, int $season): ?TeamMetric
    {
        // DUPLICATED BLOCK #1 - Game Filtering (40 lines)
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

        // DUPLICATED BLOCK #2 - Stats Gathering (30 lines)
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

        // DUPLICATED BLOCK #3 - Strength of Schedule (10 lines)
        if (empty($opponentElos)) {
            $strengthOfSchedule = null;
        } else {
            $strengthOfSchedule = round(array_sum($opponentElos) / count($opponentElos), 3);
        }

        // NFL-specific calculations (unique to NFL)
        $pointsPerGame = $this->calculateAverage($pointsScored);
        $offensiveRating = $pointsPerGame;
        // ... more NFL-specific logic
    }
}
```

**File 2: `app/Actions/NBA/CalculateTeamMetrics.php`**
```php
<?php

namespace App\Actions\NBA;

use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;

class CalculateTeamMetrics
{
    public function execute(Team $team, int $season): ?TeamMetric
    {
        // EXACT SAME 40 LINES (just different namespace)
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

        // EXACT SAME 30 LINES
        $teamStats = [];
        $opponentStats = [];
        // ... same code

        // EXACT SAME 10 LINES
        if (empty($opponentElos)) {
            $strengthOfSchedule = null;
        } else {
            $strengthOfSchedule = round(array_sum($opponentElos) / count($opponentElos), 3);
        }

        // NBA-specific calculations (unique to NBA)
        $possessions = $this->calculatePossessions($teamStats);
        $offensiveEfficiency = ($pointsScored / $possessions) * 100;
        // ... more NBA-specific logic
    }
}
```

**Files 3-5:** `CBB`, `WCBB`, `MLB` - All have the EXACT same duplicated code.

---

## AFTER: Using Traits (DRY - Don't Repeat Yourself)

### Step 1: Create the Trait

**File: `app/Concerns/CalculatesTeamMetrics.php`**
```php
<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

trait CalculatesTeamMetrics
{
    /**
     * Get completed games for a team in a specific season.
     *
     * @param Model $team The team model (NFL\Team, NBA\Team, etc.)
     * @param int $season The season year
     * @param string $sport Sport abbreviation (NFL, NBA, CBB, etc.)
     * @return Collection Collection of completed games
     */
    protected function getCompletedGamesForTeam(
        Model $team,
        int $season,
        string $sport
    ): Collection {
        // Dynamically construct the Game model class for this sport
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

    /**
     * Gather team and opponent stats from games.
     *
     * Returns an array with 'teamStats', 'opponentStats', and 'opponentElos' keys.
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
}
```

### Step 2: Use the Trait in Your Actions

**File: `app/Actions/NFL/CalculateTeamMetrics.php` (AFTER)**
```php
<?php

namespace App\Actions\NFL;

use App\Concerns\CalculatesTeamMetrics;  // ← Import the trait
use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;

class CalculateTeamMetrics
{
    use CalculatesTeamMetrics;  // ← Use the trait (now has 3 methods available)

    public function execute(Team $team, int $season): ?TeamMetric
    {
        // OLD: 40 lines of duplicated code
        // NEW: 1 line using trait method
        $games = $this->getCompletedGamesForTeam($team, $season, 'NFL');

        if ($games->isEmpty()) {
            return null;
        }

        // OLD: 30 lines of duplicated code
        // NEW: 1 line using trait method
        extract($this->gatherTeamStatsFromGames($games, $team));
        // Now you have: $teamStats, $opponentStats, $opponentElos

        if (empty($teamStats)) {
            return null;
        }

        // NFL-specific calculations (this is the unique part)
        $pointsPerGame = $this->calculateAverage($pointsScored);
        $yardsPerGame = $this->calculateAverageYards($teamStats);
        $offensiveRating = $pointsPerGame;
        $defensiveRating = $pointsAllowedPerGame;
        $netRating = $offensiveRating - $defensiveRating;

        // OLD: 10 lines of duplicated code
        // NEW: 1 line using trait method
        $strengthOfSchedule = $this->calculateStrengthOfSchedule($opponentElos);

        return TeamMetric::updateOrCreate(
            ['team_id' => $team->id, 'season' => $season],
            [
                'offensive_rating' => round($offensiveRating, 1),
                'defensive_rating' => round($defensiveRating, 1),
                'net_rating' => round($netRating, 1),
                'yards_per_game' => round($yardsPerGame, 1),
                'strength_of_schedule' => $strengthOfSchedule,
                'calculation_date' => now()->toDateString(),
            ]
        );
    }

    // NFL-specific helper methods stay here
    protected function calculateAverage(array $values): float
    {
        if (empty($values)) {
            return 0;
        }

        return array_sum($values) / count($values);
    }

    protected function calculateAverageYards(array $teamStats): float
    {
        // NFL-specific logic
    }
}
```

**File: `app/Actions/NBA/CalculateTeamMetrics.php` (AFTER)**
```php
<?php

namespace App\Actions\NBA;

use App\Concerns\CalculatesTeamMetrics;  // ← Same trait
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;

class CalculateTeamMetrics
{
    use CalculatesTeamMetrics;  // ← Same trait, different sport

    public function execute(Team $team, int $season): ?TeamMetric
    {
        // Uses the SAME trait methods, but with 'NBA' namespace
        $games = $this->getCompletedGamesForTeam($team, $season, 'NBA');

        if ($games->isEmpty()) {
            return null;
        }

        extract($this->gatherTeamStatsFromGames($games, $team));

        if (empty($teamStats)) {
            return null;
        }

        // NBA-specific calculations (different from NFL)
        $possessions = $this->calculatePossessions($teamStats);
        $offensiveEfficiency = ($pointsScored / $possessions) * 100;
        $defensiveEfficiency = ($pointsAllowed / $possessions) * 100;
        $netRating = $offensiveEfficiency - $defensiveEfficiency;

        $strengthOfSchedule = $this->calculateStrengthOfSchedule($opponentElos);

        return TeamMetric::updateOrCreate(
            ['team_id' => $team->id, 'season' => $season],
            [
                'offensive_efficiency' => round($offensiveEfficiency, 1),
                'defensive_efficiency' => round($defensiveEfficiency, 1),
                'net_rating' => round($netRating, 1),
                'tempo' => round($tempo, 1),
                'strength_of_schedule' => $strengthOfSchedule,
                'calculation_date' => now()->toDateString(),
            ]
        );
    }

    // NBA-specific helper methods
    protected function calculatePossessions(TeamStat $stat): float
    {
        // Dean Oliver's formula - NBA-specific
        return $stat->fga - $stat->orb + $stat->to + (0.44 * $stat->fta);
    }
}
```

---

## Code Reduction Summary

### Before Traits
```
NFL Action:    201 lines (70 lines duplicated)
NBA Action:    181 lines (70 lines duplicated)
CBB Action:    443 lines (70 lines duplicated)
WCBB Action:   443 lines (70 lines duplicated)
MLB Action:    224 lines (70 lines duplicated)
────────────────────────────────────────────────
Total:         1,492 lines
Duplicated:    350 lines (23% waste)
```

### After Traits
```
Trait:         120 lines (shared logic)

NFL Action:    131 lines (unique NFL logic only)
NBA Action:    111 lines (unique NBA logic only)
CBB Action:    373 lines (unique CBB logic only)
WCBB Action:   373 lines (unique WCBB logic only)
MLB Action:    154 lines (unique MLB logic only)
────────────────────────────────────────────────
Total:         1,262 lines
Reduction:     230 lines (15% reduction)
```

**Benefits:**
- ✓ Fix bugs once, not 5 times
- ✓ Add features once, not 5 times
- ✓ Easier to understand (each file shows only unique logic)
- ✓ Easier to test (test the trait separately)

---

## How Traits Work (Technical Details)

### 1. Trait Methods Become Class Methods

When you `use` a trait, PHP copies all trait methods into your class:

```php
class CalculateTeamMetrics
{
    use CalculatesTeamMetrics;  // PHP copies 3 methods into this class

    // After compilation, it's as if you wrote:
    // protected function getCompletedGamesForTeam(...) { }
    // protected function gatherTeamStatsFromGames(...) { }
    // protected function calculateStrengthOfSchedule(...) { }
}
```

### 2. Traits Can Use `$this`

Trait methods have full access to `$this`:

```php
trait CalculatesTeamMetrics
{
    protected function getCompletedGamesForTeam(...)
    {
        // Can call other methods in the class
        return $this->someOtherMethod();

        // Can access properties
        return $this->property;
    }
}
```

### 3. Multiple Traits Can Be Used

```php
class CalculateTeamMetrics
{
    use CalculatesTeamMetrics;    // Game filtering & stats
    use ValidatesMetrics;          // Validation logic
    use LogsCalculations;          // Logging logic

    // Now has methods from all 3 traits
}
```

### 4. Method Conflicts Can Be Resolved

If two traits have the same method name:

```php
class MyClass
{
    use TraitA, TraitB {
        TraitA::conflictMethod insteadof TraitB;  // Use TraitA's version
        TraitB::conflictMethod as traitBMethod;   // Rename TraitB's version
    }
}
```

### 5. Traits Can Use Other Traits

```php
trait CalculatesTeamMetrics
{
    use LogsCalculations;  // Traits can use other traits

    protected function getCompletedGamesForTeam(...)
    {
        $this->logInfo('Fetching games...');  // From LogsCalculations trait
    }
}
```

---

## Comparison: Traits vs Inheritance vs Duplication

### Option 1: Duplication (Current)
```php
// app/Actions/NFL/CalculateTeamMetrics.php
class CalculateTeamMetrics
{
    public function execute() {
        // 70 lines of duplicated code
        $games = Game::query()...
        // NFL-specific calculations
    }
}

// app/Actions/NBA/CalculateTeamMetrics.php
class CalculateTeamMetrics
{
    public function execute() {
        // SAME 70 lines duplicated
        $games = Game::query()...
        // NBA-specific calculations
    }
}
```

**Pros:** Simple, no abstraction
**Cons:** Bug fixes need 5 changes, hard to maintain

---

### Option 2: Inheritance (NOT Recommended)

```php
// app/Actions/BaseTeamMetricsCalculator.php
abstract class BaseTeamMetricsCalculator
{
    protected function getCompletedGamesForTeam() { }
    protected function gatherTeamStatsFromGames() { }

    abstract public function execute(Team $team, int $season);
}

// app/Actions/NFL/CalculateTeamMetrics.php
class CalculateTeamMetrics extends BaseTeamMetricsCalculator
{
    public function execute(Team $team, int $season) {
        // NFL-specific logic
    }
}
```

**Pros:** Shared code in one place
**Cons:**
- ❌ Forces rigid hierarchy (can only extend one class)
- ❌ NFL\Team and NBA\Team are different classes
- ❌ Hard to add other shared behaviors (would need multiple inheritance)
- ❌ Not flexible

---

### Option 3: Traits (Recommended)

```php
// app/Concerns/CalculatesTeamMetrics.php
trait CalculatesTeamMetrics
{
    protected function getCompletedGamesForTeam() { }
    protected function gatherTeamStatsFromGames() { }
}

// app/Actions/NFL/CalculateTeamMetrics.php
class CalculateTeamMetrics
{
    use CalculatesTeamMetrics;  // Flexible composition
    use LogsCalculations;        // Can use multiple traits
    use ValidatesMetrics;        // Easy to add more

    public function execute(Team $team, int $season) {
        // NFL-specific logic
    }
}
```

**Pros:**
- ✓ Shared code in one place
- ✓ No rigid hierarchy
- ✓ Can use multiple traits
- ✓ Flexible composition
- ✓ Easy to test

**Cons:**
- Methods are "hidden" (not visible in class definition)
- Can be confusing for beginners

---

## Testing Traits

Traits can be tested independently:

```php
// tests/Unit/Concerns/CalculatesTeamMetricsTest.php

use App\Concerns\CalculatesTeamMetrics;
use Tests\TestCase;

class CalculatesTeamMetricsTest extends TestCase
{
    // Create a concrete class just for testing
    private $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        // Anonymous class that uses the trait
        $this->calculator = new class {
            use CalculatesTeamMetrics;
        };
    }

    public function test_it_calculates_strength_of_schedule()
    {
        $opponentElos = [1500, 1600, 1550, 1575];

        $result = $this->calculator->calculateStrengthOfSchedule($opponentElos);

        $this->assertEquals(1556.25, $result);
    }

    public function test_it_returns_null_for_empty_opponent_elos()
    {
        $result = $this->calculator->calculateStrengthOfSchedule([]);

        $this->assertNull($result);
    }

    public function test_it_filters_games_for_team()
    {
        $team = Team::factory()->create();
        Game::factory()->count(5)->create([
            'home_team_id' => $team->id,
            'status' => 'STATUS_FINAL',
            'season' => 2025,
        ]);

        $games = $this->calculator->getCompletedGamesForTeam($team, 2025, 'NFL');

        $this->assertCount(5, $games);
    }
}
```

---

## When to Use Traits vs Services

### Use Traits When:
- ✓ Code is tightly coupled to the class (uses `$this`)
- ✓ Code represents a "behavior" of the class
- ✓ Code is relatively simple (<100 lines)
- ✓ You want to share code across unrelated classes

**Example:** `CalculatesTeamMetrics` trait
- Uses `$this` implicitly
- Provides helper methods for the class
- Simple, focused methods

### Use Services When:
- ✓ Code is complex (>100 lines)
- ✓ Code has its own dependencies
- ✓ Code represents a standalone operation
- ✓ You want to test it independently

**Example:** `OpponentAdjustmentCalculator` service
- 170+ lines of complex logic
- Has its own constructor and state
- Performs a complete standalone operation
- Easier to test as a service

---

## Alternative: Service Classes (If You Don't Like Traits)

If you're not comfortable with traits, here's an alternative using service classes:

```php
// app/Services/TeamStatsGatherer.php
class TeamStatsGatherer
{
    public function getCompletedGames(Model $team, int $season, string $sport): Collection
    {
        // Same logic as trait
    }

    public function gatherStats(Collection $games, Model $team): array
    {
        // Same logic as trait
    }
}

// app/Actions/NFL/CalculateTeamMetrics.php
class CalculateTeamMetrics
{
    public function __construct(
        private TeamStatsGatherer $gatherer
    ) {}

    public function execute(Team $team, int $season): ?TeamMetric
    {
        $games = $this->gatherer->getCompletedGames($team, $season, 'NFL');
        extract($this->gatherer->gatherStats($games, $team));
        // ...
    }
}
```

**Pros:**
- Clear dependencies (constructor injection)
- Easy to test (mock the service)
- No "magic" (methods are visible)

**Cons:**
- More verbose
- Need to inject in every class
- More classes to manage

---

## Recommended Approach for Your Codebase

I recommend **Traits** for your situation because:

1. **Simplicity**: Game filtering/stats gathering are simple helper methods
2. **Laravel Convention**: Laravel uses traits extensively (HasFactory, SoftDeletes, etc.)
3. **Less Boilerplate**: No need for dependency injection
4. **Existing Pattern**: You're already using Eloquent traits

**Split responsibilities:**
- **Traits**: Simple, reusable methods (<50 lines each)
  - `CalculatesTeamMetrics` trait for game filtering & stats
  - `DisplaysTeamMetrics` trait for command output
- **Services**: Complex operations (>100 lines)
  - `OpponentAdjustmentCalculator` service for 170-line convergence logic
  - `MetricValidator` service for validation rules

---

## Next Steps

1. **Start Small**: Create just the `CalculatesTeamMetrics` trait
2. **Convert One Sport**: Update NFL to use the trait
3. **Test**: Run NFL tests to ensure nothing broke
4. **Repeat**: Update NBA, CBB, WCBB, MLB one by one
5. **Verify**: Run all tests to confirm no regressions

Would you like me to create the first trait for you to review?
