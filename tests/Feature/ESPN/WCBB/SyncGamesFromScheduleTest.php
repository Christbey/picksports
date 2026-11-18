<?php

use App\Actions\ESPN\WCBB\SyncGamesFromSchedule;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;
use App\Services\ESPN\WCBB\EspnService;
use App\Services\SportsAssetStorage;

beforeEach(function () {
    $this->scheduleEvent = [
        'id' => '401999301',
        'date' => '2026-11-20T23:00:00Z',
        'season' => ['year' => 2027, 'type' => 2],
        'status' => ['type' => ['name' => 'STATUS_SCHEDULED']],
        'competitions' => [[
            'competitors' => [
                ['homeAway' => 'away', 'team' => ['id' => '-2', 'location' => 'TBD', 'name' => 'TBD', 'abbreviation' => 'TBD']],
                ['homeAway' => 'home', 'team' => ['id' => '123', 'location' => 'Home School', 'name' => 'Home Mascot', 'abbreviation' => 'HOME']],
            ],
        ]],
    ];
    $storage = Mockery::mock(SportsAssetStorage::class);
    $storage->shouldReceive('mirrorTeamLogo')->andReturnNull();
    $this->app->instance(SportsAssetStorage::class, $storage);
});

it('keeps an unresolved opponent off the team table and initializes its schedule service', function () {
    Team::factory()->create(['espn_id' => '-1', 'abbreviation' => 'TBD']);
    $resolvedEvent = $this->scheduleEvent;
    $resolvedEvent['id'] = '401999302';
    $resolvedEvent['competitions'][0]['competitors'][0]['team'] = [
        'id' => '456', 'location' => 'Away School', 'name' => 'Away Mascot', 'abbreviation' => 'AWAY',
    ];
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getSchedule')->with('123', 2027)->andReturn(['events' => [$this->scheduleEvent, $resolvedEvent]]);
    $this->app->instance(EspnService::class, $service);

    expect(app(SyncGamesFromSchedule::class)->execute('123', 2027))->toBe(1);

    expect(Game::query()->where('espn_event_id', '401999301')->exists())->toBeFalse()
        ->and(Game::query()->where('espn_event_id', '401999302')->firstOrFail()->homeTeam->school)->toBe('Home School')
        ->and(Team::query()->where('espn_id', '-2')->exists())->toBeFalse();
});

it('resolves competitors by side and retains distinct identities with colliding provider abbreviations', function () {
    $this->scheduleEvent['competitions'][0]['competitors'][0]['team'] = [
        'id' => '456', 'location' => 'Away School', 'name' => 'Away Mascot', 'abbreviation' => 'HOME',
    ];
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getSchedule')->with('123', 2027)->twice()->andReturn(['events' => [$this->scheduleEvent]]);
    $this->app->instance(EspnService::class, $service);
    $action = app(SyncGamesFromSchedule::class);

    expect($action->execute('123', 2027))->toBe(1)
        ->and($action->execute('123', 2027))->toBe(1);

    $game = Game::query()->where('espn_event_id', '401999301')->firstOrFail();
    expect($game->homeTeam->espn_id)->toBe('123')
        ->and($game->awayTeam->espn_id)->toBe('456')
        ->and($game->awayTeam->school)->toBe('Away School')
        ->and($game->awayTeam->abbreviation)->toBe('E'.substr(hash('sha256', '456|0'), 0, 9))
        ->and(Team::query()->count())->toBe(2);
});

it('bounds generated abbreviations to the production column length and avoids existing fallback collisions', function () {
    $longEspnId = '1234567890123';
    $occupiedAbbreviation = 'E'.substr(hash('sha256', $longEspnId.'|0'), 0, 9);
    Team::factory()->create(['abbreviation' => $occupiedAbbreviation]);
    Team::factory()->create(['abbreviation' => 'HOME']);
    $this->scheduleEvent['competitions'][0]['competitors'][0]['team'] = [
        'id' => $longEspnId, 'location' => 'Away School', 'name' => 'Away Mascot', 'abbreviation' => 'HOME',
    ];
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getSchedule')->with('123', 2027)->twice()->andReturn(['events' => [$this->scheduleEvent]]);
    $this->app->instance(EspnService::class, $service);
    $action = app(SyncGamesFromSchedule::class);

    expect($action->execute('123', 2027))->toBe(1);
    $team = Team::query()->where('espn_id', $longEspnId)->firstOrFail();
    expect(strlen($team->abbreviation))->toBeLessThanOrEqual(10)
        ->and($team->abbreviation)->toBe('E'.substr(hash('sha256', $longEspnId.'|1'), 0, 9))
        ->and($action->execute('123', 2027))->toBe(1)
        ->and($team->fresh()->abbreviation)->toBe($team->abbreviation);
});
