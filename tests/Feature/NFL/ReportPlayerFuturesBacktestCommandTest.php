<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Models\Sports\FuturesOdd;
use Illuminate\Support\Facades\Artisan;

uses()->group('nfl', 'player-futures');

it('writes an nfl player futures backtest report', function () {
    $outputPath = storage_path('app/ml/reports/test_nfl_player_futures_backtest.json');
    @mkdir(dirname($outputPath), 0777, true);
    @unlink($outputPath);

    $team = Team::factory()->create();
    $opponent = Team::factory()->create();

    $player = Player::factory()->create([
        'team_id' => $team->id,
        'position' => 'QB',
        'full_name' => 'Backtest QB',
    ]);

    foreach ([1 => 180, 2 => 210, 3 => 240] as $week => $yards) {
        $game = Game::factory()->create([
            'season' => 2025,
            'season_type' => config('nfl.season.types.regular'),
            'week' => $week,
            'status' => config('nfl.statuses.final'),
            'home_team_id' => $team->id,
            'away_team_id' => $opponent->id,
        ]);

        PlayerStat::create([
            'player_id' => $player->id,
            'game_id' => $game->id,
            'team_id' => $team->id,
            'passing_yards' => $yards,
            'passing_touchdowns' => 2,
        ]);
    }

    FuturesOdd::create([
        'row_key' => sha1('bt-over'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'americanfootball_nfl',
        'event_id' => 'season-2025',
        'event_name' => 'NFL 2025 Season',
        'bookmaker' => 'draftkings',
        'market_key' => 'player_pass_yds',
        'outcome_name' => 'Over',
        'outcome_description' => $player->full_name,
        'outcome_point' => 599.5,
        'price' => -110,
        'implied_probability' => 0.5238,
        'fetched_at' => now(),
        'nfl_player_id' => $player->id,
    ]);

    FuturesOdd::create([
        'row_key' => sha1('bt-under'),
        'sport' => 'nfl',
        'season' => 2025,
        'odds_api_sport_key' => 'americanfootball_nfl',
        'event_id' => 'season-2025',
        'event_name' => 'NFL 2025 Season',
        'bookmaker' => 'draftkings',
        'market_key' => 'player_pass_yds',
        'outcome_name' => 'Under',
        'outcome_description' => $player->full_name,
        'outcome_point' => 599.5,
        'price' => -110,
        'implied_probability' => 0.5238,
        'fetched_at' => now(),
        'nfl_player_id' => $player->id,
    ]);

    Artisan::call('nfl:report-player-futures-backtest', [
        '--season' => 2025,
        '--market' => 'passing_yards',
        '--from-week' => 1,
        '--to-week' => 2,
        '--output' => $outputPath,
    ]);

    $report = json_decode(file_get_contents($outputPath), true);

    expect($report)->toBeArray()
        ->and($report['report_type'])->toBe('nfl_player_futures_backtest')
        ->and($report['season'])->toBe(2025)
        ->and($report['summary']['count'])->toBe(2)
        ->and($report['weeks'])->toHaveCount(2)
        ->and($report['weeks'][0]['summary']['count'])->toBe(1);
});
