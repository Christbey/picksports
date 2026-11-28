<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Services\NFL\PlayerFuturesBacktestService;
use App\Services\NFL\PlayerFuturesProjectionService;
use App\Services\NFL\RegularSeasonFuturesResults;

function futuresScheduleProjection(int $season, int $observedGames, string $finalStatus = 'STATUS_FINAL'): array
{
    $team = Team::factory()->create();
    $oldTeam = $observedGames === 0 ? Team::factory()->create() : $team;
    $opponent = Team::factory()->create();
    $player = Player::factory()->create(['team_id' => $team->id, 'position' => 'QB']);
    for ($week = 1; $week <= max(1, $observedGames); $week++) {
        $game = Game::factory()->create(['home_team_id' => $oldTeam->id, 'away_team_id' => $opponent->id,
            'season' => $season, 'season_type' => 2, 'week' => $week,
            'game_date' => $season.'-09-01', 'game_time' => '18:00:00',
            'status' => $week === 1 ? $finalStatus : 'STATUS_SCHEDULED']);
        if ($week === 1) {
            PlayerStat::create(['player_id' => $player->id, 'game_id' => $game->id,
                'team_id' => $oldTeam->id, 'passing_yards' => 250, 'passing_touchdowns' => 2]);
        }
    }

    return app(PlayerFuturesProjectionService::class)->projections($season, 'passing_yards', $player->id, false);
}

it('uses the internal season games rule rather than week count when current-team schedule is missing', function (int $season) {
    $row = futuresScheduleProjection($season, 0)[0];
    $expected = RegularSeasonFuturesResults::gamesPerTeam($season);
    expect($row['team_games_scheduled'])->toBe($expected)
        ->and($row['remaining_games'])->toBe($expected - 1)
        ->and(data_get($row, 'schedule_coverage.status'))->toBe('missing_inventory')
        ->and(data_get($row, 'schedule_coverage.observed_games'))->toBe(0)
        ->and(data_get($row, 'schedule_coverage.fallback_used'))->toBeTrue()
        ->and(data_get($row, 'schedule_coverage.completed_games_source'))->toBe('player_logs_fallback');
})->with([2020, 2026]);

it('labels a partial stored schedule and uses an explicit inferred season-length fallback', function () {
    $row = futuresScheduleProjection(2026, 3)[0];
    expect($row['team_games_scheduled'])->toBe(17)->and($row['remaining_games'])->toBe(16)
        ->and(data_get($row, 'schedule_coverage.observed_games'))->toBe(3)
        ->and(data_get($row, 'schedule_coverage.status'))->toBe('incomplete_inventory')
        ->and(data_get($row, 'schedule_coverage.fallback_used'))->toBeTrue();
});

it('preserves expected-count stored inventory without claiming an inferred schedule', function () {
    $row = futuresScheduleProjection(2026, 17)[0];
    expect($row['team_games_scheduled'])->toBe(17)->and($row['remaining_games'])->toBe(16)
        ->and(data_get($row, 'schedule_coverage.status'))->toBe('expected_count_present')
        ->and(data_get($row, 'schedule_coverage.fallback_used'))->toBeFalse();
});

it('retains unexpected extra stored games but exposes the inconsistency', function () {
    $row = futuresScheduleProjection(2026, 18)[0];
    expect($row['team_games_scheduled'])->toBe(18)
        ->and(data_get($row, 'schedule_coverage.expected_games'))->toBe(17)
        ->and(data_get($row, 'schedule_coverage.status'))->toBe('unexpected_inventory');
});

it('counts shared full-time finals consistently in player logs and completed schedule', function () {
    $rows = futuresScheduleProjection(2026, 17, 'STATUS_FULL_TIME');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['current_total'])->toBe(250.0)
        ->and($rows[0]['team_games_played'])->toBe(1)
        ->and($rows[0]['remaining_games'])->toBe(16);
});

it('reports inferred inventory in manual backtest summary and console output', function () {
    futuresScheduleProjection(2026, 3);
    $report = app(PlayerFuturesBacktestService::class)->evaluate(2026, 'passing_yards', 1, 1, 1);
    expect($report['summary']['inferred_schedule_rows'])->toBe(1)
        ->and($report['summary']['unexpected_schedule_rows'])->toBe(0)
        ->and($report['summary']['unknown_schedule_rows'])->toBe(0);
    $this->artisan('nfl:report-player-futures-backtest', ['--season' => 2026, '--market' => 'passing_yards', '--to-week' => 1])
        ->expectsOutputToContain('1 inferred')->assertSuccessful();
});
