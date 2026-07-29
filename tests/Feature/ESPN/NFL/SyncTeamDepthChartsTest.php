<?php

use App\Actions\ESPN\NFL\SyncTeamDepthCharts;
use App\Models\NFL\DepthChartEntry;
use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\Player;
use App\Models\NFL\Team;
use App\Services\ESPN\BaseEspnService;
use Carbon\Carbon;

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

it('preserves each nfl depth chart observation across repeated syncs', function () {
    $team = Team::factory()->create(['espn_id' => '22']);
    $firstQuarterback = Player::factory()->create(['team_id' => $team->id, 'espn_id' => '123']);
    $secondQuarterback = Player::factory()->create(['team_id' => $team->id, 'espn_id' => '456']);

    $service = new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'nfl';

        public array $payload = [];

        public function getTeamDepthCharts(string $teamId, int $season): ?array
        {
            return $this->payload;
        }
    };

    $depthChartPayload = function (
        string $sourceUpdatedAt,
        int $firstQuarterbackRank,
        int $secondQuarterbackRank
    ): array {
        return [
            'lastUpdated' => $sourceUpdatedAt,
            'items' => [[
                'id' => 'offense',
                'name' => 'Offense',
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
                                'rank' => $firstQuarterbackRank,
                                'athlete' => ['id' => '123'],
                            ],
                            [
                                'slot' => 1,
                                'rank' => $secondQuarterbackRank,
                                'athlete' => ['id' => '456'],
                            ],
                        ],
                    ],
                ],
            ]],
        ];
    };

    try {
        Carbon::setTestNow('2026-07-26 10:00:00');
        $service->payload = $depthChartPayload('2026-07-26T09:45:00Z', 1, 2);
        (new SyncTeamDepthCharts($service))->execute('22', 2026);

        Carbon::setTestNow('2026-07-26 12:00:00');
        $service->payload = $depthChartPayload('2026-07-26T11:50:00Z', 2, 1);
        (new SyncTeamDepthCharts($service))->execute('22', 2026);
    } finally {
        Carbon::setTestNow();
    }

    $snapshots = DepthChartSnapshot::query()
        ->with(['entries' => fn ($query) => $query->orderBy('espn_athlete_id')])
        ->orderBy('observed_at')
        ->get();

    expect($snapshots)->toHaveCount(2)
        ->and($snapshots[0]->snapshot_uuid)->not->toBe($snapshots[1]->snapshot_uuid)
        ->and($snapshots[0]->observed_at->toDateTimeString())->toBe('2026-07-26 10:00:00')
        ->and($snapshots[0]->source_updated_at->utc()->toDateTimeString())->toBe('2026-07-26 09:45:00')
        ->and($snapshots[0]->entry_count)->toBe(2)
        ->and($snapshots[0]->entries[0]->player_id)->toBe($firstQuarterback->id)
        ->and($snapshots[0]->entries[0]->depth_rank)->toBe(1)
        ->and($snapshots[1]->observed_at->toDateTimeString())->toBe('2026-07-26 12:00:00')
        ->and($snapshots[1]->source_updated_at->utc()->toDateTimeString())->toBe('2026-07-26 11:50:00')
        ->and($snapshots[1]->entries[0]->depth_rank)->toBe(2)
        ->and($snapshots[1]->entries[1]->player_id)->toBe($secondQuarterback->id)
        ->and($snapshots[1]->entries[1]->depth_rank)->toBe(1);

    expect(DepthChartEntry::query()->where('team_id', $team->id)->count())->toBe(2)
        ->and(
            DepthChartEntry::query()
                ->where('team_id', $team->id)
                ->where('player_id', $secondQuarterback->id)
                ->value('is_starter')
        )->toBeTruthy();
});
