<?php

use App\Actions\CBB\RepairTournamentStructure;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Services\CFB\Data\CfbDriveBuilder;
use App\Services\CFB\Elo\EloRebuildService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

dataset('invalid explicit operational integers', ['0', '', 'junk', '12junk', '1e2', '-1', '999999999999999999999999999999']);

beforeEach(function () {
    Http::preventStrayRequests();
    Notification::fake();
});

function fakeOperationalElo(array &$calls): void
{
    $service = Mockery::mock(EloRebuildService::class);
    $service->shouldReceive('build')->andReturnUsing(function () use (&$calls): array {
        $calls[] = 'build';

        return ['id' => 42];
    });
    $service->shouldReceive('activate')->andReturnUsing(function (int $id) use (&$calls): array {
        $calls[] = 'activate:'.$id;

        return ['id' => $id];
    });
    app()->instance(EloRebuildService::class, $service);
}

it('refuses malformed explicit activation before either Elo service operation', function (string $value) {
    $calls = [];
    fakeOperationalElo($calls);
    $exit = Artisan::call('cfb:rebuild-elo', ['--activate' => $value]);
    expect($calls)->toBe([])->and($exit)->toBe(1);
})->with('invalid explicit operational integers');

it('preserves omitted Elo build and explicit positive activation as distinct authorized operations', function () {
    $calls = [];
    fakeOperationalElo($calls);
    expect(Artisan::call('cfb:rebuild-elo'))->toBe(0)->and($calls)->toBe(['build']);
    expect(Artisan::call('cfb:rebuild-elo', ['--activate' => '42']))->toBe(0)->and($calls)->toBe(['build', 'activate:42']);
});

function fakeOperationalTournament(array &$calls): void
{
    $repair = Mockery::mock(RepairTournamentStructure::class);
    $repair->shouldReceive('execute')->andReturnUsing(function (int $season) use (&$calls): int {
        $calls[] = $season;

        return 0;
    });
    app()->instance(RepairTournamentStructure::class, $repair);
}

it('refuses malformed explicit tournament season without using or mutating its default', function (string $value) {
    config(['cbb.season.default' => 2027]);
    $calls = [];
    fakeOperationalTournament($calls);
    $exit = Artisan::call('cbb:repair-tournament-structure', ['--season' => $value]);
    expect($calls)->toBe([])->and($exit)->toBe(1);
})->with('invalid explicit operational integers');

it('preserves the configured omitted tournament season and exact positive supplied season', function () {
    config(['cbb.season.default' => 2027]);
    $calls = [];
    fakeOperationalTournament($calls);
    expect(Artisan::call('cbb:repair-tournament-structure'))->toBe(0)->and($calls)->toBe([2027]);
    expect(Artisan::call('cbb:repair-tournament-structure', ['--season' => '1975']))->toBe(0)->and($calls)->toBe([2027, 1975]);
});

function operationalDriveFixture(array &$calls): array
{
    $games = collect([2026, 2026, 2025])->map(fn (int $season) => Game::factory()->create([
        'season' => $season, 'status' => 'STATUS_FINAL', 'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
    ]));
    $builder = Mockery::mock(CfbDriveBuilder::class);
    $builder->shouldReceive('build')->andReturnUsing(function (Game $game, bool $changed) use (&$calls): array {
        $calls[] = ['id' => $game->id, 'only_changed' => $changed];

        return ['state' => 'complete'];
    });
    app()->instance(CfbDriveBuilder::class, $builder);

    return $games->all();
}

it('refuses malformed game selectors before a valid season can broaden drive work', function (string $value) {
    $calls = [];
    operationalDriveFixture($calls);
    $exit = Artisan::call('cfb:build-drives', ['--season' => '2026', '--game' => $value]);
    expect($calls)->toBe([])->and($exit)->toBe(1);
})->with('invalid explicit operational integers');

it('refuses malformed supplied season even when a valid drive game is present', function (string $value) {
    $calls = [];
    [$game] = operationalDriveFixture($calls);
    $exit = Artisan::call('cfb:build-drives', ['--season' => $value, '--game' => (string) $game->id]);
    expect($calls)->toBe([])->and($exit)->toBe(1);
})->with(['0', 'junk']);

it('preserves exact game only season only and intersection drive scopes with only changed', function (string $scope) {
    $calls = [];
    [$first, $second, $older] = operationalDriveFixture($calls);
    $options = match ($scope) {
        'game' => ['--game' => (string) $first->id],
        'season' => ['--season' => '2026'],
        'intersection' => ['--season' => '2026', '--game' => (string) $first->id],
    };
    expect(Artisan::call('cfb:build-drives', [...$options, '--only-changed' => true]))->toBe(0)
        ->and(array_column($calls, 'id'))->toBe($scope === 'season' ? [$first->id, $second->id] : [$first->id])
        ->and(array_unique(array_column($calls, 'only_changed')))->toBe([true]);
})->with(['game', 'season', 'intersection']);

it('preserves refusal when drive scope is entirely omitted', function () {
    $builder = Mockery::mock(CfbDriveBuilder::class);
    $builder->shouldNotReceive('build');
    app()->instance(CfbDriveBuilder::class, $builder);
    expect(Artisan::call('cfb:build-drives'))->toBe(1);
});

it('refuses an explicitly present valueless option instead of treating it as omitted', function (string $command) {
    $calls = [];
    if ($command === 'activate') {
        fakeOperationalElo($calls);
        $exit = Artisan::call('cfb:rebuild-elo', ['--activate' => null]);
    } elseif ($command === 'tournament') {
        fakeOperationalTournament($calls);
        $exit = Artisan::call('cbb:repair-tournament-structure', ['--season' => null]);
    } else {
        operationalDriveFixture($calls);
        $exit = Artisan::call('cfb:build-drives', ['--season' => '2026', '--game' => null]);
    }
    expect($calls)->toBe([])->and($exit)->toBe(1);
})->with(['activate', 'tournament', 'drives']);
