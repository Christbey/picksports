<?php

use App\Support\BasketballSeason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('routes ending-year basketball grading through the existing Chicago season boundary', function (string $sport, string $clock, int $offset) {
    $year = (int) date('Y');
    $this->travelTo(CarbonImmutable::parse($year.'-'.$clock));
    expect(BasketballSeason::forDate())->toBe($year + $offset);
    $action = 'App\\Actions\\'.strtoupper($sport).'\\GradePredictions';
    $this->mock($action)->shouldReceive('execute')->once()->with($year + $offset)->andReturn(['graded' => 0]);
    $this->artisan($sport.':grade-predictions')->assertSuccessful();
    Http::assertNothingSent();
})->with([
    ['nba', '07-01T04:59:59Z', 0], ['nba', '07-01T05:00:00Z', 1], ['nba', '11-27T12:00:00Z', 1],
    ['wcbb', '07-01T04:59:59Z', 0], ['wcbb', '07-01T05:00:00Z', 1], ['wcbb', '11-27T12:00:00Z', 1],
    ['cbb', '07-01T04:59:59Z', 0], ['cbb', '07-01T05:00:00Z', 1], ['cbb', '11-27T12:00:00Z', 1],
]);

it('preserves an explicit grading season across basketball and calendar families', function (string $sport) {
    $this->travelTo(CarbonImmutable::parse('2026-11-27T12:00:00Z'));
    $action = 'App\\Actions\\'.strtoupper($sport).'\\GradePredictions';
    $this->mock($action)->shouldReceive('execute')->once()->with(2021)->andReturn(['graded' => 0]);
    $this->artisan($sport.':grade-predictions', ['--season' => 2021])->assertSuccessful();
    Http::assertNothingSent();
})->with(['nba', 'wcbb', 'cbb', 'wnba', 'nfl']);

it('retains the existing calendar-year routing for WNBA and NFL', function (string $sport) {
    $year = (int) date('Y');
    $this->travelTo(CarbonImmutable::parse($year.'-11-27T12:00:00Z'));
    $action = 'App\\Actions\\'.strtoupper($sport).'\\GradePredictions';
    $this->mock($action)->shouldReceive('execute')->once()->with($year)->andReturn(['graded' => 0]);
    $this->artisan($sport.':grade-predictions')->assertSuccessful();
    Http::assertNothingSent();
})->with(['wnba', 'nfl']);
