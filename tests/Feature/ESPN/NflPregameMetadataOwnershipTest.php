<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\ESPN\NFL\EspnService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T16:00:00Z'));
    Http::preventStrayRequests();
});

function metadataOwnerGame(): Game
{
    return Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'season' => 2026, 'season_type' => 2, 'game_date' => '2026-11-28', 'game_time' => '12:00:00', 'status' => 'STATUS_SCHEDULED',
        'venue_name' => 'Keep venue', 'venue_city' => 'Keep city', 'name' => 'Keep matchup', 'short_name' => 'AWY @ HME', 'home_score' => 0, 'away_score' => 0]);
}
function metadataOwnerPayload(Game $game): array
{
    return ['header' => ['id' => (string) $game->espn_event_id, 'season' => ['year' => 2026], 'competitions' => [[
        'status' => ['type' => ['name' => 'STATUS_SCHEDULED']], 'neutralSite' => false, 'competitors' => [
            ['homeAway' => 'home', 'team' => ['id' => (string) $game->homeTeam->espn_id, 'displayName' => 'Current home', 'abbreviation' => 'HOM']],
            ['homeAway' => 'away', 'team' => ['id' => (string) $game->awayTeam->espn_id, 'displayName' => 'Current away', 'abbreviation' => 'AWY']],
        ],
    ]]], 'gameInfo' => ['venue' => ['fullName' => 'New venue', 'address' => ['city' => 'New city', 'state' => 'XY']]]];
}
function metadataOwnerRow(Game $game): array
{
    return (array) DB::table('nfl_games')->where('id', $game->id)->first();
}

it('does not widen an explicit invalid NFL metadata selector into summary fetches', function (string $id) {
    $games = [metadataOwnerGame(), metadataOwnerGame()];
    $rows = array_map('metadataOwnerRow', $games);
    $calls = 0;
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->andReturnUsing(function (string $event) use ($games, &$calls) {
        $calls++;
        foreach ($games as $game) {
            if ((string) $game->espn_event_id === $event) {
                return metadataOwnerPayload($game);
            }
        }

        return null;
    });
    app()->instance(EspnService::class, $service);
    $code = Artisan::call('espn:sync-nfl-pregame-metadata', ['--season' => 2026, '--game-id' => $id]);
    expect($calls)->toBe(0)->and($code)->not->toBe(0)->and(array_map('metadataOwnerRow', $games))->toBe($rows);
})->with(['0', 'bad']);

it('refuses a same event summary explicitly naming another NFL matchup team', function () {
    $game = metadataOwnerGame();
    $payload = metadataOwnerPayload($game);
    $foreign = Team::factory()->create();
    $payload['header']['competitions'][0]['competitors'][0]['team']['id'] = (string) $foreign->espn_id;
    $payload['header']['competitions'][0]['competitors'][0]['team']['displayName'] = 'Wrong home';
    $before = metadataOwnerRow($game);
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->once()->with((string) $game->espn_event_id)->andReturn($payload);
    app()->instance(EspnService::class, $service);
    $code = Artisan::call('espn:sync-nfl-pregame-metadata', ['--game-id' => $game->id]);
    expect(metadataOwnerRow($game))->toBe($before)->and($code)->not->toBe(0);
});

it('refuses a stored NFL matchup reassignment during summary IO without overwriting the newer row', function () {
    $game = metadataOwnerGame();
    $payload = metadataOwnerPayload($game);
    $foreign = Team::factory()->create();
    $afterPeer = null;
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->once()->with((string) $game->espn_event_id)->andReturnUsing(function () use ($game, $payload, $foreign, &$afterPeer) {
        DB::table('nfl_games')->where('id', $game->id)->update(['home_team_id' => $foreign->id]);
        $afterPeer = metadataOwnerRow($game);

        return $payload;
    });
    app()->instance(EspnService::class, $service);
    $code = Artisan::call('espn:sync-nfl-pregame-metadata', ['--game-id' => $game->id]);
    expect($afterPeer)->not->toBeNull()->and(metadataOwnerRow($game))->toBe($afterPeer)->and($code)->not->toBe(0);
});

it('preserves supported matching and partial NFL venue summaries without changing observed zero scores', function (bool $includeIds) {
    $game = metadataOwnerGame();
    $payload = metadataOwnerPayload($game);
    if (! $includeIds) {
        foreach ($payload['header']['competitions'][0]['competitors'] as &$item) {
            unset($item['team']['id']);
        }unset($item);
    }
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->once()->with((string) $game->espn_event_id)->andReturn($payload);
    app()->instance(EspnService::class, $service);
    expect(Artisan::call('espn:sync-nfl-pregame-metadata', ['--game-id' => $game->id]))->toBe(0);
    $fresh = $game->fresh();
    expect($fresh->venue_name)->toBe('New venue')->and($fresh->venue_city)->toBe('New city')->and($fresh->short_name)->toBe('AWY @ HOM')->and($fresh->home_score)->toBe(0)->and($fresh->away_score)->toBe(0);
})->with([true, false]);

it('does not acknowledge a cancelled NFL metadata save as an unchanged success', function () {
    $game = metadataOwnerGame();
    $payload = metadataOwnerPayload($game);
    $before = metadataOwnerRow($game);
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->once()->with((string) $game->espn_event_id)->andReturn($payload);
    app()->instance(EspnService::class, $service);
    $dispatcher = Game::getEventDispatcher();
    Game::setEventDispatcher(clone $dispatcher);
    $reached = 0;
    Game::saving(function () use (&$reached) {
        $reached++;

        return false;
    });
    try {
        $code = Artisan::call('espn:sync-nfl-pregame-metadata', ['--game-id' => $game->id]);
    } finally {
        Game::setEventDispatcher($dispatcher);
    }
    expect($reached)->toBe(1)->and(metadataOwnerRow($game))->toBe($before)->and($code)->not->toBe(0);
});

it('refuses an NFL provider team reference change during metadata IO', function () {
    $game = metadataOwnerGame();
    $payload = metadataOwnerPayload($game);
    $before = metadataOwnerRow($game);
    $team = $game->homeTeam;
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->once()->with((string) $game->espn_event_id)->andReturnUsing(function () use ($team, $payload) {
        DB::table('nfl_teams')->where('id', $team->id)->update(['espn_id' => 'synthetic-reassigned-ref']);

        return $payload;
    });
    app()->instance(EspnService::class, $service);
    $code = Artisan::call('espn:sync-nfl-pregame-metadata', ['--game-id' => $game->id]);
    expect(metadataOwnerRow($game))->toBe($before)->and($code)->not->toBe(0)->and($team->fresh()->espn_id)->toBe('synthetic-reassigned-ref');
});

it('does not acknowledge an NFL metadata observer replacing the requested venue', function () {
    $game = metadataOwnerGame();
    $payload = metadataOwnerPayload($game);
    $before = metadataOwnerRow($game);
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->once()->with((string) $game->espn_event_id)->andReturn($payload);
    app()->instance(EspnService::class, $service);
    $dispatcher = Game::getEventDispatcher();
    Game::setEventDispatcher(clone $dispatcher);
    $reached = 0;
    Game::saving(function ($row) use (&$reached) {
        $reached++;
        $row->venue_name = 'Unrequested venue';
    });
    try {
        $code = Artisan::call('espn:sync-nfl-pregame-metadata', ['--game-id' => $game->id]);
    } finally {
        Game::setEventDispatcher($dispatcher);
    }
    expect($reached)->toBe(1)->and(metadataOwnerRow($game))->toBe($before)->and($code)->not->toBe(0);
});
