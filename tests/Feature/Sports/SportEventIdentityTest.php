<?php

use App\Models\CBB\Game;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Team as NbaTeam;
use App\Models\NFL\Game as NflGame;
use App\Models\NFL\Team as NflTeam;
use App\Models\SportEvent;
use App\Models\SportEventProviderMapping;
use App\Services\Sports\Exceptions\SportEventIdentityConflict;
use App\Services\Sports\SportEventIdentitySynchronizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

it('exposes canonical belongs-to relationships on every sport detail model', function (string $gameModel) {
    $relation = (new $gameModel)->sportEvent();

    expect($relation->getForeignKeyName())->toBe('sport_event_id')
        ->and($relation->getOwnerKeyName())->toBe('id');
})->with([
    Game::class,
    App\Models\CFB\Game::class,
    App\Models\MLB\Game::class,
    NbaGame::class,
    NflGame::class,
    App\Models\WCBB\Game::class,
    App\Models\WNBA\Game::class,
]);

it('gives canonical events stable ulid public ids and explicit detail relationships', function () {
    $event = SportEvent::factory()->create(['sport' => 'nba']);
    $mapping = SportEventProviderMapping::factory()
        ->for($event)
        ->create(['provider_event_id' => '401810001']);
    $game = NbaGame::factory()->create([
        'sport_event_id' => $event->getKey(),
        'home_team_id' => NbaTeam::factory(),
        'away_team_id' => NbaTeam::factory(),
    ]);

    expect(Str::isUlid($event->public_id))->toBeTrue()
        ->and($event->getKey())->toBeInt()
        ->and($event->getRouteKeyName())->toBe('public_id')
        ->and($event->getRouteKey())->toBe($event->public_id)
        ->and($mapping->sportEvent->is($event))->toBeTrue()
        ->and($event->providerMappings->first()->is($mapping))->toBeTrue()
        ->and($game->sportEvent->is($event))->toBeTrue()
        ->and($event->nbaGame->is($game))->toBeTrue();
});

it('backfills one canonical event with every known provider identity idempotently', function () {
    $game = NflGame::factory()->create([
        'home_team_id' => NflTeam::factory(),
        'away_team_id' => NflTeam::factory(),
        'espn_event_id' => '401810101',
        'espn_uid' => 's:20~l:28~e:401810101',
        'odds_api_event_id' => 'odds-event-101',
        'nflverse_game_id' => '2026_01_BUF_KC',
        'game_date' => '2026-09-13',
        'game_time' => '20:20:00',
    ]);

    expect(Artisan::call('sports:backfill-event-identities', [
        '--sport' => ['nfl'],
        '--chunk' => 1,
    ]))->toBe(0);

    $game->refresh();
    $event = $game->sportEvent;

    expect($event)->not->toBeNull()
        ->and($event->sport)->toBe('nfl')
        ->and($event->starts_at?->utc()->toIso8601String())->toBe('2026-09-13T20:20:00+00:00')
        ->and($event->providerMappings()->count())->toBe(3)
        ->and($event->providerMappings()->where('provider', 'espn')->value('provider_uid'))
        ->toBe('s:20~l:28~e:401810101');

    expect(Artisan::call('sports:backfill-event-identities', [
        '--sport' => ['nfl'],
        '--chunk' => 1,
    ]))->toBe(0)
        ->and(SportEvent::query()->count())->toBe(1)
        ->and(SportEventProviderMapping::query()->count())->toBe(3)
        ->and($game->fresh()->sport_event_id)->toBe($event->getKey());
});

it('supports a write-free dry run', function () {
    NflGame::factory()->create([
        'home_team_id' => NflTeam::factory(),
        'away_team_id' => NflTeam::factory(),
        'espn_event_id' => '401810102',
    ]);

    expect(Artisan::call('sports:backfill-event-identities', [
        '--sport' => ['nfl'],
        '--dry-run' => true,
    ]))->toBe(0)
        ->and(Artisan::output())->toContain('Events that would be created')
        ->and(SportEvent::query()->count())->toBe(0)
        ->and(SportEventProviderMapping::query()->count())->toBe(0)
        ->and(NflGame::query()->value('sport_event_id'))->toBeNull();
});

it('detects duplicate provider claims during a write-free dry run', function () {
    foreach (['401810201', '401810202'] as $espnEventId) {
        NflGame::factory()->create([
            'home_team_id' => NflTeam::factory(),
            'away_team_id' => NflTeam::factory(),
            'espn_event_id' => $espnEventId,
            'odds_api_event_id' => 'shared-odds-event-201',
        ]);
    }

    expect(Artisan::call('sports:backfill-event-identities', [
        '--sport' => ['nfl'],
        '--dry-run' => true,
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('Conflicts skipped', '1')
        ->and(SportEvent::query()->count())->toBe(0)
        ->and(SportEventProviderMapping::query()->count())->toBe(0)
        ->and(NflGame::query()->whereNotNull('sport_event_id')->count())->toBe(0);
});

it('leaves a duplicate provider claim unlinked instead of violating the one-to-one event constraint', function () {
    $event = SportEvent::factory()->create(['sport' => 'nfl']);
    SportEventProviderMapping::factory()->for($event)->create([
        'provider' => 'odds_api',
        'provider_event_id' => 'shared-odds-event-202',
        'provider_uid' => null,
    ]);
    NflGame::factory()->create([
        'sport_event_id' => $event->getKey(),
        'home_team_id' => NflTeam::factory(),
        'away_team_id' => NflTeam::factory(),
        'espn_event_id' => '401810203',
        'odds_api_event_id' => 'shared-odds-event-202',
    ]);
    $duplicate = NflGame::factory()->create([
        'home_team_id' => NflTeam::factory(),
        'away_team_id' => NflTeam::factory(),
        'espn_event_id' => '401810204',
        'odds_api_event_id' => 'shared-odds-event-202',
    ]);

    expect(Artisan::call('sports:backfill-event-identities', [
        '--sport' => ['nfl'],
    ]))->toBe(1)
        ->and($duplicate->fresh()->sport_event_id)->toBeNull()
        ->and(SportEventProviderMapping::query()
            ->where('provider', 'espn')
            ->where('provider_event_id', '401810204')
            ->exists())->toBeFalse();

    expect(fn () => app(SportEventIdentitySynchronizer::class)->sync('nfl', $duplicate))
        ->toThrow(SportEventIdentityConflict::class, 'already linked');
});

it('leaves conflicting provider identities unchanged and reports failure', function () {
    $gameEvent = SportEvent::factory()->create(['sport' => 'nfl']);
    $providerEvent = SportEvent::factory()->create(['sport' => 'nfl']);
    SportEventProviderMapping::factory()->for($providerEvent)->create([
        'provider' => 'espn',
        'provider_event_id' => '401810103',
        'provider_uid' => null,
    ]);
    $game = NflGame::factory()->create([
        'sport_event_id' => $gameEvent->getKey(),
        'home_team_id' => NflTeam::factory(),
        'away_team_id' => NflTeam::factory(),
        'espn_event_id' => '401810103',
    ]);

    expect(Artisan::call('sports:backfill-event-identities', [
        '--sport' => ['nfl'],
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('Conflicting identities were left unchanged')
        ->and($game->fresh()->sport_event_id)->toBe($gameEvent->getKey())
        ->and(SportEvent::query()->count())->toBe(2)
        ->and(SportEventProviderMapping::query()->count())->toBe(1);
});

it('rejects unsupported sports before scanning game tables', function () {
    expect(Artisan::call('sports:backfill-event-identities', [
        '--sport' => ['soccer'],
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('Unsupported sport(s): soccer.');
});
