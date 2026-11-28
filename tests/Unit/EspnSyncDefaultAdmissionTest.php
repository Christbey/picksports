<?php

use App\Console\Commands\ESPN\MLB\SyncSchedulesCommand as MlbSchedules;
use App\Console\Commands\ESPN\WCBB\SyncSchedulesCommand as WcbbSchedules;
use App\Jobs\ESPN\MLB\FetchGames;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

uses(TestCase::class);

trait CapturesEspnScheduleTestSeason
{
    public array $syntheticSeasons = [];

    protected function executeTeamScheduleSync(string $teamEspnId, int $season): int
    {
        $this->syntheticSeasons[] = $season;

        return 0;
    }
}

function syntheticEspnScheduleCommand(string $sport): MlbSchedules|WcbbSchedules
{
    return $sport === 'mlb'
        ? new class extends MlbSchedules
        {
            use CapturesEspnScheduleTestSeason;
        }
    : new class extends WcbbSchedules
    {
        use CapturesEspnScheduleTestSeason;
    };
}

it('uses the configured schedule season and preserves an explicit override without fetching', function (string $sport) {
    config([$sport.'.season.default' => 2031]);
    $command = syntheticEspnScheduleCommand($sport);
    $command->setLaravel($this->app);
    $tester = new CommandTester($command);
    expect($tester->execute(['--team' => 'synthetic-team']))->toBe(0);
    expect($tester->execute(['--team' => 'synthetic-team', '--season' => 2032]))->toBe(0);
    expect($command->syntheticSeasons)->toBe([2031, 2032]);
})->with(['mlb', 'wcbb']);

it('validates a missing schedule configuration only when that command executes', function () {
    config(['mlb.season.default' => null]);
    $command = syntheticEspnScheduleCommand('mlb');
    $command->setLaravel($this->app);
    $tester = new CommandTester($command);
    expect(fn () => $tester->execute(['--team' => 'synthetic-team']))
        ->toThrow(LogicException::class, 'configured season');
    expect($command->syntheticSeasons)->toBe([]);
});

it('dispatches regular-season MLB games by default while preserving explicit spring training', function () {
    Bus::fake([FetchGames::class]);
    $this->artisan('espn:sync-mlb-games', ['season' => 2026, 'week' => 1])->assertSuccessful();
    Bus::assertDispatched(FetchGames::class, fn (FetchGames $job): bool => $job->season === 2026 && $job->seasonType === 2 && $job->week === 1);
    $this->artisan('espn:sync-mlb-games', ['season' => 2026, 'week' => 2, 'seasonType' => 1])->assertSuccessful();
    Bus::assertDispatched(FetchGames::class, fn (FetchGames $job): bool => $job->seasonType === 1 && $job->week === 2);
});

it('refuses a malformed explicit season before fetching any team schedule', function (string $season) {
    $command = syntheticEspnScheduleCommand('mlb');
    $command->setLaravel($this->app);
    $tester = new CommandTester($command);
    expect(fn () => $tester->execute(['--team' => 'synthetic-team', '--season' => $season]))
        ->toThrow(LogicException::class, 'season');
    expect($command->syntheticSeasons)->toBe([]);
})->with(['2031.5', 'invalid']);
