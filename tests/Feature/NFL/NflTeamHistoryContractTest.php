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

it('uses regular season history and preserves the shared query budget', function (int $week) {
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
    expect($profiles['lineProfile'])->toEqual($baseline['profiles']['lineProfile']);
    foreach (['home', 'away'] as $side) {
        $rolling = $baseline['profiles']['rollingEfficiencyProfile'][$side];
        $opponent = $baseline['profiles']['opponentAdjustedEfficiencyProfile'][$side];
        if ($week === 1) {
            foreach ($rolling as $key => $value) {
                $rolling[$key] = $key === 'games' ? 0 : null;
            }
            foreach ($opponent as $key => $value) {
                $opponent[$key] = $key === 'games' ? 0 : null;
            }
        } else {
            $rolling['turnover_diff'] = null;
        }
        $rolling['stat_coverage_complete'] = $week === 1;
        $opponent['stat_coverage_complete'] = $week === 1;
        expect($profiles['rollingEfficiencyProfile'][$side])->toEqual($rolling)
            ->and($profiles['opponentAdjustedEfficiencyProfile'][$side])->toEqual($opponent);
        $totals = $profiles['totalEnvironmentProfile'][$side];
        expect($totals['season_type'])->toBe('regular')
            ->and($totals['games'])->toBe($week === 1 ? 1 : 3)
            ->and($totals['offense_games'])->toBe($week === 1 ? 1 : 2)
            ->and($totals['defense_games'])->toBe($week === 1 ? 1 : 2)
            ->and($totals['turnover_rate'])->toBeNull()
            ->and($totals['takeaway_rate'])->toBeNull();
    }
    expect($profiles['totalEnvironmentProfile']['home']['points_for'])->toBe($week === 1 ? 10.0 : 16.5)
        ->and($profiles['totalEnvironmentProfile']['home']['points_against'])->toBe($week === 1 ? 30.0 : 24.5)
        ->and($queries)->toBeLessThan($baseline['queries'])
        ->and($queries)->toBeLessThanOrEqual($week === 1 ? 4 : 8);
})->with([1, 4]);

it('reuses loaded team history for venue splits without changing scores or season scope', function (int $week) {
    $game = nflHistoryContractFixture($week);
    $action = app(GeneratePredictionFromHistoricalElo::class);
    (new ReflectionMethod($action, 'rollingEfficiencyProfile'))->invoke($action, $game, $game->home_team_id);
    $connection = Game::query()->getConnection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    try {
        $venue = new ReflectionMethod($action, 'venueSplitProfile');
        $home = $venue->invoke($action, $game, $game->home_team_id, true);
        $away = $venue->invoke($action, $game, $game->away_team_id, false);
        expect($home)->toBe($week === 1
            ? ['games' => 1, 'avg_margin' => -28.0, 'points_for' => 7.0, 'points_against' => 35.0]
            : ['games' => 2, 'avg_margin' => -21.5, 'points_for' => 10.0, 'points_against' => 31.5])
            ->and($away)->toBe($week === 1
                ? ['games' => 1, 'avg_margin' => 28.0, 'points_for' => 35.0, 'points_against' => 7.0]
                : ['games' => 2, 'avg_margin' => 21.5, 'points_for' => 31.5, 'points_against' => 10.0])
            ->and($connection->getQueryLog())->toBeEmpty();
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
})->with([1, 4]);

it('retains zero defaults for venue splits with no completed games', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id, 'season' => 2026, 'game_date' => '2026-09-01']);
    $venue = new ReflectionMethod(app(GeneratePredictionFromHistoricalElo::class), 'venueSplitProfile');
    expect($venue->invoke(app(GeneratePredictionFromHistoricalElo::class), $game, $game->home_team_id, true))
        ->toBe(['games' => 0, 'avg_margin' => 0.0, 'points_for' => 0.0, 'points_against' => 0.0]);
});
