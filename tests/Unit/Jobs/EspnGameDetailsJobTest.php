<?php

use App\Jobs\ESPN\NBA\FetchGameDetails;

it('expires orphaned uniqueness locks for recurring ESPN detail jobs', function (string $jobClass) {
    $job = new $jobClass('test-event');

    expect($job->uniqueFor)->toBe(3600)
        ->and($job->uniqueId())->toBe('test-event');
})->with([
    FetchGameDetails::class,
    App\Jobs\ESPN\NFL\FetchGameDetails::class,
    App\Jobs\ESPN\MLB\FetchGameDetails::class,
    App\Jobs\ESPN\WNBA\FetchGameDetails::class,
    App\Jobs\ESPN\CBB\FetchGameDetails::class,
    App\Jobs\ESPN\CFB\FetchGameDetails::class,
    App\Jobs\ESPN\WCBB\FetchGameDetails::class,
]);
