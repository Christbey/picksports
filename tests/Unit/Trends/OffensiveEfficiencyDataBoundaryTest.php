<?php

use App\Actions\Trends\Collectors\OffensiveEfficiencyTrendCollector;
use Illuminate\Database\Eloquent\Collection as ModelCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function loadedOffensiveTrend(string $league, array $rows): array
{
    $namespace = match ($league) {
        'nba' => 'NBA', 'wnba' => 'WNBA', 'cbb' => 'CBB', 'wcbb' => 'WCBB', 'mlb' => 'MLB', 'nfl' => 'NFL'
    };
    $teamClass = 'App\\Models\\'.$namespace.'\\Team';
    $gameClass = 'App\\Models\\'.$namespace.'\\Game';
    $statClass = 'App\\Models\\'.$namespace.'\\TeamStat';
    $team = (new $teamClass)->forceFill(['id' => 7, 'abbreviation' => 'TST']);
    $games = collect($rows)->map(function (array $row, int $index) use ($gameClass, $statClass, $league) {
        $score = $league === 'mlb' ? (($row['runs'] ?? null) === 0 ? 0 : 5) : ($league === 'nfl' ? 27 : (($row['points'] ?? null) === 0 ? 0 : 100));
        $game = (new $gameClass)->forceFill(['id' => $index + 1, 'home_team_id' => 7, 'away_team_id' => 9,
            'home_score' => $score, 'away_score' => $league === 'mlb' ? 3 : 90, 'status' => 'STATUS_FINAL',
            'game_date' => '2026-10-20', 'game_time' => '18:00:00']);
        $stats = (new $statClass)->forceFill(['id' => $index + 1, 'game_id' => $game->id, 'team_id' => 7, ...$row]);
        $game->setRelation('teamStats', new ModelCollection([$stats]));

        return $game;
    });
    Http::preventStrayRequests();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $result = (new OffensiveEfficiencyTrendCollector)->setContext($league, $team, $games)->collect();
    expect($queries)->toBe([]);
    Http::assertNothingSent();

    return $result;
}

it('does not turn missing basketball points into a zero offensive rating', function (string $league) {
    expect(loadedOffensiveTrend($league, [['points' => null, 'possessions' => 100]]))->toBe([]);
})->with(['nba', 'wnba', 'cbb', 'wcbb']);

it('uses only recorded basketball point possession pairs in the observed rating mean', function () {
    expect(loadedOffensiveTrend('nba', [['points' => 100, 'possessions' => 100], ['points' => null, 'possessions' => 100]]))
        ->toContain('The TST average 100.0 offensive rating (points per 100 possessions)');
});

it('counts actual zero basketball turnovers as recorded low turnovers', function () {
    $messages = loadedOffensiveTrend('nba', array_fill(0, 3, ['turnovers' => 0]));
    expect(collect($messages)->contains(fn ($line) => str_contains($line, '12 or fewer turnovers in 3 of')))->toBeTrue();
});

it('uses recorded basketball turnover games as the significance denominator', function () {
    $messages = loadedOffensiveTrend('nba', [['turnovers' => 10], ['turnovers' => 10], ['turnovers' => null], ['turnovers' => null], ['turnovers' => null]]);
    expect(collect($messages)->contains(fn ($line) => str_contains($line, '12 or fewer turnovers in 2 of') && str_contains($line, '2 games')))->toBeTrue();
});

it('does not replace missing assists with zero in the observed assist turnover ratio', function () {
    $messages = loadedOffensiveTrend('nba', [['assists' => 20, 'turnovers' => 10], ['assists' => null, 'turnovers' => 10]]);
    expect($messages)->toContain('The TST are taking care of the ball with a 2.00 assist-to-turnover ratio');
});

it('does not replace missing baseball run or hit observations with zero', function () {
    expect(loadedOffensiveTrend('mlb', [['runs' => 6, 'hits' => 10], ['runs' => null, 'hits' => null]]))
        ->toContain('The TST average 6.0 runs per game', 'The TST average 10.0 hits per game');
});

it('admits baseball run and hit means independently for each recorded field', function () {
    expect(loadedOffensiveTrend('mlb', [['runs' => null, 'hits' => 10], ['runs' => 6, 'hits' => null]]))
        ->toContain('The TST average 6.0 runs per game', 'The TST average 10.0 hits per game');
});

it('preserves recorded zero basketball points as an actual zero rating', function () {
    expect(loadedOffensiveTrend('nba', [['points' => 0, 'possessions' => 100]]))
        ->toContain('The TST average 0.0 offensive rating (points per 100 possessions)');
});

it('retains real zero assists and omits undefined ratios with zero turnovers', function () {
    $messages = loadedOffensiveTrend('nba', [['assists' => 20, 'turnovers' => 10], ['assists' => 0, 'turnovers' => 10], ['assists' => 20, 'turnovers' => 0]]);
    expect(collect($messages)->contains(fn ($line) => str_contains($line, 'assist-to-turnover ratio')))->toBeFalse();
});

it('retains real zero baseball observations in the same mean formula', function () {
    expect(loadedOffensiveTrend('mlb', [['runs' => 6, 'hits' => 10], ['runs' => 0, 'hits' => 0]]))
        ->toBe(['The TST average 3.0 runs per game']);
});

it('preserves complete basketball values and existing rating and ratio formulas', function () {
    $messages = loadedOffensiveTrend('nba', [['points' => 100, 'possessions' => 100, 'assists' => 20, 'turnovers' => 10]]);
    expect($messages)->toContain('The TST average 100.0 offensive rating (points per 100 possessions)',
        'The TST are taking care of the ball with a 2.00 assist-to-turnover ratio');
});

it('omits an entirely unrecorded boxscore and preserves complete baseball means', function () {
    expect(loadedOffensiveTrend('nba', [['points' => null, 'possessions' => null, 'assists' => null, 'turnovers' => null]]))->toBe([])
        ->and(loadedOffensiveTrend('mlb', [['runs' => null, 'hits' => null]]))->toBe([])
        ->and(loadedOffensiveTrend('mlb', [['runs' => 6, 'hits' => 10]]))->toBe(['The TST average 6.0 runs per game', 'The TST average 10.0 hits per game']);
});

it('omits undefined basketball ratings when possessions are missing or recorded zero', function ($possessions) {
    expect(loadedOffensiveTrend('nba', [['points' => 100, 'possessions' => $possessions]]))->toBe([]);
})->with(['missing' => null, 'recorded_zero' => 0]);

it('does not render nonfinite or overflowing in memory NBA ratings', function (array $rows) {
    // These are actual model cast values, not claims that native integer/float
    // database columns accept this damaged data.
    expect(loadedOffensiveTrend('nba', $rows))->toBe([]);
})->with([
    'infinite_points' => [[['points' => INF, 'possessions' => 100]]],
    'finite_division_overflow' => [[['points' => 1.0e308, 'possessions' => 1.0e-308]]],
    'finite_mean_overflow' => [[['points' => 1.0e308, 'possessions' => 100], ['points' => 1.0e308, 'possessions' => 100]]],
]);

it('does not render nonfinite or overflowing in memory assist ratios', function (float $assists, float $turnovers) {
    expect(collect(loadedOffensiveTrend('nba', [['assists' => $assists, 'turnovers' => $turnovers]]))
        ->contains(fn ($line) => str_contains($line, 'assist-to-turnover ratio')))->toBeFalse();
})->with(['infinite_assists' => [INF, 10.0], 'finite_ratio_overflow' => [1.0e308, 1.0e-308]]);

it('does not treat negative recorded count metrics as valid observations', function (string $league, array $row) {
    expect(loadedOffensiveTrend($league, [$row]))->toBe([]);
})->with([
    'negative_points' => ['nba', ['points' => -1, 'possessions' => 100]],
    'negative_possessions' => ['nba', ['points' => 100, 'possessions' => -1]],
    'negative_turnovers' => ['nba', ['turnovers' => -1]],
    'negative_assists' => ['nba', ['assists' => -1, 'turnovers' => 20]],
    'negative_baseball_counts' => ['mlb', ['runs' => -1, 'hits' => -1]],
]);

it('excludes negative baseball counts from each observed mean', function () {
    expect(loadedOffensiveTrend('mlb', [['runs' => 6, 'hits' => 10], ['runs' => -2, 'hits' => -2]]))
        ->toBe(['The TST average 6.0 runs per game', 'The TST average 10.0 hits per game']);
});

it('excludes negative assists from the observed ratio denominator', function () {
    expect(implode(' ', loadedOffensiveTrend('nba', [['assists' => 20, 'turnovers' => 10], ['assists' => -1, 'turnovers' => 20]])))
        ->toContain('2.00 assist-to-turnover ratio');
});
