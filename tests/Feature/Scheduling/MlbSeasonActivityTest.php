<?php

use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Services\Validation\HeartbeatHealthService;
use App\Support\MlbSeasonActivity;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'America/Chicago')));
afterEach(fn () => $this->travelBack());

function assertMlbSeasonConsumers(bool $active): void
{
    $checks = collect(app(HeartbeatHealthService::class)->evaluate(['mlb']));
    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'MLB: Sync Schedules');
    expect(MlbSeasonActivity::isActive())->toBe($active)
        ->and(data_get($checks->firstWhere('check_type', 'heartbeat_sync'), 'metadata.in_season'))->toBe($active)
        ->and($event)->not->toBeNull()
        ->and($event->filtersPass(app()))->toBe($active);
}

/** Store date-only values as the production MySQL DATE column does. */
function createMlbSeasonActivityGame(int $offset, int $season, string $status, string $seasonType): void
{
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'season' => $season, 'season_type' => $seasonType, 'status' => $status]);
    Game::query()->whereKey($game->id)->update(['game_date' => now()->addDays($offset)->toDateString()]);
}

it('keeps MLB scheduling and heartbeat health off season in November without nearby postseason games', function () {
    assertMlbSeasonConsumers(false);
    $live = collect(app(HeartbeatHealthService::class)->evaluate(['mlb']))->firstWhere('check_type', 'heartbeat_live_scoreboard');
    expect($live['status'])->toBe('passing')->and($live['metadata']['in_season'])->toBeFalse();
});

it('activates both MLB consumers for nearby postseason games within the production recovery window', function (int $offset, string $status, string $seasonType) {
    createMlbSeasonActivityGame($offset, 2026, $status, $seasonType);

    assertMlbSeasonConsumers(true);
})->with([
    [-2, 'STATUS_FINAL', '3'],
    [3, 'STATUS_SCHEDULED', '3'],
    [0, 'STATUS_IN_PROGRESS', 'postseason'],
    [0, 'STATUS_DELAYED', 'Post Season'],
]);

it('does not activate MLB for unrelated or out of window November games', function (int $offset, int $season, string $status, string $seasonType) {
    createMlbSeasonActivityGame($offset, $season, $status, $seasonType);

    assertMlbSeasonConsumers(false);
})->with([
    [-3, 2026, 'STATUS_FINAL', '3'],
    [4, 2026, 'STATUS_SCHEDULED', '3'],
    [0, 2025, 'STATUS_SCHEDULED', '3'],
    [0, 2026, 'STATUS_CANCELED', '3'],
    [0, 2026, 'STATUS_SCHEDULED', '2'],
]);

it('honors configured MLB active months outside November without requiring postseason games', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00', 'America/Chicago'));
    assertMlbSeasonConsumers(false);
    config(['healthchecks.season_months.mlb' => [12]]);
    assertMlbSeasonConsumers(true);
});
