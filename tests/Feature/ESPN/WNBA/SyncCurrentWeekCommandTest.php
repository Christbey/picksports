<?php

use App\Jobs\ESPN\WNBA\FetchGames;
use App\Jobs\ESPN\WNBA\FetchTeams;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

afterEach(function () {
    Carbon::setTestNow();
});

it('dispatches the current WNBA week without a negative week number', function () {
    Queue::fake();
    Carbon::setTestNow('2026-05-23 12:00:00');

    artisan('espn:sync-wnba-current')
        ->expectsOutput('Syncing WNBA games for Week 3...')
        ->assertSuccessful();

    Queue::assertPushed(FetchTeams::class);
    Queue::assertPushed(FetchGames::class, fn (FetchGames $job) => $job->season === 2026
        && $job->seasonType === 2
        && $job->week === 3);
});
