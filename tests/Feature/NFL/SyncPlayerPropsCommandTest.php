<?php

use App\Actions\OddsApi\NFL\SyncPlayerPropsForGames;
use App\Console\Commands\NFL\SyncPlayerPropsCommand;
use Symfony\Component\Console\Tester\CommandTester;

test('daily NFL prop sync chains deterministic analysis and propagates its result without AI narratives', function (int $exitCode) {
    $this->mock(SyncPlayerPropsForGames::class)->shouldReceive('execute')
        ->once()->with(null, 'americanfootball_nfl')->andReturn(10);
    $command = Mockery::mock(SyncPlayerPropsCommand::class)->makePartial();
    $command->__construct();
    $command->setLaravel(app());
    $command->shouldReceive('call')->once()->with('sports:analyze-player-props', [
        '--sport' => 'nfl', '--only-missing' => true,
    ])->andReturn($exitCode);
    $tester = new CommandTester($command);
    expect($tester->execute(['--odds-sport' => 'americanfootball_nfl']))->toBe($exitCode);
})->with([0, 1]);
