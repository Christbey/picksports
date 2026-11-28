<?php

use App\Models\NBA\Player;
use App\Models\NBA\Team;
use App\Services\SportsAssetStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->assetOriginalDispatcher = Model::getEventDispatcher();
    Model::setEventDispatcher(clone $this->assetOriginalDispatcher);
});

afterEach(function () {
    Model::setEventDispatcher($this->assetOriginalDispatcher);
});

function assetMirrorBoundaryFixture(string $kind, ?Closure $duringMirror = null): array
{
    $source = 'https://a.espncdn.com/synthetic-original.png';
    $target = 'sports-assets/nba/synthetic-mirrored.png';
    $team = Team::factory()->create(['logo_url' => $source]);
    $model = $kind === 'player' ? Player::factory()->create(['team_id' => $team->id, 'headshot_url' => $source]) : $team;
    $column = $kind === 'player' ? 'headshot_url' : 'logo_url';
    $storage = Mockery::mock(SportsAssetStorage::class);
    $method = $kind === 'player' ? 'mirrorPlayerHeadshot' : 'mirrorTeamLogo';
    $storage->shouldReceive($method)->zeroOrMoreTimes()->andReturnUsing(function () use ($duringMirror, $model, $target) {
        $duringMirror?->__invoke($model);

        return $target;
    });
    app()->instance(SportsAssetStorage::class, $storage);
    $command = $kind === 'logos' ? 'team-logos:sync' : 'sports-assets:sync';
    $arguments = ['sport' => 'nba'];
    if ($kind !== 'logos') {
        $arguments['--type'] = $kind === 'player' ? 'players' : 'teams';
    }

    return [$model, $column, $source, $target, $command, $arguments];
}

it('commits the requested mirrored path before acknowledging its update', function (string $kind) {
    [$model, $column, $source, $target, $command, $arguments] = assetMirrorBoundaryFixture($kind);
    expect(Artisan::call($command, $arguments))->toBe(0)
        ->and($model->fresh()->getRawOriginal($column))->toBe($target);
    Http::assertNothingSent();
})->with(['logos', 'teams', 'player']);

it('does not acknowledge a cancelled mirrored path update', function (string $kind) {
    [$model, $column, $source, $target, $command, $arguments] = assetMirrorBoundaryFixture($kind);
    $model::saving(fn () => false);
    $error = null;
    $exit = 0;
    try {
        $exit = Artisan::call($command, $arguments);
    } catch (RuntimeException $caught) {
        $error = $caught;
    }
    expect($model->fresh()?->getRawOriginal($column))->toBe($source)
        ->and($error !== null || $exit !== 0)->toBeTrue();
    Http::assertNothingSent();
})->with(['logos', 'teams', 'player']);

it('does not overwrite a newer asset source observed during mirror IO', function (string $kind) {
    $replacement = 'https://a.espncdn.com/synthetic-new-source.png';
    [$model, $column, $source, $target, $command, $arguments] = assetMirrorBoundaryFixture($kind, function (Model $model) use ($kind, $replacement) {
        $model->getConnection()->table($model->getTable())->where($model->getKeyName(), $model->getKey())
            ->update([$kind === 'player' ? 'headshot_url' : 'logo_url' => $replacement]);
    });
    $error = null;
    $exit = 0;
    try {
        $exit = Artisan::call($command, $arguments);
    } catch (RuntimeException $caught) {
        $error = $caught;
    }
    expect($model->fresh()?->getRawOriginal($column))->toBe($replacement)
        ->and($error !== null || $exit !== 0)->toBeTrue();
    Http::assertNothingSent();
})->with(['logos', 'teams', 'player']);

it('rolls back rewritten or deleted mirrored path acknowledgements', function (string $kind, string $event) {
    [$model, $column, $source, $target, $command, $arguments] = assetMirrorBoundaryFixture($kind);
    if ($event === 'rewrite') {
        $model::saving(function (Model $row) use ($column) {
            $row->{$column} = 'sports-assets/synthetic-unrequested.png';
        });
    } else {
        $model::saved(function (Model $row) {
            $row->getConnection()->table($row->getTable())->where($row->getKeyName(), $row->getKey())->delete();
        });
    }
    $failed = false;
    try {
        $failed = Artisan::call($command, $arguments) !== 0;
    } catch (RuntimeException) {
        $failed = true;
    }
    expect($model->fresh()?->getRawOriginal($column))->toBe($source)->and($failed)->toBeTrue();
    Http::assertNothingSent();
})->with(['logos', 'teams', 'player'])->with(['rewrite', 'delete']);

it('does not count a matching mirrored path already committed during source IO twice', function (string $kind) {
    [$model, $column, $source, $target, $command, $arguments] = assetMirrorBoundaryFixture($kind, function (Model $model) use ($kind) {
        $model->getConnection()->table($model->getTable())->where($model->getKeyName(), $model->getKey())
            ->update([$kind === 'player' ? 'headshot_url' : 'logo_url' => 'sports-assets/nba/synthetic-mirrored.png']);
    });
    expect(Artisan::call($command, $arguments))->toBe(0)->and($model->fresh()->getRawOriginal($column))->toBe($target);
    expect(Artisan::output())->toContain($kind === 'logos' ? 'updated 0 team logo records' : 'updated 0 teams and 0 players');
    Http::assertNothingSent();
})->with(['logos', 'teams', 'player']);

it('refuses explicitly empty sport selectors instead of mirroring every sport', function (string $kind, string $selector) {
    $calls = 0;
    [$model, $column, $source, $target, $command, $arguments] = assetMirrorBoundaryFixture($kind, function () use (&$calls) {
        $calls++;
    });
    $exit = Artisan::call($command, [...$arguments, 'sport' => $selector]);
    expect([$exit, $calls, $model->fresh()->getRawOriginal($column)])->toBe([1, 0, $source]);
    Http::assertNothingSent();
})->with(['logos', 'teams', 'player'])->with(['', ' ']);

it('preserves intentional omitted sport mirroring', function (string $kind) {
    [$model, $column, $source, $target, $command, $arguments] = assetMirrorBoundaryFixture($kind);
    unset($arguments['sport']);
    expect(Artisan::call($command, $arguments))->toBe(0)
        ->and($model->fresh()->getRawOriginal($column))->toBe($target);
    Http::assertNothingSent();
})->with(['logos', 'teams', 'player']);

it('refuses a changed player team owner during source IO', function () {
    $newTeam = Team::factory()->create();
    [$model, $column, $source, $target, $command, $arguments] = assetMirrorBoundaryFixture('player', function (Model $model) use ($newTeam) {
        $model->getConnection()->table($model->getTable())->where($model->getKeyName(), $model->getKey())
            ->update(['team_id' => $newTeam->id]);
    });
    $failed = false;
    try {
        $failed = Artisan::call($command, $arguments) !== 0;
    } catch (RuntimeException) {
        $failed = true;
    }
    expect($model->fresh()->getRawOriginal($column))->toBe($source)
        ->and($model->fresh()->team_id)->toBe($newTeam->id)->and($failed)->toBeTrue();
    Http::assertNothingSent();
});

it('refuses a changed provider identity during mirror IO', function (string $kind) {
    [$model, $column, $source, $target, $command, $arguments] = assetMirrorBoundaryFixture($kind, function (Model $model) {
        $model->getConnection()->table($model->getTable())->where($model->getKeyName(), $model->getKey())
            ->update(['espn_id' => 'changed-provider']);
    });
    $failed = false;
    try {
        $failed = Artisan::call($command, $arguments) !== 0;
    } catch (RuntimeException) {
        $failed = true;
    }
    expect($model->fresh()->getRawOriginal($column))->toBe($source)
        ->and((string) $model->fresh()->getRawOriginal('espn_id'))->toBe('changed-provider')->and($failed)->toBeTrue();
    Http::assertNothingSent();
})->with(['logos', 'teams', 'player']);
