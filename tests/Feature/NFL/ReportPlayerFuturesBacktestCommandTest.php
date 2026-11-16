<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Models\Sports\FuturesOdd;
use App\Models\Sports\FuturesOddsSnapshot;
use App\Services\NFL\PlayerFuturesBacktestService;
use App\Services\NFL\PlayerFuturesProjectionService;
use Illuminate\Support\Facades\Artisan;

uses()->group('nfl', 'player-futures');

it('writes an nfl player futures backtest report', function () {
    config()->set(['nfl.season.team_count' => 2, 'nfl.team_futures.default_regular_season_games' => 3]);

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

    $opposingPlayer = Player::factory()->create(['team_id' => $opponent->id, 'position' => 'RB']);
    $games = [];
    foreach ([1 => 180, 2 => 210, 3 => 240] as $week => $yards) {
        $game = $games[$week] = Game::factory()->create([
            'season' => 2025,
            'season_type' => config('nfl.season.types.regular'),
            'week' => $week,
            'game_date' => sprintf('2025-09-%02d 19:00:00', 1 + 7 * $week),
            'status' => config('nfl.statuses.final'),
            'home_score' => 21,
            'away_score' => 14,
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
        'fetched_at' => '2025-09-08 12:00:00',
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
        'fetched_at' => '2025-09-08 12:00:00',
        'nfl_player_id' => $player->id,
    ]);

    foreach ([
        ['2025-09-08 23:59:59', 599.5, null],
        ['2025-09-09 00:00:00', 999.5, null],
        ['2025-09-15 21:00:00', 699.5, null],
        ['2025-09-15 22:00:00', 499.5, '2025-09-16 00:00:00'],
        ['2025-09-22 20:00:00', 630.0, null],
    ] as [$capturedAt, $line, $marketLastUpdate]) {
        foreach (['Over', 'Under'] as $side) {
            FuturesOddsSnapshot::create([
                'snapshot_key' => sha1("player-futures-{$capturedAt}-{$side}"),
                'row_key' => sha1("player-futures-{$side}"),
                'sport' => 'nfl',
                'season' => 2025,
                'odds_api_sport_key' => 'americanfootball_nfl',
                'event_id' => 'season-2025',
                'bookmaker' => 'draftkings',
                'market_key' => 'player_pass_yds',
                'outcome_name' => $side,
                'outcome_point' => $line,
                'price' => -110,
                'implied_probability' => 0.5238,
                'captured_at' => $capturedAt,
                'market_last_update' => $marketLastUpdate,
                'nfl_player_id' => $player->id,
            ]);
        }
    }

    foreach ([1, 2] as $week) {
        PlayerStat::create([
            'player_id' => $opposingPlayer->id,
            'game_id' => $games[$week]->id,
            'team_id' => $opponent->id,
        ]);
    }

    $backtest = app(PlayerFuturesBacktestService::class);
    $pending = $backtest->evaluate(2025, 'passing_yards', toWeek: 3);
    expect($pending['season_settled'])->toBeFalse()
        ->and($pending['summary']['pending_count'])->toBe(3)
        ->and($pending['summary']['count'])->toBe(0)
        ->and($pending['summary']['line_count'])->toBe(0)
        ->and($pending['summary']['over_brier'])->toBeNull();

    PlayerStat::create([
        'player_id' => $opposingPlayer->id,
        'game_id' => $games[3]->id,
        'team_id' => $opponent->id,
    ]);

    Artisan::call('nfl:report-player-futures-backtest', [
        '--season' => 2025,
        '--market' => 'passing_yards',
        '--from-week' => 1,
        '--to-week' => 3,
        '--output' => $outputPath,
    ]);

    $report = json_decode(file_get_contents($outputPath), true);
    $projections = app(PlayerFuturesProjectionService::class);
    [$weekOne] = $projections->projections(2025, 'passing_yards', asOfWeek: 1);
    [$weekTwo] = $projections->projections(2025, 'passing_yards', asOfWeek: 2);

    expect($weekOne['market_odds']['line'])->toBe(599.5)
        ->and($weekTwo['market_odds']['line'])->toBe(699.5)
        ->and($report)->toBeArray()
        ->and($report['report_type'])->toBe('nfl_player_futures_backtest')
        ->and($report['season'])->toBe(2025)
        ->and($report['season_settled'])->toBeTrue()
        ->and($report['summary']['pending_count'])->toBe(0)
        ->and($report['summary']['count'])->toBe(3)
        ->and($report['summary']['line_count'])->toBe(2)
        ->and($report['weeks'])->toHaveCount(3)
        ->and($report['weeks'][0]['summary']['over_brier'])->toEqualWithDelta((1 - $weekOne['over_probability']) ** 2, 0.0001)
        ->and($report['weeks'][1]['summary']['over_brier'])->toEqualWithDelta($weekTwo['over_probability'] ** 2, 0.0001)
        ->and($report['weeks'][2]['summary']['count'])->toBe(1)
        ->and($report['weeks'][2]['summary']['line_count'])->toBe(0);

    FuturesOddsSnapshot::query()->delete();
    $withoutArchive = app(PlayerFuturesBacktestService::class)->evaluate(2025, 'passing_yards', toWeek: 3);
    expect($withoutArchive['summary']['count'])->toBe(3)
        ->and($withoutArchive['summary']['line_count'])->toBe(0)
        ->and($withoutArchive['summary']['over_brier'])->toBeNull();
});
