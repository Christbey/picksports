<?php

use App\Actions\ESPN\NFL\SyncGameDetails;
use App\Actions\ESPN\NFL\SyncGames;
use App\Actions\ESPN\NFL\SyncGamesFromScoreboard;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\ESPN\NFL\EspnService;
use App\Support\NflGameStateGuard;

it('preserves rich NFL metadata when a partial scoreboard omits it', function () {
    $home = Team::factory()->create(['espn_id' => '1']);
    $away = Team::factory()->create(['espn_id' => '2']);
    $game = Game::factory()->create([
        'espn_event_id' => '401999999', 'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'status' => 'STATUS_SCHEDULED', 'name' => 'Away at Home', 'short_name' => 'AWY @ HME',
        'venue_name' => 'Lumen Field', 'venue_city' => 'Seattle', 'venue_state' => 'WA',
        'broadcast_networks' => ['ESPN'],
    ]);
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getScoreboard')->once()->andReturn(['events' => [[
        'id' => '401999999', 'date' => '2026-09-20T17:00:00Z', 'season' => ['year' => 2026, 'type' => 2], 'week' => ['number' => 2],
        'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
        'competitions' => [['competitors' => [
            ['homeAway' => 'home', 'team' => ['id' => '1']], ['homeAway' => 'away', 'team' => ['id' => '2']],
        ]]],
    ]]]);
    $live = new class
    {
        public function execute(Game $game): void {}
    };
    (new SyncGamesFromScoreboard($service, $live))->execute('20260920');

    expect($game->fresh()->only(['name', 'short_name', 'venue_name', 'venue_city', 'venue_state', 'broadcast_networks']))->toBe([
        'name' => 'Away at Home', 'short_name' => 'AWY @ HME', 'venue_name' => 'Lumen Field',
        'venue_city' => 'Seattle', 'venue_state' => 'WA', 'broadcast_networks' => ['ESPN'],
    ]);
});

it('accepts a real venue relocation while preserving absent identity fields', function () {
    $game = new Game(['venue_name' => 'Original Stadium', 'venue_city' => 'Original City', 'short_name' => 'AWY @ HME']);
    $game->fill(NflGameStateGuard::preserve($game, ['venue_name' => 'Wembley Stadium', 'venue_city' => 'London', 'short_name' => null]));

    expect($game->venue_name)->toBe('Wembley Stadium')->and($game->venue_city)->toBe('London')->and($game->short_name)->toBe('AWY @ HME');
});

it('restores NFL venue metadata from the summary gameInfo payload', function () {
    $game = Game::factory()->create([
        'espn_event_id' => '401999999', 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'status' => 'STATUS_SCHEDULED', 'venue_name' => null, 'venue_city' => null, 'venue_state' => null, 'short_name' => null,
    ]);
    $payload = [
        'gameInfo' => ['venue' => ['fullName' => 'Lumen Field', 'address' => ['city' => 'Seattle', 'state' => 'WA']]],
        'header' => ['id' => '401999999', 'competitions' => [['neutralSite' => false, 'competitors' => [
            ['homeAway' => 'home', 'team' => ['displayName' => 'Seattle Seahawks', 'abbreviation' => 'SEA']],
            ['homeAway' => 'away', 'team' => ['displayName' => 'Denver Broncos', 'abbreviation' => 'DEN']],
        ]]]],
    ];
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->once()->with('401999999')->andReturn($payload);
    $stats = new class
    {
        public function execute(mixed ...$args): int
        {
            return 0;
        }
    };

    $result = (new SyncGameDetails($service, $stats, $stats, $stats))->execute('401999999');
    expect($result['game_updated'])->toBeTrue()
        ->and($game->fresh()->venue_city)->toBe('Seattle')
        ->and($game->fresh()->venue_name)->toBe('Lumen Field')
        ->and($game->fresh()->short_name)->toBe('DEN @ SEA');

    $normalized = (new ReflectionMethod(SyncGames::class, 'normalizeGamePayload'))->invoke(new SyncGames($service), $payload);
    expect(data_get($normalized, 'competitions.0.venue.fullName'))->toBe('Lumen Field');
});
