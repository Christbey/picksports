<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

it('refuses retired NFL imports before resolving a database or reading input files', function (string $environment, string $command, array $arguments) {
    config(['app.env' => $environment, 'database.default' => 'mysql',
        'database.connections.mysql.host' => 'unreachable.example.invalid']);
    DB::shouldReceive('connection')->never();
    DB::shouldReceive('purge')->never();
    DB::shouldReceive('selectOne')->never();
    DB::shouldReceive('statement')->never();
    File::shouldReceive('exists')->never();
    File::shouldReceive('get')->never();
    Process::fake();
    $this->artisan($command, $arguments)->expectsOutputToContain($command.' is retired.')->assertExitCode(Command::FAILURE);
    Process::assertNothingRan();
})->with(['local', 'testing', 'production'])->with([
    'CSV replacement' => ['nfl:import-csv-data', []],
    'CSV append' => ['nfl:import-csv-data', ['--append' => true, '--chunk' => 17]],
    'game JSON' => ['nfl:import-games-from-json', ['file' => '/synthetic/must-not-read.json']],
    'player-data JSON' => ['nfl:import-player-data-json', ['--chunk' => 17, '--player-data' => '/synthetic/must-not-read.json']],
    'line-score JSON' => ['nfl:import-line-scores', ['file' => '/synthetic/must-not-read.json']],
]);
