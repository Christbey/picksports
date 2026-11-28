<?php

use App\Jobs\ESPN\CBB\FetchPlayers;
use App\Jobs\ESPN\NFL\FetchTeamDepthCharts;
use App\Models\CBB\Team;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('freezes an implicit depth chart season before queue serialization across a year boundary', function (string $class) {
    $this->travelTo(Carbon::parse('2026-12-31 23:59:00', 'America/Chicago'));
    $job = new $class('22');
    $serialized = serialize($job);
    $this->travelTo(Carbon::parse('2027-01-01 00:01:00', 'America/Chicago'));
    expect(unserialize($serialized)->season)->toBe(2026);
})->with([
    FetchTeamDepthCharts::class,
    App\Jobs\ESPN\NBA\FetchTeamDepthCharts::class,
    App\Jobs\ESPN\MLB\FetchTeamDepthCharts::class,
]);

it('retains an explicit historical depth chart season', function (string $class) {
    expect((new $class('22', 2024))->season)->toBe(2024);
})->with([
    FetchTeamDepthCharts::class,
    App\Jobs\ESPN\NBA\FetchTeamDepthCharts::class,
    App\Jobs\ESPN\MLB\FetchTeamDepthCharts::class,
]);

it('preserves the selected queue and connection for each bounded college roster child', function () {
    Http::preventStrayRequests();
    Queue::fake();
    Team::factory()->create(['espn_id' => '22']);
    Team::factory()->create(['espn_id' => '23']);
    Team::factory()->create(['espn_id' => '']);
    $job = (new FetchPlayers)->onConnection('isolated-import')->onQueue('custom-sync');
    $job->handle();

    Queue::assertPushed(FetchPlayers::class, 2);
    Queue::assertPushed(FetchPlayers::class, fn ($child) => $child->teamEspnId === '22'
        && $child->connection === 'isolated-import' && $child->queue === 'custom-sync');
    Queue::assertPushed(FetchPlayers::class, fn ($child) => $child->teamEspnId === '23'
        && $child->connection === 'isolated-import' && $child->queue === 'custom-sync');
    Http::assertNothingSent();
});
