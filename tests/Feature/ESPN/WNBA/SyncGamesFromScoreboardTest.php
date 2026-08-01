<?php

use App\Actions\ESPN\WNBA\SyncGamesFromScoreboard;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;
use App\Services\ESPN\WNBA\EspnService;
use Illuminate\Support\Carbon;

uses()->group('espn', 'wnba');

test('wnba scoreboard sync does not promote a game to final before the post tip window', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:00', 'UTC'));

    try {
        Team::factory()->create(['espn_id' => '24']);
        Team::factory()->create(['espn_id' => '26']);

        $service = new class extends EspnService
        {
            public function getScoreboard(?string $date = null): ?array
            {
                return [
                    'events' => [
                        [
                            'id' => '401857104',
                            'uid' => 's:40~l:59~e:401857104',
                            'date' => '2026-08-01T02:00Z',
                            'name' => 'Indiana Fever at Portland Fire',
                            'shortName' => 'IND @ POR',
                            'season' => ['year' => 2026, 'type' => 2],
                            'week' => ['number' => 12],
                            'status' => [
                                'type' => ['name' => 'STATUS_FINAL'],
                                'period' => 4,
                                'displayClock' => '0.0',
                            ],
                            'competitions' => [
                                [
                                    'id' => '401857104',
                                    'competitors' => [
                                        [
                                            'team' => ['id' => '26', 'displayName' => 'Portland Fire', 'abbreviation' => 'POR'],
                                            'homeAway' => 'home',
                                            'score' => 98,
                                            'linescores' => [['value' => 25], ['value' => 22], ['value' => 25], ['value' => 26]],
                                        ],
                                        [
                                            'team' => ['id' => '24', 'displayName' => 'Indiana Fever', 'abbreviation' => 'IND'],
                                            'homeAway' => 'away',
                                            'score' => 112,
                                            'linescores' => [['value' => 27], ['value' => 29], ['value' => 34], ['value' => 22]],
                                        ],
                                    ],
                                    'status' => ['type' => ['name' => 'STATUS_FINAL']],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

        $livePrediction = new class
        {
            public function execute(Game $game): void {}
        };

        $synced = (new SyncGamesFromScoreboard($service, $livePrediction))->execute('20260801');

        $game = Game::query()->where('espn_event_id', '401857104')->first();

        expect($synced)->toBe(1)
            ->and($game)->not->toBeNull()
            ->and($game->status)->toBe('STATUS_SCHEDULED')
            ->and($game->home_score)->toBeNull()
            ->and($game->away_score)->toBeNull()
            ->and($game->period)->toBeNull()
            ->and($game->game_clock)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

test('wnba scoreboard sync does not promote a game to final with a non final game clock', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-01 04:00:00', 'UTC'));

    try {
        Team::factory()->create(['espn_id' => '24']);
        Team::factory()->create(['espn_id' => '26']);

        $service = new class extends EspnService
        {
            public function getScoreboard(?string $date = null): ?array
            {
                return [
                    'events' => [
                        [
                            'id' => '401857105',
                            'uid' => 's:40~l:59~e:401857105',
                            'date' => '2026-08-01T02:00Z',
                            'name' => 'Indiana Fever at Portland Fire',
                            'shortName' => 'IND @ POR',
                            'season' => ['year' => 2026, 'type' => 2],
                            'week' => ['number' => 12],
                            'status' => [
                                'type' => ['name' => 'STATUS_FINAL'],
                                'period' => 4,
                                'displayClock' => '10:00',
                            ],
                            'competitions' => [
                                [
                                    'id' => '401857105',
                                    'competitors' => [
                                        [
                                            'team' => ['id' => '26', 'displayName' => 'Portland Fire', 'abbreviation' => 'POR'],
                                            'homeAway' => 'home',
                                            'score' => 98,
                                        ],
                                        [
                                            'team' => ['id' => '24', 'displayName' => 'Indiana Fever', 'abbreviation' => 'IND'],
                                            'homeAway' => 'away',
                                            'score' => 112,
                                        ],
                                    ],
                                    'status' => ['type' => ['name' => 'STATUS_FINAL']],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

        $livePrediction = new class
        {
            public function execute(Game $game): void {}
        };

        $synced = (new SyncGamesFromScoreboard($service, $livePrediction))->execute('20260801');

        $game = Game::query()->where('espn_event_id', '401857105')->first();

        expect($synced)->toBe(1)
            ->and($game)->not->toBeNull()
            ->and($game->status)->toBe('STATUS_SCHEDULED')
            ->and($game->home_score)->toBeNull()
            ->and($game->away_score)->toBeNull()
            ->and($game->game_clock)->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});
