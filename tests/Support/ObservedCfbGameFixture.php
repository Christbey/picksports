<?php

namespace Tests\Support;

use App\Actions\ESPN\CFB\SyncGameMetadata;
use App\Models\CFB\Game;
use App\Services\ESPN\CFB\EspnService;
use App\Support\CFB\CfbGameStart;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use LogicException;
use Mockery;

/** Explicit synthetic provider observation for an owning fixture, never a factory-wide backstamp. */
final class ObservedCfbGameFixture
{
    public static function observe(Game $game, CarbonInterface|string $kickoff): Game
    {
        Http::preventStrayRequests();
        $instant = $kickoff instanceof CarbonInterface
            ? CarbonImmutable::instance($kickoff)->utc()
            : CarbonImmutable::parse($kickoff)->utc();
        $provider = Mockery::mock(EspnService::class);
        $provider->shouldReceive('getGame')->once()->with((string) $game->espn_event_id)
            ->andReturn(['header' => ['id' => (string) $game->espn_event_id, 'season' => ['year' => (int) $game->season],
                'competitions' => [['date' => $instant->toIso8601String(), 'dateValid' => true, 'timeValid' => true,
                    'status' => ['type' => ['name' => 'STATUS_SCHEDULED']]]]]]);
        if (! (new SyncGameMetadata($provider))->execute($game)) {
            throw new LogicException('Synthetic CFB provider observation did not complete.');
        }
        $observed = $game->fresh();
        if (CfbGameStart::for($observed)?->equalTo($instant) !== true) {
            throw new LogicException('Synthetic CFB provider observation did not bind the intended kickoff.');
        }

        return $observed;
    }
}
