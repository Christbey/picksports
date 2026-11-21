<?php

use App\Jobs\Predictions\RefreshGameAnalysis;
use App\Jobs\Predictions\ReviewPredictionWorkItem;

it('bounds shared analysis jobs below the existing default worker and reservation leases', function () {
    $workerTimeout = (int) config('queue.worker_contracts.default.timeout');
    $lease = (int) config('prediction_review.lease_seconds');

    foreach ([new RefreshGameAnalysis('nba', 1), new ReviewPredictionWorkItem(1)] as $job) {
        expect($job->timeout)->toBeLessThan($workerTimeout);
    }
    expect((int) config('prediction_review.timeout_seconds'))->toBeLessThan($workerTimeout)
        ->and($workerTimeout)->toBeLessThan($lease)
        ->and($lease)->toBeLessThan((int) config('queue.connections.redis.retry_after'));
});
