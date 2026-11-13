<?php

use App\Models\CFB\FpiRating;
use App\Models\CFB\Team;
use App\Services\CollegeFootballData\CollegeFootballDataService;
use Illuminate\Support\Facades\Http;

it('fails a required rating refresh on an empty provider response', function () {
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->with(2026)
        ->andReturn(['rows' => [], 'observed_at' => now()->toIso8601String()]);
    $this->artisan('cfb:import-fpi', ['--season' => 2026, '--require-data' => true])->assertFailed();
    $this->artisan('cfb:import-fpi', ['--season' => 2026])->assertSuccessful();
});

it('fails a required rating refresh when no returned team can be mapped', function () {
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->with(2026)
        ->andReturn(['rows' => [['team' => 'Unmapped College', 'fpi' => 20]], 'observed_at' => now()->toIso8601String()]);
    $this->artisan('cfb:import-fpi', ['--season' => 2026, '--require-data' => true])->assertFailed();
});

it('accepts unchanged numeric ratings without pretending their source timestamp advanced', function () {
    $team = Team::factory()->create(['school' => 'Test College', 'division' => 'FBS']);
    $rating = FpiRating::factory()->create(['team_id' => $team->id, 'season' => 2026,
        'week' => 3, 'fpi' => 15, 'offense' => null, 'defense' => null, 'special_teams' => null,
        'fpi_rank' => null, 'updated_at' => now()->subDays(2)]);
    $timestamp = $rating->updated_at->toIso8601String();
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->with(2026)
        ->andReturn(['rows' => [['team' => 'Test College', 'fpi' => 15]], 'observed_at' => now()->toIso8601String()]);
    $this->artisan('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-data' => true])->assertSuccessful();
    expect($rating->fresh()->updated_at->toIso8601String())->toBe($timestamp);
});

it('rejects a partially mapped provider response in complete coverage mode', function () {
    Team::factory()->create(['school' => 'Known College', 'division' => 'FBS']);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->with(2026)
        ->andReturn(['rows' => [['team' => 'Known College', 'fpi' => 10], ['team' => 'Missing College', 'fpi' => 12]], 'observed_at' => now()->toIso8601String()]);
    $this->artisan('cfb:import-fpi', ['--season' => 2026, '--require-complete' => true])
        ->expectsOutputToContain('Incomplete FPI coverage')->assertFailed();
});

it('rejects missing ratings for a known FBS team in complete coverage mode', function () {
    Team::factory()->create(['school' => 'Known College', 'division' => 'FBS']);
    Team::factory()->create(['school' => 'Absent College', 'division' => 'FBS']);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->with(2026)
        ->andReturn(['rows' => [['team' => 'Known College', 'fpi' => 10]], 'observed_at' => now()->toIso8601String()]);
    $this->artisan('cfb:import-fpi', ['--season' => 2026, '--require-complete' => true])
        ->expectsOutputToContain('Absent College')->assertFailed();
});

it('accepts a complete usable mapped FPI response', function () {
    Team::factory()->create(['school' => 'Known College', 'division' => 'FBS']);
    $this->mock(CollegeFootballDataService::class)->shouldReceive('getFpiSeasonSnapshot')->with(2026)
        ->andReturn(['rows' => [['team' => 'Known College', 'fpi' => 10]], 'observed_at' => now()->toIso8601String()]);
    $this->artisan('cfb:import-fpi', ['--season' => 2026, '--require-complete' => true])
        ->expectsOutputToContain('FPI coverage: 1/1')->assertSuccessful();
});

it('shares a provider fetch across FPI import runs and preserves the observation time for each week', function () {
    config(['services.collegefootballdata.api_key' => 'test']);
    Team::factory()->create(['school' => 'Known College', 'division' => 'FBS']);
    Http::fake(['*/ratings/fpi*' => Http::response([['team' => 'Known College', 'fpi' => 10]])]);
    $observedAt = now()->startOfSecond();
    $this->travelTo($observedAt);

    $this->artisan('cfb:import-fpi', ['--season' => 2026, '--week' => 3, '--require-complete' => true])->assertSuccessful();
    $this->travel(20)->minutes();
    $this->artisan('cfb:import-fpi', ['--season' => 2026, '--week' => 4, '--require-complete' => true])->assertSuccessful();

    Http::assertSentCount(1);
    $ratings = FpiRating::orderBy('week')->get();
    expect($ratings->pluck('week')->all())->toEqual([3, 4])
        ->and($ratings->every(fn (FpiRating $rating) => $rating->updated_at->equalTo($observedAt)))->toBeTrue()
        ->and($ratings->last()->created_at->gt($observedAt))->toBeTrue();
});
