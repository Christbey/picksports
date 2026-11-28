<?php

use App\Console\Commands\ESPN\NFL\SyncCurrentWeekCommand;
use App\Jobs\ESPN\NFL\FetchGames;
use App\Jobs\ESPN\NFL\FetchGamesFromScoreboard;
use App\Jobs\ESPN\NFL\FetchTeams;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schedule;

it('dispatches the actual current calendar window without a guessed season or week', function (string $clock) {
    config(['app.timezone' => 'America/Chicago']);
    $this->travelTo(CarbonImmutable::parse($clock, 'America/Chicago'));
    Bus::fake();
    expect(Artisan::call('espn:sync-nfl-current', ['--skip-teams' => true]))->toBe(0);
    $dates = Bus::dispatched(FetchGamesFromScoreboard::class)->map(fn ($job) => $job->date)->all();
    $legacy = Bus::dispatched(FetchGames::class)->map(fn ($job) => ['season' => $job->season, 'season_type' => $job->seasonType, 'week' => $job->week])->all();
    $this->assertContains(now()->format('Ymd'), $dates, 'Actual legacy week jobs: '.json_encode($legacy));
    expect($dates)->toHaveCount(15)->toContain(now()->subDays(7)->format('Ymd'))->toContain(now()->addDays(7)->format('Ymd'));
    Bus::assertNotDispatched(FetchGames::class);
    Bus::assertNotDispatched(FetchTeams::class);
})->with(['January current window' => '2027-01-15 12:00:00', 'September current window' => '2026-09-10 12:00:00']);

it('can construct the command without input and retains default team refresh', function () {
    $command = new SyncCurrentWeekCommand;
    expect($command->getName())->toBe('espn:sync-nfl-current');
    $this->travelTo(CarbonImmutable::parse('2027-01-15 12:00:00', 'America/Chicago'));
    Bus::fake();
    expect(Artisan::call('espn:sync-nfl-current'))->toBe(0);
    Bus::assertDispatched(FetchTeams::class, 1);
});

it('bounds calendar fanout before any team or game dispatch', function (mixed $back, mixed $forward) {
    Bus::fake();
    expect(Artisan::call('espn:sync-nfl-current', ['--days-back' => $back, '--days-forward' => $forward]))->toBe(2);
    Bus::assertNothingDispatched();
})->with([
    'negative' => ['-1', '0'],
    'too many back dates' => ['31', '0'],
    'too many inclusive dates' => ['20', '11'],
    'exponent' => ['1e9', '0'],
    'fractional' => ['2.5', '0'],
    'empty' => ['', '0'],
]);

it('accepts exact zero and maximum calendar bounds without dispatching week jobs', function (int $back, int $forward, int $count) {
    $this->travelTo(CarbonImmutable::parse('2027-01-15 12:00:00', 'America/Chicago'));
    Bus::fake();
    expect(Artisan::call('espn:sync-nfl-current', ['--skip-teams' => true, '--days-back' => $back, '--days-forward' => $forward]))->toBe(0);
    Bus::assertDispatched(FetchGamesFromScoreboard::class, $count);
    Bus::assertNotDispatched(FetchGames::class);
    Bus::assertNotDispatched(FetchTeams::class);
})->with(['single date' => [0, 0, 1], 'maximum range' => [30, 0, 31]]);

it('retains the scheduled command identity skip option and configured month guard', function (string $clock, bool $admitted) {
    $this->travelTo(CarbonImmutable::parse($clock, 'America/Chicago'));
    Schedule::swap(new Illuminate\Console\Scheduling\Schedule('America/Chicago'));
    require base_path('routes/console.php');
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->firstWhere('description', 'NFL: Sync Current Week');
    expect($event)->not->toBeNull()->and($event->command)->toContain('espn:sync-nfl-current --skip-teams')
        ->and($event->expression)->toBe('0 8 * * *')->and($event->onOneServer)->toBeTrue()
        ->and($event->command)->toContain('ops:dispatch-scheduled')
        ->and($event->withoutOverlapping)->toBeTrue()->and($event->runInBackground)->toBeFalse()
        ->and($event->filtersPass(app()))->toBe($admitted);
})->with(['configured January' => ['2027-01-15 08:00:00', true], 'configured off-season month' => ['2027-04-15 08:00:00', false]]);
