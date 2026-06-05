<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

test('api v1 usage report summarizes legacy product route hits', function () {
    $path = storage_path('logs/api-v1-usage-report-test.log');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, implode(PHP_EOL, [
        '[2026-06-05 12:00:00] production.INFO: api.v1.usage {"method":"GET","path":"api/v1/cbb-brackets","route_name":"cbb-brackets.index","user_id":1}',
        '[2026-06-05 12:05:00] production.INFO: api.v1.usage {"method":"GET","path":"api/v1/cbb-brackets","route_name":"cbb-brackets.index","user_id":2}',
        '[2026-06-05 12:10:00] production.INFO: api.v1.usage {"method":"POST","path":"api/v1/user-bets","route_name":"user-bets.store","user_id":1}',
    ]));

    $exitCode = Artisan::call('api:v1-usage-report', ['--path' => [$path]]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('api/v1/cbb-brackets')
        ->and($output)->toContain('/api/v2/cbb-brackets')
        ->and($output)->toContain('cbb-brackets.index')
        ->and($output)->toContain('api/v1/user-bets')
        ->and($output)->toContain('/api/v2/user-bets')
        ->and($output)->toContain('user-bets.store');
});

test('api v1 usage report can output json', function () {
    $path = storage_path('logs/api-v1-usage-report-json-test.log');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, implode(PHP_EOL, [
        '[2026-06-05 12:00:00] production.INFO: api.v1.usage {"method":"GET","path":"api/v1/groups","route_name":"groups.index","user_id":null}',
        '[2026-06-05 12:02:00] production.INFO: api.v1.usage {"method":"GET","path":"api/v1/mlb/team-metrics","route_name":"mlb.team-metrics.index","user_id":1}',
        '[2026-06-05 12:03:00] production.INFO: api.v1.usage {"method":"GET","path":"api/v1/mlb/games/99/player-stats","route_name":"mlb.games.player-stats","user_id":1}',
    ]));

    $exitCode = Artisan::call('api:v1-usage-report', ['--path' => [$path], '--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('"path": "api/v1/groups"')
        ->and($output)->toContain('"replacement_path": "/api/v2/groups"')
        ->and($output)->toContain('"replacement_path": "/api/v2/sports/mlb/metrics/teams"')
        ->and($output)->toContain('"replacement_path": "/api/v2/sports/mlb/stats/player?game_id=99"')
        ->and($output)->toContain('"unique_users"');
});

test('api v1 usage report handles empty logs', function () {
    $path = storage_path('logs/api-v1-usage-report-empty-test.log');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '[2026-06-05 12:00:00] production.INFO: something else []');

    $this->artisan('api:v1-usage-report', ['--path' => [$path]])
        ->expectsOutput('No api.v1.usage entries found.')
        ->assertExitCode(0);
});

test('api v1 auth usage report summarizes legacy auth route hits', function () {
    $path = storage_path('logs/api-v1-auth-usage-report-test.log');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, implode(PHP_EOL, [
        '[2026-06-05 12:00:00] production.INFO: api.v1.auth.usage {"method":"POST","path":"api/v1/auth/login","route_name":"auth.login","user_id":null}',
        '[2026-06-05 12:05:00] production.INFO: api.v1.auth.usage {"method":"GET","path":"api/v1/auth/me","route_name":"auth.me","user_id":1}',
        '[2026-06-05 12:10:00] production.INFO: api.v1.usage {"method":"GET","path":"api/v1/user-bets","route_name":"user-bets.index","user_id":1}',
    ]));

    $exitCode = Artisan::call('api:v1-auth-usage-report', ['--path' => [$path], '--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('"path": "api/v1/auth/login"')
        ->and($output)->toContain('"path": "api/v1/auth/me"')
        ->and($output)->toContain('"replacement_path": "/api/v2/auth/login"')
        ->and($output)->toContain('"replacement_path": "/api/v2/auth/me"')
        ->and($output)->not->toContain('api/v1/user-bets');
});

test('api v1 auth usage report handles empty logs', function () {
    $path = storage_path('logs/api-v1-auth-usage-report-empty-test.log');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '[2026-06-05 12:00:00] production.INFO: something else []');

    $this->artisan('api:v1-auth-usage-report', ['--path' => [$path]])
        ->expectsOutput('No api.v1.auth.usage entries found.')
        ->assertExitCode(0);
});
