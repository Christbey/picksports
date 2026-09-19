<?php

use App\Services\CollegeFootballData\CollegeFootballDataService;

it('fails a required rating refresh on an empty provider response', function () {
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiRatings')->with(2026)->andReturn([]);
    $this->artisan('cfb:import-fpi', ['--season' => 2026, '--require-data' => true])->assertFailed();
    $this->artisan('cfb:import-fpi', ['--season' => 2026])->assertSuccessful();
});

it('fails a required rating refresh when no returned team can be mapped', function () {
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiRatings')->with(2026)
        ->andReturn([['team' => 'Unmapped College', 'fpi' => 20]]);
    $this->artisan('cfb:import-fpi', ['--season' => 2026, '--require-data' => true])->assertFailed();
});
