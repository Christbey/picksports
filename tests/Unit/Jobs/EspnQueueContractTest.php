<?php

use App\Jobs\ESPN\BulkEspnJob;
use App\Jobs\ESPN\CBB\FetchPlayers;
use App\Jobs\ESPN\CBB\FetchTeams;
use App\Jobs\ESPN\CBB\FetchTeamSchedule;
use App\Jobs\ESPN\CFB\FetchGameDetails;
use App\Jobs\ESPN\CFB\FetchPlayerInjuries;
use App\Jobs\ESPN\MLB\FetchTeamDepthCharts;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

it('isolates bulk ESPN imports on the sync queue with bounded execution policy', function (string $jobClass, array $arguments = []) {
    $job = new $jobClass(...$arguments);

    expect($job)->toBeInstanceOf(BulkEspnJob::class)
        ->and($job->queue)->toBe('sync')
        ->and($job->timeout)->toBe(1800)
        ->and($job->tries)->toBe(3)
        ->and($job->maxExceptions)->toBe(3)
        ->and($job->backoff)->toBe([30, 120, 300]);
})->with([
    'CBB teams' => [FetchTeams::class],
    'CFB teams' => [App\Jobs\ESPN\CFB\FetchTeams::class],
    'MLB teams' => [App\Jobs\ESPN\MLB\FetchTeams::class],
    'NBA teams' => [App\Jobs\ESPN\NBA\FetchTeams::class],
    'NFL teams' => [App\Jobs\ESPN\NFL\FetchTeams::class],
    'WCBB teams' => [App\Jobs\ESPN\WCBB\FetchTeams::class],
    'WNBA teams' => [App\Jobs\ESPN\WNBA\FetchTeams::class],
    'CBB players' => [FetchPlayers::class, ['1']],
    'CFB players' => [App\Jobs\ESPN\CFB\FetchPlayers::class, ['1']],
    'CFB league injury import' => [FetchPlayerInjuries::class],
    'MLB players' => [App\Jobs\ESPN\MLB\FetchPlayers::class, ['1']],
    'NBA players' => [App\Jobs\ESPN\NBA\FetchPlayers::class, ['1']],
    'NFL players' => [App\Jobs\ESPN\NFL\FetchPlayers::class, ['1']],
    'WCBB players' => [App\Jobs\ESPN\WCBB\FetchPlayers::class, ['1']],
    'WNBA players' => [App\Jobs\ESPN\WNBA\FetchPlayers::class, ['1']],
    'CBB team schedule' => [FetchTeamSchedule::class, ['1']],
    'CFB team schedule' => [App\Jobs\ESPN\CFB\FetchTeamSchedule::class, ['1', 2026]],
    'MLB depth chart' => [FetchTeamDepthCharts::class, ['1', 2026]],
    'NBA depth chart' => [App\Jobs\ESPN\NBA\FetchTeamDepthCharts::class, ['1', 2026]],
    'NFL depth chart' => [App\Jobs\ESPN\NFL\FetchTeamDepthCharts::class, ['1', 2026]],
]);

it('keeps latency-sensitive game details on the default queue', function () {
    $job = new FetchGameDetails('event-1');

    expect($job->queue)->toBeNull();
});

it('defines a production worker contract for every application queue', function () {
    $contracts = config('queue.worker_contracts');

    expect($contracts)->toHaveKeys(['default', 'sync'])
        ->and($contracts['default']['timeout'])->toBe(120)
        ->and($contracts['sync']['timeout'])->toBe(1800)
        ->and($contracts['default']['max_time'])->toBe(3600)
        ->and($contracts['sync']['max_time'])->toBe(3600)
        ->and(config('queue.connections.redis.retry_after'))->toBeGreaterThan($contracts['sync']['timeout']);
});

it('keeps every ESPN job with a long timeout off the live worker', function () {
    $liveTimeout = config('queue.worker_contracts.default.timeout');
    $violations = collect(File::allFiles(app_path('Jobs/ESPN')))
        ->map(function ($file) {
            $relativePath = str_replace([app_path().DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());

            return 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
        })
        ->filter(fn (string $class): bool => class_exists($class))
        ->map(fn (string $class): ReflectionClass => new ReflectionClass($class))
        ->reject(fn (ReflectionClass $class): bool => $class->isAbstract())
        ->filter(fn (ReflectionClass $class): bool => (int) ($class->getDefaultProperties()['timeout'] ?? 0) > $liveTimeout)
        ->reject(fn (ReflectionClass $class): bool => $class->isSubclassOf(BulkEspnJob::class))
        ->map(fn (ReflectionClass $class): string => $class->getName())
        ->values()
        ->all();

    expect($violations)->toBeEmpty();
});

it('has a worker contract for every statically named application queue', function () {
    $namedQueues = collect(File::allFiles(app_path()))
        ->flatMap(function ($file): array {
            preg_match_all(
                '/(?:onQueue|allOnQueue)\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/',
                File::get($file->getPathname()),
                $matches,
            );

            return $matches[1];
        })
        ->unique()
        ->values();

    expect($namedQueues->diff(array_keys(config('queue.worker_contracts')))->all())->toBeEmpty();
});
