<?php

use App\Actions\ESPN\NFL\SyncTeamDepthCharts;
use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\Player;
use App\Models\NFL\Team;
use App\Services\ESPN\BaseEspnService;

uses()->group('espn', 'nfl');

it('syncs nfl depth chart entries from espn', function () {
    $team = Team::factory()->create(['espn_id' => '22']);
    $starter = Player::factory()->create(['team_id' => $team->id, 'espn_id' => '123']);
    $backup = Player::factory()->create(['team_id' => $team->id, 'espn_id' => '456']);

    $service = new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'nfl';

        public function getTeamDepthCharts(string $teamId, int $season): ?array
        {
            expect($teamId)->toBe('22');
            expect($season)->toBe(2025);

            return [
                'items' => [[
                    'id' => '16',
                    'name' => 'Base 4-3 D',
                    'positions' => [
                        'qb' => [
                            'position' => [
                                'id' => '1',
                                'name' => 'Quarterback',
                                'displayName' => 'Quarterback',
                                'abbreviation' => 'QB',
                            ],
                            'athletes' => [
                                [
                                    'slot' => 1,
                                    'rank' => 1,
                                    'athlete' => ['$ref' => 'https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/2025/athletes/123?lang=en&region=us'],
                                ],
                                [
                                    'slot' => 1,
                                    'rank' => 2,
                                    'athlete' => ['$ref' => 'https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/2025/athletes/456?lang=en&region=us'],
                                ],
                            ],
                        ],
                    ],
                ]],
            ];
        }
    };

    $count = (new SyncTeamDepthCharts($service))->execute('22', 2025);

    expect($count)->toBe(2);

    $entries = DepthChartEntry::query()
        ->where('team_id', $team->id)
        ->orderBy('depth_rank')
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->player_id)->toBe($starter->id)
        ->and($entries[0]->position_slot_key)->toBe('qb')
        ->and($entries[0]->position_code)->toBe('QB')
        ->and($entries[0]->is_starter)->toBeTrue()
        ->and($entries[1]->player_id)->toBe($backup->id)
        ->and($entries[1]->depth_rank)->toBe(2)
        ->and($entries[1]->is_starter)->toBeFalse();
});
