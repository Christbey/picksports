<?php

use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Services\ESPN\MLB\EspnService;
use App\Services\MLB\MlbLineScoreBackfillService;
use App\Support\MLB\MlbLineScores;
use Mockery as m;

uses()->group('espn', 'mlb');

afterEach(function () {
    m::close();
});

it('backfills only missing mlb line scores from the daily scoreboard', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();
    $game = Game::factory()->create([
        'espn_event_id' => '401999301',
        'season' => 2026,
        'season_type' => 2,
        'game_date' => '2026-04-01',
        'game_time' => '19:10:00',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_score' => 5,
        'away_score' => 4,
        'home_linescores' => null,
        'away_linescores' => null,
    ]);
    $espn = m::mock(EspnService::class);
    $espn->shouldReceive('getScoreboard')
        ->once()
        ->with('20260401')
        ->andReturn([
            'events' => [[
                'id' => '401999301',
                'competitions' => [[
                    'competitors' => [
                        [
                            'homeAway' => 'home',
                            'linescores' => [
                                ['value' => 1, 'displayValue' => '1'],
                                ['value' => 0, 'displayValue' => '0'],
                                ['value' => 2, 'displayValue' => '2'],
                            ],
                        ],
                        [
                            'homeAway' => 'away',
                            'linescores' => [
                                ['value' => 0, 'displayValue' => '0'],
                                ['value' => 1, 'displayValue' => '1'],
                                ['value' => 0, 'displayValue' => '0'],
                            ],
                        ],
                    ],
                ]],
            ]],
        ]);

    $report = (new MlbLineScoreBackfillService($espn))->backfill(
        season: 2026,
        sleepMilliseconds: 0,
    );

    $game->refresh();

    expect($report)->toMatchArray([
        'games' => 1,
        'dates' => 1,
        'updated' => 1,
        'unmatched' => 0,
        'failed_dates' => 0,
    ])->and($game->home_linescores)->toBe(['1', '0', '2'])
        ->and($game->away_linescores)->toBe(['0', '1', '0'])
        ->and($game->home_score)->toBe(5)
        ->and($game->away_score)->toBe(4);
});

it('normalizes raw and double encoded mlb line score formats', function () {
    expect(MlbLineScores::normalize([
        ['value' => 2, 'displayValue' => '2'],
        ['value' => 0, 'displayValue' => '0'],
    ]))->toBe(['2', '0'])
        ->and(MlbLineScores::normalize(json_encode(json_encode(['1', '3']))))->toBe(['1', '3']);
});

it('falls back to the espn event summary when a game is absent from its stored date scoreboard', function () {
    $homeTeam = Team::factory()->create();
    $awayTeam = Team::factory()->create();
    $game = Game::factory()->create([
        'espn_event_id' => '401999302',
        'season' => 2026,
        'season_type' => 2,
        'game_date' => '2026-06-09',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'home_linescores' => null,
        'away_linescores' => null,
    ]);
    $espn = m::mock(EspnService::class);
    $espn->shouldReceive('getScoreboard')->once()->with('20260609')->andReturn(['events' => []]);
    $espn->shouldReceive('getGame')->once()->with('401999302')->andReturn([
        'header' => [
            'competitions' => [[
                'competitors' => [
                    [
                        'homeAway' => 'home',
                        'linescores' => [['displayValue' => '2']],
                    ],
                    [
                        'homeAway' => 'away',
                        'linescores' => [['displayValue' => '1']],
                    ],
                ],
            ]],
        ],
    ]);

    $report = (new MlbLineScoreBackfillService($espn))->backfill(
        season: 2026,
        sleepMilliseconds: 0,
    );

    $game->refresh();

    expect($report['updated'])->toBe(1)
        ->and($report['event_fallbacks'])->toBe(1)
        ->and($report['unmatched'])->toBe(0)
        ->and($game->home_linescores)->toBe(['2'])
        ->and($game->away_linescores)->toBe(['1']);
});
