<?php

use App\Actions\MLB\GeneratePrediction;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

it('bounds routine prediction generation to the upcoming game window', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-13 10:00:00', config('sports.business_timezone')));

    $generator = Mockery::mock(GeneratePrediction::class);
    $generator->shouldReceive('executeForAllScheduledGames')
        ->once()
        ->withArgs(fn (
            int $season,
            CarbonInterface $fromDate,
            CarbonInterface $toDate,
        ): bool => $season === 2026
            && $fromDate->toDateString() === '2026-09-13'
            && $toDate->toDateString() === '2026-09-15')
        ->andReturn(0);
    app()->instance(GeneratePrediction::class, $generator);

    $this->artisan('mlb:generate-predictions', [
        '--season' => 2026,
        '--days-forward' => 2,
    ])->assertSuccessful();
});
