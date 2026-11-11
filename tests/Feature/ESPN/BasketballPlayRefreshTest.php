<?php

use App\Actions\ESPN\CBB\SyncPlays as SyncCbbPlays;
use App\Actions\ESPN\NBA\SyncPlays as SyncNbaPlays;
use App\Models\CBB\Game as CbbGame;
use App\Models\CBB\Team as CbbTeam;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Team as NbaTeam;
use App\Services\ESPN\BaseEspnService;
use Illuminate\Support\Facades\DB;

it('keeps identical basketball refreshes read-only and applies genuine corrections', function (string $gameClass, string $teamClass, string $actionClass, bool $summaryPlays) {
    $game = $gameClass::factory()->create([
        'home_team_id' => $teamClass::factory(),
        'away_team_id' => $teamClass::factory(),
    ]);
    $payload = [['id' => '1001', 'text' => 'Made basket', 'homeScore' => 2, 'scoringPlay' => true]];
    $service = Mockery::mock(BaseEspnService::class);
    $service->shouldReceive('getGame')->andReturnUsing(function () use (&$payload, $summaryPlays): array {
        return $summaryPlays ? ['plays' => $payload] : ['competitions' => [['id' => '1']]];
    });
    $service->shouldReceive('getPlays')->andReturnUsing(function () use (&$payload): array {
        return ['items' => $payload];
    });

    $sync = new $actionClass($service);
    expect($sync->execute($game->espn_event_id))->toBe(1);
    $original = $game->plays()->firstOrFail();
    $original->update(['true_epa' => 0.75]);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $synced = $sync->execute($game->espn_event_id);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($synced)->toBe(1)
        ->and($queries->contains(fn (string $sql) => preg_match('/^(delete|insert|update) /i', $sql) === 1))->toBeFalse()
        ->and($game->plays()->firstOrFail()->getKey())->toBe($original->getKey())
        ->and((float) $original->fresh()->true_epa)->toBe(0.75);

    $payload = [];
    expect($sync->execute($game->espn_event_id))->toBe(0)
        ->and($game->plays()->count())->toBe(1);

    $payload = [['id' => '1001', 'text' => 'Corrected basket', 'homeScore' => 3, 'scoringPlay' => true]];
    expect($sync->execute($game->espn_event_id))->toBe(1)
        ->and($game->plays()->sole()->play_text)->toBe('Corrected basket');
})->with([
    'CBB' => [CbbGame::class, CbbTeam::class, SyncCbbPlays::class, false],
    'NBA' => [NbaGame::class, NbaTeam::class, SyncNbaPlays::class, true],
]);
