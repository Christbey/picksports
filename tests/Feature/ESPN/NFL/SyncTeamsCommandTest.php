<?php

use App\Actions\ESPN\NFL\SyncTeams;
use App\Models\NFL\Team;
use App\Services\ESPN\BaseEspnService;

uses()->group('espn', 'nfl');

it('hydrates conference and division from group refs during nfl team sync', function () {
    $service = new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'nfl';

        public function getTeams(): ?array
        {
            return [
                'sports' => [[
                    'leagues' => [[
                        'teams' => [
                            [
                                'team' => [
                                    'id' => '1',
                                    'abbreviation' => 'ATL',
                                    'location' => 'Atlanta',
                                    'name' => 'Falcons',
                                ],
                            ],
                        ],
                    ]],
                ]],
            ];
        }

        public function getTeam(string $teamId): ?array
        {
            return [
                'team' => [
                    'id' => '1',
                    'abbreviation' => 'ATL',
                    'location' => 'Atlanta',
                    'name' => 'Falcons',
                    'group' => [
                        '$ref' => 'https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/groups/8?lang=en&region=us',
                        'parent' => [
                            '$ref' => 'https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/groups/3?lang=en&region=us',
                        ],
                    ],
                ],
            ];
        }

        public function getByRef(string $url, bool $useCache = true): ?array
        {
            if (str_contains($url, '/groups/8')) {
                return [
                    'id' => '8',
                    'name' => 'NFC',
                    'abbreviation' => 'NFC',
                    'parent' => [
                        '$ref' => 'https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/groups/3?lang=en&region=us',
                    ],
                ];
            }

            if (str_contains($url, '/groups/3')) {
                return [
                    'id' => '3',
                    'name' => 'South',
                    'abbreviation' => 'S',
                ];
            }

            return null;
        }
    };

    $action = new SyncTeams($service);
    $count = $action->execute();

    expect($count)->toBe(1);

    $team = Team::where('espn_id', '1')->first();

    expect($team)->not->toBeNull();
    expect($team->conference)->toBe('NFC');
    expect($team->division)->toBe('South');
});
