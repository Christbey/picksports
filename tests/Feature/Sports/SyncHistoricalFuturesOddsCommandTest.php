<?php

use App\Models\NFL\Team;
use App\Models\Sports\FuturesOddsSnapshot;
use App\Services\OddsApi\OddsApiService;
use Illuminate\Support\Facades\Artisan;

it('records historical team futures snapshots from the odds api', function () {
    Team::factory()->create([
        'name' => 'Chiefs',
        'location' => 'Kansas City',
        'abbreviation' => 'KC',
    ]);

    $oddsService = \Mockery::mock(OddsApiService::class);
    $oddsService->shouldReceive('getHistoricalOdds')
        ->once()
        ->andReturn([
            'timestamp' => '2025-08-01T12:00:00Z',
            'data' => [[
                'id' => 'historic-futures',
                'sport_title' => 'NFL Futures',
                'bookmakers' => [[
                    'key' => 'draftkings',
                    'markets' => [[
                        'key' => 'outrights',
                        'last_update' => '2025-08-01T12:00:00Z',
                        'outcomes' => [[
                            'name' => 'Chiefs',
                            'price' => 650,
                        ]],
                    ]],
                ]],
            ]],
        ]);
    $oddsService->shouldReceive('mappedEspnTeamName')->andReturnNull();
    $oddsService->shouldReceive('mappedEspnPlayerId')->andReturnNull();
    $oddsService->shouldReceive('mappedEspnPlayerName')->andReturnNull();
    $oddsService->shouldReceive('normalizeTeamName')->andReturnUsing(fn (string $name) => strtolower($name));
    $oddsService->shouldReceive('normalizePlayerName')->andReturnUsing(fn (string $name) => strtolower($name));

    app()->instance(OddsApiService::class, $oddsService);

    Artisan::call('sports:sync-historical-futures-odds', [
        '--sport' => ['nfl'],
        '--season' => 2025,
        '--date' => ['2025-08-01T12:00:00Z'],
    ]);

    $snapshot = FuturesOddsSnapshot::query()->first();

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->sport)->toBe('nfl')
        ->and($snapshot->season)->toBe(2025)
        ->and($snapshot->bookmaker)->toBe('draftkings')
        ->and($snapshot->market_key)->toBe('outrights')
        ->and($snapshot->price)->toBe(650)
        ->and($snapshot->nfl_team_id)->not->toBeNull();
});

it('expands a daily date range into multiple historical futures snapshots', function () {
    Team::factory()->create([
        'name' => 'Chiefs',
        'location' => 'Kansas City',
        'abbreviation' => 'KC',
    ]);

    $oddsService = \Mockery::mock(OddsApiService::class);
    $oddsService->shouldReceive('getHistoricalOdds')
        ->twice()
        ->andReturn(
            [
                'timestamp' => '2025-08-01T12:00:00Z',
                'data' => [[
                    'id' => 'historic-futures-1',
                    'sport_title' => 'NFL Futures',
                    'bookmakers' => [[
                        'key' => 'draftkings',
                        'markets' => [[
                            'key' => 'outrights',
                            'last_update' => '2025-08-01T12:00:00Z',
                            'outcomes' => [[
                                'name' => 'Chiefs',
                                'price' => 650,
                            ]],
                        ]],
                    ]],
                ]],
            ],
            [
                'timestamp' => '2025-08-02T12:00:00Z',
                'data' => [[
                    'id' => 'historic-futures-2',
                    'sport_title' => 'NFL Futures',
                    'bookmakers' => [[
                        'key' => 'draftkings',
                        'markets' => [[
                            'key' => 'outrights',
                            'last_update' => '2025-08-02T12:00:00Z',
                            'outcomes' => [[
                                'name' => 'Chiefs',
                                'price' => 625,
                            ]],
                        ]],
                    ]],
                ]],
            ]
        );
    $oddsService->shouldReceive('mappedEspnTeamName')->andReturnNull();
    $oddsService->shouldReceive('mappedEspnPlayerId')->andReturnNull();
    $oddsService->shouldReceive('mappedEspnPlayerName')->andReturnNull();
    $oddsService->shouldReceive('normalizeTeamName')->andReturnUsing(fn (string $name) => strtolower($name));
    $oddsService->shouldReceive('normalizePlayerName')->andReturnUsing(fn (string $name) => strtolower($name));

    app()->instance(OddsApiService::class, $oddsService);

    Artisan::call('sports:sync-historical-futures-odds', [
        '--sport' => ['nfl'],
        '--season' => 2025,
        '--from-date' => '2025-08-01',
        '--to-date' => '2025-08-02',
        '--daily' => true,
    ]);

    expect(FuturesOddsSnapshot::query()->count())->toBe(2);
});

it('supports syncing non-outright futures markets', function () {
    Team::factory()->create([
        'name' => 'Chiefs',
        'location' => 'Kansas City',
        'abbreviation' => 'KC',
    ]);

    $oddsService = \Mockery::mock(OddsApiService::class);
    $oddsService->shouldReceive('getHistoricalOdds')
        ->once()
        ->withArgs(function (
            string $sport,
            string $date,
            ?string $eventId,
            string $bookmaker,
            string $markets
        ): bool {
            return $sport === 'americanfootball_nfl_super_bowl_winner'
                && $date === '2025-08-01T12:00:00Z'
                && $eventId === null
                && $bookmaker === 'draftkings'
                && $markets === 'season_wins';
        })
        ->andReturn([
            'timestamp' => '2025-08-01T12:00:00Z',
            'data' => [[
                'id' => 'historic-futures-wins',
                'sport_title' => 'NFL Futures',
                'bookmakers' => [[
                    'key' => 'draftkings',
                    'markets' => [[
                        'key' => 'season_wins',
                        'last_update' => '2025-08-01T12:00:00Z',
                        'outcomes' => [
                            [
                                'name' => 'Over',
                                'description' => 'Chiefs',
                                'point' => 11.5,
                                'price' => -110,
                            ],
                            [
                                'name' => 'Under',
                                'description' => 'Chiefs',
                                'point' => 11.5,
                                'price' => -110,
                            ],
                        ],
                    ]],
                ]],
            ]],
        ]);
    $oddsService->shouldReceive('mappedEspnTeamName')->andReturnNull();
    $oddsService->shouldReceive('mappedEspnPlayerId')->andReturnNull();
    $oddsService->shouldReceive('mappedEspnPlayerName')->andReturnNull();
    $oddsService->shouldReceive('normalizeTeamName')->andReturnUsing(fn (string $name) => strtolower($name));
    $oddsService->shouldReceive('normalizePlayerName')->andReturnUsing(fn (string $name) => strtolower($name));

    app()->instance(OddsApiService::class, $oddsService);

    Artisan::call('sports:sync-historical-futures-odds', [
        '--sport' => ['nfl'],
        '--season' => 2025,
        '--date' => ['2025-08-01T12:00:00Z'],
        '--market' => ['season_wins'],
    ]);

    expect(FuturesOddsSnapshot::query()->count())->toBe(2)
        ->and(FuturesOddsSnapshot::query()->first()?->market_key)->toBe('season_wins');
});
