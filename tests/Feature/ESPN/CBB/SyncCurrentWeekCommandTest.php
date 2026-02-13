<?php

use App\Jobs\ESPN\CBB\FetchGamesFromScoreboard;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

it('dispatches FetchGamesFromScoreboard jobs for each day in range', function () {
    Queue::fake();

    artisan('espn:sync-cbb-current --days-back=2 --days-forward=2');

    // Should dispatch FetchGamesFromScoreboard for 5 days total (2 back + today + 2 forward)
    Queue::assertPushed(FetchGamesFromScoreboard::class, 5);
});

it('uses default days-back and days-forward values', function () {
    Queue::fake();

    artisan('espn:sync-cbb-current');

    // Default is 7 days back + 7 days forward + today = 15 days
    Queue::assertPushed(FetchGamesFromScoreboard::class, 15);
});

it('dispatches correct number of jobs based on date range', function () {
    Queue::fake();

    artisan('espn:sync-cbb-current --days-back=1 --days-forward=1');

    Queue::assertPushed(FetchGamesFromScoreboard::class, 3); // yesterday + today + tomorrow
});
