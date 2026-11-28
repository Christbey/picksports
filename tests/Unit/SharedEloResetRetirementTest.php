<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

it('rejects unsupported destructive Elo reset before database work', function (string $sport) {
    DB::shouldReceive('connection')->never();
    $this->artisan($sport.':calculate-elo', ['--reset' => true, '--season' => 2025])
        ->expectsOutputToContain('reset')->assertExitCode(Command::FAILURE);
})->with(['nfl', 'nba', 'mlb', 'cbb', 'cfb', 'wnba', 'wcbb']);
