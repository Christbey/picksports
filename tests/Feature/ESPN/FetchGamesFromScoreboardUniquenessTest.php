<?php

use App\Jobs\ESPN\NBA\FetchGamesFromScoreboard;
use Illuminate\Contracts\Queue\ShouldBeUnique;

it('keeps one pending scoreboard sync per sport and date', function (string $jobClass) {
    $job = new $jobClass('20260812');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueFor)->toBe(3600)
        ->and($job->uniqueId())->toBe('20260812');
})->with([
    FetchGamesFromScoreboard::class,
    App\Jobs\ESPN\NFL\FetchGamesFromScoreboard::class,
    App\Jobs\ESPN\MLB\FetchGamesFromScoreboard::class,
    App\Jobs\ESPN\CBB\FetchGamesFromScoreboard::class,
    App\Jobs\ESPN\CFB\FetchGamesFromScoreboard::class,
    App\Jobs\ESPN\WCBB\FetchGamesFromScoreboard::class,
    App\Jobs\ESPN\WNBA\FetchGamesFromScoreboard::class,
]);
