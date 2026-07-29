<?php

use App\Models\MarketQuote;
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
