<?php

use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use Illuminate\Support\Carbon;

uses()->group('mlb', 'odds');

it('normalizes MLB moneyline run line and total snapshots with paired no-vig probabilities', function () {
    $game = createMlbGame([
        'season' => 2026,
        'game_date' => '2026-07-29',
    ]);
    $snapshot = createMlbOddsSnapshot($game, [
        'home_team' => 'Chicago Cubs',
        'away_team' => 'Milwaukee Brewers',
        'bookmakers' => [[
            'key' => 'draftkings',
            'title' => 'DraftKings',
            'markets' => [
                [
                    'key' => 'h2h',
                    'outcomes' => [
                        ['name' => 'Chicago Cubs', 'price' => -120],
                        ['name' => 'Milwaukee Brewers', 'price' => 110],
                    ],
                ],
                [
                    'key' => 'spreads',
                    'outcomes' => [
                        ['name' => 'Chicago Cubs', 'price' => 135, 'point' => -1.5],
                        ['name' => 'Milwaukee Brewers', 'price' => -155, 'point' => 1.5],
                    ],
                ],
                [
                    'key' => 'totals',
                    'outcomes' => [
                        ['name' => 'Over', 'price' => -105, 'point' => 8.5],
                        ['name' => 'Under', 'price' => -115, 'point' => 8.5],
                    ],
                ],
            ],
        ]],
    ]);

    $this->artisan('sports:backfill-market-quotes', [
        '--sport' => ['mlb'],
        '--season' => 2026,
        '--date' => '2026-07-29',
        '--chunk' => 1,
    ])->assertSuccessful();

    $quotes = MarketQuote::query()
        ->orderBy('market_key')
        ->orderBy('side')
        ->get();
    $moneyline = $quotes
        ->where('market_key', 'h2h')
        ->keyBy('side');
    $runLine = $quotes
        ->where('market_key', 'spreads')
        ->keyBy('side');
    $total = $quotes
        ->where('market_key', 'totals')
        ->keyBy('side');

    expect($quotes)->toHaveCount(6)
        ->and($quotes->pluck('game_odds_snapshot_id')->unique()->all())->toBe([$snapshot->id])
        ->and($quotes->pluck('sport')->unique()->all())->toBe(['mlb'])
        ->and($quotes->pluck('source')->unique()->all())->toBe(['odds_api'])
        ->and($quotes->pluck('bookmaker_key')->unique()->all())->toBe(['draftkings'])
        ->and($quotes->every(fn (MarketQuote $quote): bool => $quote->is_pregame === true))->toBeTrue()
        ->and((float) $moneyline['home']->implied_probability)->toBe(0.545455)
        ->and((float) $moneyline['home']->no_vig_probability)->toBe(0.533898)
        ->and((float) $moneyline['away']->no_vig_probability)->toBe(0.466102)
        ->and((float) $moneyline->sum('no_vig_probability'))->toBe(1.0)
        ->and((float) $runLine['home']->line)->toBe(-1.5)
        ->and((float) $runLine['away']->line)->toBe(1.5)
        ->and((float) $runLine['home']->bookmaker_home_line)->toBe(-1.5)
        ->and((float) $runLine['away']->bookmaker_home_line)->toBe(-1.5)
        ->and((float) $runLine['home']->home_margin_equivalent)->toBe(1.5)
        ->and($total['over']->price)->toBe(-105)
        ->and((float) $total['over']->line)->toBe(8.5)
        ->and((float) $total->sum('no_vig_probability'))->toBe(1.0)
        ->and($moneyline['home']->captured_at->equalTo($snapshot->captured_at))->toBeTrue()
        ->and($moneyline['home']->commence_time?->equalTo($snapshot->commence_time))->toBeTrue()
        ->and(data_get($runLine['home']->metadata, 'market_type'))->toBe('run_line');
});

it('is idempotent when the MLB quote backfill is rerun', function () {
    $game = createMlbGame([
        'season' => 2026,
        'game_date' => '2026-08-01',
    ]);
    createMlbOddsSnapshot($game, standardMlbMoneylinePayload());

    $this->artisan('sports:backfill-market-quotes', [
        '--sport' => ['mlb'],
        '--season' => 2026,
    ])
        ->expectsOutputToContain('Market quote backfill completed.')
        ->assertSuccessful();

    $firstHashes = MarketQuote::query()->orderBy('quote_hash')->pluck('quote_hash')->all();

    $this->artisan('sports:backfill-market-quotes', [
        '--sport' => ['mlb'],
        '--season' => 2026,
    ])
        ->expectsOutputToContain('Market quote backfill completed.')
        ->assertSuccessful();

    expect(MarketQuote::query()->count())->toBe(2)
        ->and(MarketQuote::query()->orderBy('quote_hash')->pluck('quote_hash')->all())->toBe($firstHashes)
        ->and(MarketQuote::query()->pluck('quote_hash')->filter()->unique())->toHaveCount(2);
});

it('marks quotes captured at or after first pitch as non-pregame and ignores other sports', function () {
    $game = createMlbGame([
        'season' => 2026,
        'game_date' => '2026-08-02',
    ]);
    $atStart = createMlbOddsSnapshot(
        $game,
        standardMlbMoneylinePayload(),
        capturedAt: '2026-08-02 19:05:00',
        commenceTime: '2026-08-02 19:05:00',
    );
    $afterStart = createMlbOddsSnapshot(
        $game,
        standardMlbMoneylinePayload(),
        capturedAt: '2026-08-02 19:06:00',
        commenceTime: '2026-08-02 19:05:00',
    );
    GameOddsSnapshot::query()->create([
        'sport' => 'nba',
        'game_table' => 'nba_games',
        'game_id' => 999,
        'source' => 'odds_api',
        'commence_time' => '2026-08-02 19:05:00',
        'captured_at' => '2026-08-02 18:00:00',
        'payload_hash' => hash('sha256', 'nba'),
        'odds_data' => standardMlbMoneylinePayload(),
    ]);

    $this->artisan('sports:backfill-market-quotes', [
        '--sport' => ['mlb'],
        '--season' => 2026,
        '--date' => '2026-08-02',
    ])->assertSuccessful();

    expect(MarketQuote::query()->count())->toBe(4)
        ->and(MarketQuote::query()
            ->whereIn('game_odds_snapshot_id', [$atStart->id, $afterStart->id])
            ->where('is_pregame', true)
            ->exists())->toBeFalse()
        ->and(MarketQuote::query()->where('sport', 'nba')->exists())->toBeFalse();
});

it('supports dry runs and reports malformed skipped payload rows', function () {
    $game = createMlbGame([
        'season' => 2026,
        'game_date' => '2026-08-03',
    ]);
    createMlbOddsSnapshot($game, [
        'home_team' => 'Chicago Cubs',
        'away_team' => 'Milwaukee Brewers',
        'bookmakers' => [[
            'key' => 'fanduel',
            'title' => 'FanDuel',
            'markets' => [
                [
                    'key' => 'h2h',
                    'outcomes' => [
                        ['name' => 'Chicago Cubs', 'price' => -125],
                    ],
                ],
                [
                    'key' => 'totals',
                    'outcomes' => [
                        ['name' => 'Over', 'point' => 9.0],
                        ['name' => 'Under', 'price' => -110, 'point' => 9.0],
                    ],
                ],
                [
                    'key' => 'alternate_spreads',
                    'outcomes' => [],
                ],
            ],
        ]],
    ]);

    $this->artisan('sports:backfill-market-quotes', [
        '--sport' => ['mlb'],
        '--season' => 2026,
        '--date' => '2026-08-03',
        '--chunk' => 1,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Market quote backfill dry run completed.')
        ->expectsOutputToContain('Representative malformed or skipped payload entries:')
        ->expectsOutputToContain('invalid_outcome')
        ->expectsOutputToContain('incomplete_pair')
        ->assertSuccessful();

    expect(MarketQuote::query()->count())->toBe(0);
});

/**
 * @param  array<string, mixed>  $oddsData
 */
function createMlbOddsSnapshot(
    Game $game,
    array $oddsData,
    string $capturedAt = '2026-07-29 18:00:00',
    string $commenceTime = '2026-07-29 19:05:00',
): GameOddsSnapshot {
    return GameOddsSnapshot::query()->create([
        'sport' => 'mlb',
        'game_table' => $game->getTable(),
        'game_id' => $game->id,
        'odds_api_event_id' => 'mlb-event-'.$game->id,
        'bookmaker_key' => data_get($oddsData, 'bookmakers.0.key'),
        'bookmaker_title' => data_get($oddsData, 'bookmakers.0.title'),
        'source' => 'odds_api',
        'commence_time' => Carbon::parse($commenceTime),
        'captured_at' => Carbon::parse($capturedAt),
        'payload_hash' => hash('sha256', json_encode([$oddsData, $capturedAt])),
        'odds_data' => $oddsData,
    ]);
}

/**
 * @return array<string, mixed>
 */
function standardMlbMoneylinePayload(): array
{
    return [
        'home_team' => 'Chicago Cubs',
        'away_team' => 'Milwaukee Brewers',
        'bookmakers' => [[
            'key' => 'draftkings',
            'title' => 'DraftKings',
            'markets' => [[
                'key' => 'h2h',
                'outcomes' => [
                    ['name' => 'Chicago Cubs', 'price' => -120],
                    ['name' => 'Milwaukee Brewers', 'price' => 110],
                ],
            ]],
        ]],
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createMlbGame(array $attributes): Game
{
    return Game::factory()->create($attributes + [
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
    ]);
}
