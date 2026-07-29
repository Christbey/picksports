<?php

use App\Actions\ESPN\NFL\SyncPlayerInjuries;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerInjurySnapshot;
use App\Models\NFL\Team;
use App\Services\ESPN\BaseEspnService;
use Carbon\Carbon;

uses()->group('espn', 'nfl');

it('preserves each nfl player injury observation across repeated syncs', function () {
    $team = Team::factory()->create(['espn_id' => '22']);
    $player = Player::factory()->create(['team_id' => $team->id, 'espn_id' => '123']);

    $service = new class extends BaseEspnService
    {
        protected const SPORT_KEY = 'nfl';

        public array $payload = [];

        public function getTeamInjuries(string $teamId): ?array
        {
            return $this->payload;
        }
    };

    $injuryPayload = function (string $status, string $sourceUpdatedAt): array {
        return [
            'items' => [[
                'id' => 'injury-123',
                'athlete' => ['id' => '123'],
                'status' => ['type' => $status],
                'type' => ['displayName' => 'Hamstring'],
                'details' => ['detail' => 'Left hamstring'],
                'date' => '2026-07-24T08:00:00Z',
                'lastUpdated' => $sourceUpdatedAt,
            ]],
        ];
    };

    try {
        Carbon::setTestNow('2026-07-26 10:00:00');
        $service->payload = $injuryPayload('Questionable', '2026-07-26T09:30:00Z');
        (new SyncPlayerInjuries($service))->execute('22');

        Carbon::setTestNow('2026-07-26 12:00:00');
        $service->payload = $injuryPayload('Out', '2026-07-26T11:45:00Z');
        (new SyncPlayerInjuries($service))->execute('22');
    } finally {
        Carbon::setTestNow();
    }

    $snapshots = PlayerInjurySnapshot::query()
        ->with('entries')
        ->orderBy('observed_at')
        ->get();

    expect($snapshots)->toHaveCount(2)
        ->and($snapshots[0]->snapshot_uuid)->not->toBe($snapshots[1]->snapshot_uuid)
        ->and($snapshots[0]->observed_at->toDateTimeString())->toBe('2026-07-26 10:00:00')
        ->and($snapshots[0]->source_updated_at->utc()->toDateTimeString())->toBe('2026-07-26 09:30:00')
        ->and($snapshots[0]->entry_count)->toBe(1)
        ->and($snapshots[0]->entries[0]->player_id)->toBe($player->id)
        ->and($snapshots[0]->entries[0]->status)->toBe('Questionable')
        ->and($snapshots[0]->entries[0]->observed_at->toDateTimeString())->toBe('2026-07-26 10:00:00')
        ->and($snapshots[1]->observed_at->toDateTimeString())->toBe('2026-07-26 12:00:00')
        ->and($snapshots[1]->source_updated_at->utc()->toDateTimeString())->toBe('2026-07-26 11:45:00')
        ->and($snapshots[1]->entries[0]->status)->toBe('Out')
        ->and($snapshots[1]->entries[0]->source_updated_at->utc()->toDateTimeString())
        ->toBe('2026-07-26 11:45:00');

    $currentInjury = PlayerInjury::query()->sole();

    expect($currentInjury->status)->toBe('Out')
        ->and($currentInjury->is_active)->toBeTrue();
});
