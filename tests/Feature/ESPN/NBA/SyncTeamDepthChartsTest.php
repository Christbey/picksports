<?php

use App\Actions\ESPN\NBA\SyncTeamDepthCharts;
use App\Models\NBA\DepthChartEntry;
use App\Models\NBA\Player;
use App\Models\NBA\Team;
use App\Services\ESPN\BaseEspnService;

uses()->group('espn', 'nba');

it('syncs nba depth chart entries from espn', function () {
    $team = Team::factory()->create(['espn_id' => '1']);
    $starter = Player::factory()->create(['team_id' => $team->id, 'espn_id' => '1001']);
    $bench = Player::factory()->create(['team_id' => $team->id, 'espn_id' => '1002']);

    $service = new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'nba';

        public function getTeamDepthCharts(string $teamId, int $season): ?array
        {
            expect($teamId)->toBe('1');
            expect($season)->toBe(2025);

            return [
                'items' => [[
                    'id' => '1',
                    'name' => 'Depth Chart',
                    'positions' => [
                        'pg' => [
                            'position' => [
                                'id' => '1',
                                'name' => 'Point Guard',
                                'displayName' => 'Point Guard',
                                'abbreviation' => 'PG',
                            ],
                            'athletes' => [
                                [
                                    'rank' => 1,
                                    'athlete' => ['$ref' => 'https://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/2025/athletes/1001?lang=en&region=us'],
                                ],
                                [
                                    'rank' => 2,
                                    'athlete' => ['$ref' => 'https://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/2025/athletes/1002?lang=en&region=us'],
                                ],
                            ],
                        ],
                    ],
                ]],
            ];
        }
    };

    $count = (new SyncTeamDepthCharts($service))->execute('1', 2025);

    expect($count)->toBe(2);

    $entries = DepthChartEntry::query()
        ->where('team_id', $team->id)
        ->orderBy('depth_rank')
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->player_id)->toBe($starter->id)
        ->and($entries[0]->position_slot_key)->toBe('pg')
        ->and($entries[0]->position_code)->toBe('PG')
        ->and($entries[1]->player_id)->toBe($bench->id);
});
