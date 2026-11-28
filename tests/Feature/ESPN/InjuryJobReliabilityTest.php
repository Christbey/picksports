<?php

use App\Jobs\ESPN\NFL\FetchPlayerInjuries;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('exposes an unavailable team acquisition as a failed injury job', function (string $sport) {
    config(['nba.injuries.observations_enabled' => false]);
    $team = 'App\\Models\\'.$sport.'\\Team';
    $job = 'App\\Jobs\\ESPN\\'.$sport.'\\FetchPlayerInjuries';
    $team::factory()->create(['espn_id' => '22']);
    Http::fake(['*' => Http::response([])]);
    expect(fn () => (new $job('22'))->handle())->toThrow(RuntimeException::class);
})->with(['NFL', 'NBA', 'CFB', 'CBB', 'MLB', 'WNBA', 'WCBB']);

it('does not report an all-team job successful when a team source is unavailable', function (string $sport) {
    config(['nba.injuries.observations_enabled' => false]);
    $team = 'App\\Models\\'.$sport.'\\Team';
    $job = 'App\\Jobs\\ESPN\\'.$sport.'\\FetchPlayerInjuries';
    $team::factory()->create(['espn_id' => '22']);
    Http::fake(['*' => Http::response([])]);
    expect(fn () => (new $job)->handle())->toThrow(RuntimeException::class);
})->with(['NBA', 'CFB', 'MLB', 'WNBA', 'WCBB']);

it('preserves NFL parent routing while dispatching only valid team identifiers', function () {
    Queue::fake();
    Http::preventStrayRequests();
    Team::factory()->create(['espn_id' => '22']);
    Team::factory()->create(['espn_id' => '23']);
    Team::factory()->create(['espn_id' => '']);
    (new FetchPlayerInjuries)->onConnection('isolated-import')->onQueue('team-injuries')->handle();
    Queue::assertPushed(FetchPlayerInjuries::class, 2);
    foreach (['22', '23'] as $id) {
        Queue::assertPushed(FetchPlayerInjuries::class, fn ($child) => $child->teamEspnId === $id
            && $child->connection === 'isolated-import' && $child->queue === 'team-injuries');
    }
    Http::assertNothingSent();
});

it('does not interpret an explicitly empty team identifier as all-team permission', function (string $sport) {
    config(['nba.injuries.observations_enabled' => false]);
    $job = 'App\\Jobs\\ESPN\\'.$sport.'\\FetchPlayerInjuries';
    Http::preventStrayRequests();
    Queue::fake();
    expect(fn () => (new $job(''))->handle())->toThrow(RuntimeException::class);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['NFL', 'NBA', 'CFB', 'CBB', 'MLB', 'WNBA', 'WCBB']);
