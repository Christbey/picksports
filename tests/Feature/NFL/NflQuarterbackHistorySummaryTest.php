<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\DB;

it('preserves quarterback source filters and counts career transfers without using future games', function () {
    [$current, $previous, $opponent] = Team::factory()->count(3)->create()->all();
    $player = Player::factory()->create(['team_id' => $current->id]);
    $otherPlayer = Player::factory()->create(['team_id' => $current->id]);
    $target = Game::factory()->create([
        'home_team_id' => $current->id,
        'away_team_id' => $opponent->id,
        'season' => 2026,
        'season_type' => '2',
        'game_date' => '2026-09-20',
        'status' => 'STATUS_SCHEDULED',
    ]);

    foreach ([
        [2025, '2025-12-01', '2', 'STATUS_FINAL', 30, $previous->id, $player->id],
        [2026, '2026-09-10', 'regular', 'STATUS_FINAL', 20, $current->id, $player->id],
        [2026, '2026-08-20', '1', 'STATUS_FINAL', 99, $current->id, $player->id],
        [2026, '2026-09-20', '2', 'STATUS_FINAL', 99, $current->id, $player->id],
        [2026, '2026-09-27', '2', 'STATUS_FINAL', 99, $current->id, $player->id],
        [2026, '2026-09-11', '2', 'STATUS_IN_PROGRESS', 99, $current->id, $player->id],
        [2026, '2026-09-12', '2', 'STATUS_FINAL', 0, $current->id, $player->id],
        [2026, '2026-09-13', '2', 'STATUS_FINAL', 99, $current->id, $otherPlayer->id],
    ] as [$season, $date, $type, $status, $attempts, $teamId, $playerId]) {
        $game = Game::factory()->create([
            'home_team_id' => $teamId,
            'away_team_id' => $opponent->id,
            'season' => $season,
            'season_type' => $type,
            'game_date' => $date,
            'status' => $status,
        ]);
        $stats = [
            'team_id' => $teamId,
            'passing_attempts' => $attempts,
            'passing_yards' => 200,
            'passing_touchdowns' => 1,
            'interceptions_thrown' => null,
            'rushing_yards' => -3,
        ];

        PlayerStat::create(['player_id' => $playerId, 'game_id' => $game->id, 'sacks_taken' => 2, ...$stats]);
        DB::table('nflverse_weekly_player_stats')->insert([
            'nflverse_weekly_stat_key' => hash('sha256', (string) $game->id),
            'nfl_game_id' => $game->id,
            'player_id' => 'QB-'.$playerId,
            ...$stats,
        ]);
    }

    $action = app(GeneratePredictionFromHistoricalElo::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $local = (new ReflectionMethod($action, 'priorQbStats'))->invoke($action, $player->id, $current->id, $target);
    $nflverse = (new ReflectionMethod($action, 'priorNflverseQbStats'))->invoke($action, 'QB-'.$player->id, $target);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(2);
    expect($local)->toBe([
        'games' => 2,
        'current_team_games' => 1,
        'attempts' => 50,
        'yards' => 400,
        'touchdowns' => 2,
        'interceptions' => 0,
        'sacks' => 4,
        'rush_yards' => -6,
        'yards_per_attempt' => 400 / 50,
        'td_rate' => 2 / 50,
        'int_rate' => 0 / 50,
        'sack_rate' => 4 / 54,
        'rush_yards_per_game' => -6 / 2,
    ]);
    unset($local['current_team_games']);
    $local['sacks'] = 0;
    $local['sack_rate'] = 0 / 50;
    expect($nflverse)->toBe($local);
});
