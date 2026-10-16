<?php

use App\Actions\ESPN\NFL\SyncPlayerInjuries;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerInjurySnapshot;
use App\Models\NFL\Team;
use App\Services\ESPN\BaseEspnService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->team = Team::factory()->create(['espn_id' => '22']);
    $this->player = Player::factory()->create(['team_id' => $this->team->id, 'espn_id' => '123']);
    $this->action = new class(new BaseEspnService('nfl')) extends SyncPlayerInjuries
    {
        public array $payload = [];

        public bool $reliable = true;

        protected function extractTeamInjuries(string $teamEspnId): array
        {
            $this->injuryPayloadObserved = true;
            $this->injuryPayloadReliable = $this->reliable;

            return $this->payload;
        }
    };
    $this->action->payload = [[
        'id' => 'injury-123',
        'athlete' => ['id' => '123'],
        'status' => ['type' => 'Out'],
        'details' => ['detail' => 'Left hamstring'],
        'date' => '2026-07-24T08:00:00Z',
        'lastUpdated' => '2026-07-26T09:30:00Z',
    ]];
    $this->travelTo(now()->setDate(2026, 7, 26)->setTime(10, 0));
});

afterEach(function () {
    DB::disableQueryLog();
    DB::flushQueryLog();
    $this->travelBack();
});

it('refreshes unchanged observations without rewriting payloads or toggling active state', function () {
    $this->action->execute('22');
    $original = PlayerInjury::query()->sole();
    $this->travel(30)->minutes();

    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($this->action->execute('22'))->toBe(1);
    $writes = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, 'nfl_player_injuries')
            && preg_match('/^(insert|update|delete)/i', $sql));

    expect($writes)->toHaveCount(1)
        ->and($writes->first())->toContain('updated_at')->not->toContain('raw_payload')->not->toContain('is_active');
    $current = PlayerInjury::query()->sole();
    expect($current->is_active)->toBeTrue()
        ->and($current->updated_at->gt($original->updated_at))->toBeTrue()
        ->and($current->source_updated_at->equalTo($original->source_updated_at))->toBeTrue()
        ->and($current->raw_payload)->toBe($original->raw_payload)
        ->and(PlayerInjurySnapshot::query()->count())->toBe(1);
});

it('ignores JSON object key order when deciding whether to rewrite a payload', function () {
    $this->action->execute('22');
    $current = PlayerInjury::query()->sole();
    $current->raw_payload = array_reverse($current->raw_payload, true);
    $current->save();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->action->execute('22');

    $writes = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $sql): bool => str_contains($sql, 'nfl_player_injuries')
            && preg_match('/^(insert|update)/i', $sql));
    expect($writes)->toHaveCount(1)
        ->and($writes->first())->not->toContain('raw_payload');
});

it('persists changed injury status and preserves the earlier snapshot', function () {
    $this->action->execute('22');
    $this->travel(30)->minutes();
    $this->action->payload[0]['status']['type'] = 'Questionable';
    $this->action->execute('22');

    expect(PlayerInjury::query()->sole()->status)->toBe('Questionable')
        ->and(PlayerInjurySnapshot::query()->count())->toBe(2)
        ->and(PlayerInjurySnapshot::query()->oldest('id')->first()->entries()->sole()->status)->toBe('Out');
});

it('only deactivates missing injuries after reliable reports', function (bool $reliable) {
    $this->action->execute('22');
    $this->action->payload = [];
    $this->action->reliable = $reliable;
    $this->travel(30)->minutes();
    $this->action->execute('22');

    expect(PlayerInjury::query()->sole()->is_active)->toBe(! $reliable);
})->with([true, false]);

it('does not toggle a retained injury when another injury disappears', function () {
    $other = Player::factory()->create(['team_id' => $this->team->id, 'espn_id' => '456']);
    $removed = PlayerInjury::query()->create([
        'player_id' => $other->id, 'team_id' => $this->team->id,
        'injury_key' => 'removed', 'status' => 'Out', 'is_active' => true,
    ]);
    $this->action->execute('22');

    expect($removed->fresh()->is_active)->toBeFalse()
        ->and(PlayerInjury::query()->where('player_id', $this->player->id)->sole()->is_active)->toBeTrue();
});
