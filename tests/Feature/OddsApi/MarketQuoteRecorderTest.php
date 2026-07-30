<?php

use App\Models\MarketQuote;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Services\OddsApi\GameOddsSnapshotRecorder;
use Illuminate\Support\Carbon;

it('normalizes every bookmaker outcome into point in time market quotes', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();
    $game = Game::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => '2026-07-26 19:00:00',
    ]);
    $odds = [
        'home_team' => 'Chicago Bulls',
        'away_team' => 'Boston Celtics',
        'bookmakers' => [[
            'key' => 'draftkings',
            'title' => 'DraftKings',
            'markets' => [[
                'key' => 'spreads',
                'outcomes' => [
                    ['name' => 'Chicago Bulls', 'price' => -110, 'point' => -3.5],
                    ['name' => 'Boston Celtics', 'price' => -110, 'point' => 3.5],
                ],
            ]],
        ]],
    ];

    $snapshot = app(GameOddsSnapshotRecorder::class)->record(
        'nba',
        $game,
        ['id' => 'event-1', 'commence_time' => '2026-07-26T19:00:00Z'],
        $odds,
        Carbon::parse('2026-07-26T18:00:00Z'),
    );

    $quotes = MarketQuote::query()->orderBy('side')->get()->keyBy('side');

    expect($snapshot)->not->toBeNull()
        ->and($quotes)->toHaveCount(2)
        ->and((float) $quotes['home']->bookmaker_home_line)->toBe(-3.5)
        ->and((float) $quotes['away']->bookmaker_home_line)->toBe(-3.5)
        ->and((float) $quotes['home']->home_margin_equivalent)->toBe(3.5)
        ->and((float) $quotes['home']->no_vig_probability)->toBe(0.5)
        ->and($quotes['home']->is_pregame)->toBeTrue();

    $this->artisan('sports:backfill-market-quotes', ['--sport' => ['nba']])
        ->assertSuccessful();

    expect(MarketQuote::query()->count())->toBe(2);
});

it('preserves generic participant markets without applying core-market pair requirements', function () {
    $homeTeam = MlbTeam::factory()->create();
    $awayTeam = MlbTeam::factory()->create();
    $game = MlbGame::factory()->create([
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'game_date' => '2026-07-27 19:00:00',
    ]);
    $odds = [
        'home_team' => 'Chicago Cubs',
        'away_team' => 'Milwaukee Brewers',
        'bookmakers' => [[
            'key' => 'draftkings',
            'title' => 'DraftKings',
            'markets' => [
                [
                    'key' => 'pitcher_strikeouts',
                    'outcomes' => [
                        [
                            'name' => 'Over',
                            'description' => 'Shota Imanaga',
                            'price' => -120,
                            'point' => 6.5,
                        ],
                        [
                            'name' => 'Under',
                            'description' => 'Shota Imanaga',
                            'price' => 100,
                            'point' => 6.5,
                        ],
                    ],
                ],
                [
                    'key' => 'batter_home_runs',
                    'outcomes' => [[
                        'name' => 'Yes',
                        'description' => 'Pete Crow-Armstrong',
                        'price' => 320,
                    ]],
                ],
            ],
        ]],
    ];

    app(GameOddsSnapshotRecorder::class)->record(
        'mlb',
        $game,
        ['id' => 'event-props', 'commence_time' => '2026-07-27T19:00:00Z'],
        $odds,
        Carbon::parse('2026-07-27T18:00:00Z'),
    );

    $strikeouts = MarketQuote::query()
        ->where('market_key', 'pitcher_strikeouts')
        ->orderBy('side')
        ->get()
        ->keyBy('side');
    $homeRun = MarketQuote::query()
        ->where('market_key', 'batter_home_runs')
        ->first();

    expect(MarketQuote::query()->count())->toBe(3)
        ->and($strikeouts)->toHaveCount(2)
        ->and($strikeouts['over']->participant)->toBe('Shota Imanaga')
        ->and((float) $strikeouts['over']->line)->toBe(6.5)
        ->and((float) $strikeouts->sum('no_vig_probability'))->toBe(1.0)
        ->and($homeRun)->not->toBeNull()
        ->and($homeRun->side)->toBe('yes')
        ->and($homeRun->participant)->toBe('Pete Crow-Armstrong')
        ->and((float) $homeRun->no_vig_probability)->toBe(1.0);
});
