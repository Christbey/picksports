<?php

use App\Models\CFB\FpiRating;
use App\Services\CollegeFootballData\Fpi\FpiSeasonCoordinator;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use Carbon\CarbonImmutable;

function activateSyntheticFpiSeasonScope(int $season = 2026): void
{
    $connection = (new FpiRating)->getConnection();
    app(FpiSeasonCoordinator::class)->activate($connection, $season,
        app(EloWriteTargetIdentity::class)->describe($connection)['fingerprint'],
        'synthetic-local-test', 'Synthetic contract only, not production activation.',
        'synthetic-no-real-writer-drain', 'synthetic-not-production-size-review', CarbonImmutable::now('UTC')->subMinute());
}
