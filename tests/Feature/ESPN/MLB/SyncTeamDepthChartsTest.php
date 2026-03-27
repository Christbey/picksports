<?php

use App\Actions\ESPN\MLB\SyncTeamDepthCharts;
use App\Models\MLB\DepthChartEntry;
use App\Models\MLB\Player;
use App\Models\MLB\Team;
use App\Services\ESPN\BaseEspnService;

uses()->group('espn', 'mlb');

it('syncs mlb depth chart entries from espn', function () {
    $team = Team::factory()->create(['espn_id' => '10']);
    $starter = Player::factory()->create(['team_id' => $team->id, 'espn_id' => '2001']);
    Player::factory()->create(['team_id' => $team->id, 'espn_id' => '9999']);

    $service = new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'mlb';

        public function getTeamDepthCharts(string $teamId, int $season): ?array
        {
            expect($teamId)->toBe('10');
            expect($season)->toBe(2026);

            return [
                'items' => [[
                    'id' => '1',
                    'name' => 'Depth Chart',
                    'positions' => [
                        'sp' => [
                            'position' => [
                                'id' => '2',
                                'name' => 'Starting Pitcher',
                                'displayName' => 'Starting Pitcher',
                                'abbreviation' => 'SP',
                            ],
                            'athletes' => [
                                [
                                    'rank' => 1,
                                    'athlete' => ['$ref' => 'https://sports.core.api.espn.com/v2/sports/baseball/leagues/mlb/seasons/2026/athletes/2001?lang=en&region=us'],
                                ],
                                [
                                    'rank' => 2,
                                    'athlete' => ['$ref' => 'https://sports.core.api.espn.com/v2/sports/baseball/leagues/mlb/seasons/2026/athletes/2002?lang=en&region=us'],
                                ],
                            ],
                        ],
                    ],
                ]],
            ];
        }
    };

    $count = (new SyncTeamDepthCharts($service))->execute('10', 2026);

    expect($count)->toBe(2);

    $entries = DepthChartEntry::query()
        ->where('team_id', $team->id)
        ->orderBy('depth_rank')
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->player_id)->toBe($starter->id)
        ->and($entries[0]->espn_athlete_id)->toBe('2001')
        ->and($entries[0]->position_code)->toBe('SP')
        ->and($entries[1]->player_id)->toBeNull()
        ->and($entries[1]->espn_athlete_id)->toBe('2002');
});
