<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;
use Illuminate\Support\Facades\DB;

function nflHistoryContractFixture(int $week): Game
{
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    foreach ([$home, $away] as $i => $team) {
        EloRating::query()->create(['team_id' => $team->id, 'season' => 2024, 'week' => 1, 'date' => '2024-01-01', 'elo_rating' => 1500 + 80 * $i]);
    }
    foreach ([
        [2024, '2', '2024-11-01', 10, 30, true],
        [2024, '3', '2024-12-01', 20, 17, true],
        [2025, '1', '2025-08-01', 7, 35, true],
        [2025, '2', '2025-09-07', 24, 17, true],
        [2025, 'regular', '2025-09-14', 13, 28, true],
        [2025, '2', '2025-09-21', 21, 20, false],
        [2025, '2', '2025-09-28', 99, 0, true],
    ] as $i => [$season, $type, $date, $homeScore, $awayScore, $hasStats]) {
        $h = $i % 2 === 0 ? $home : $away;
        $a = $i % 2 === 0 ? $away : $home;
        $game = Game::factory()->create(['home_team_id' => $h->id, 'away_team_id' => $a->id,
            'season' => $season, 'season_type' => $type, 'game_date' => $date,
            'status' => 'STATUS_FINAL', 'home_score' => $homeScore, 'away_score' => $awayScore]);
        if (! $hasStats) {
            continue;
        }
        foreach ([$h, $a] as $side => $team) {
            TeamStat::query()->create(['game_id' => $game->id, 'team_id' => $team->id,
                'team_type' => $side ? 'away' : 'home', 'total_yards' => 250 + $i * 17 + $side * 60,
                'passing_attempts' => $side ? 20 : 30, 'rushing_attempts' => $side ? 30 : 20,
                'rushing_yards' => 75 + $side * 20 + $i, 'sacks_allowed' => $side + 1,
                'interceptions' => $side, 'fumbles_lost' => null, 'fumbles' => 1,
                'red_zone_scores' => $side + 1, 'red_zone_attempts' => 3,
                'third_down_conversions' => 4 + $side, 'third_down_attempts' => 12,
                'penalty_yards' => 30 + 5 * $i]);
        }
    }

    return Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2025, 'season_type' => '2', 'week' => $week, 'status' => 'STATUS_SCHEDULED',
        'game_date' => $week === 1 ? '2025-09-01' : '2025-09-28']);
}

it('preserves the historical team profile contract', function (int $week) {
    config(['nfl.predictions.rolling_efficiency.recent_games' => 2,
        'nfl.predictions.total_environment.recent_games' => 2,
        'nfl.predictions.total_environment.min_games' => 2, 'nfl.predictions.line_matchup.min_games' => 2,
        'nfl.predictions.opponent_adjusted_efficiency.opponent_elo_weight' => .015, 'nfl.elo.default_rating' => 1500]);
    $game = nflHistoryContractFixture($week);
    $action = app(GeneratePredictionFromHistoricalElo::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $profiles = [];
    foreach (['rollingEfficiencyProfile', 'opponentAdjustedEfficiencyProfile', 'totalEnvironmentProfile', 'lineProfile'] as $method) {
        foreach (['home', 'away'] as $side) {
            $profiles[$method][$side] = (new ReflectionMethod($action, $method))->invoke($action, $game, $game->{$side.'_team_id'});
        }
    }
    $queries = count(DB::getQueryLog());
    // Reusing a loaded profile within this prediction must not repeat database work.
    (new ReflectionMethod($action, 'rollingEfficiencyProfile'))->invoke($action, $game, $game->home_team_id);
    expect(count(DB::getQueryLog()))->toBe($queries);
    DB::disableQueryLog();
    $baseline = json_decode(file_get_contents(base_path('tests/Fixtures/nfl/team-history-contract.json')), true, flags: JSON_THROW_ON_ERROR)[$week];
    expect(json_encode($profiles))->toBe(json_encode($baseline['profiles']))
        ->and($queries)->toBeLessThan($baseline['queries'])
        ->and($queries)->toBeLessThanOrEqual($week === 1 ? 4 : 8);
})->with([1, 4]);
