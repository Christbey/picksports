<?php

use App\Services\AdminQueueSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-11-17 08:00:00');
    config(['queue.default' => 'database', 'queue.connections.database.connection' => null,
        'queue.connections.database.table' => 'jobs', 'queue.failed.database' => config('database.default'),
        'queue.failed.table' => 'failed_jobs']);
});

afterEach(fn () => $this->travelBack());

function queueSnapshotFailure(string $payload, CarbonInterface $failedAt): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
        'payload' => $payload, 'exception' => 'Secret failure content must never be exported.', 'failed_at' => $failedAt,
    ]);
}

it('separates ready delayed and reserved work and measures ready age by queue', function () {
    $epoch = now()->getTimestamp();
    foreach ([
        ['queue' => 'default', 'available_at' => $epoch - 120, 'reserved_at' => null],
        ['queue' => 'default', 'available_at' => $epoch + 3600, 'reserved_at' => null],
        ['queue' => 'default', 'available_at' => $epoch - 7200, 'reserved_at' => $epoch - 60],
        ['queue' => 'sync', 'available_at' => $epoch - 600, 'reserved_at' => null],
    ] as $row) {
        DB::table('jobs')->insert([...$row, 'payload' => '{"secret":"not included"}', 'attempts' => 0, 'created_at' => $epoch - 10000]);
    }
    $snapshot = app(AdminQueueSnapshot::class)->snapshot(now()->subDay()->startOfDay(), now()->startOfDay(), now());
    expect($snapshot['queue']['available'])->toBeTrue()
        ->and($snapshot['queue']['totals'])->toMatchArray(['pending_count' => 3, 'waiting_count' => 2, 'delayed_count' => 1, 'reserved_count' => 1, 'oldest_waiting_age_seconds' => 600])
        ->and($snapshot['queue']['queues'][0])->toMatchArray(['queue' => 'default', 'pending_count' => 2, 'waiting_count' => 1, 'delayed_count' => 1, 'reserved_count' => 1, 'oldest_waiting_age_seconds' => 120])
        ->and($snapshot['errors'])->toBeEmpty()
        ->and(DB::table('jobs')->count())->toBe(4)
        ->and(json_encode($snapshot))->not->toContain('secret');
});

it('counts retained failures separately from the half-open report interval', function () {
    $start = now()->subDay()->startOfDay();
    $end = now()->startOfDay();
    foreach ([$start->copy()->subSecond(), $start, $end->copy()->subSecond(), $end, now()->copy()->addSecond()] as $at) {
        queueSnapshotFailure(json_encode(['displayName' => 'App\\Jobs\\RunScheduledCommand']), $at);
    }
    $snapshot = app(AdminQueueSnapshot::class)->snapshot($start, $end, now());
    expect($snapshot['failures'])->toMatchArray(['available' => true, 'retained_total' => 4, 'period_count' => 2, 'sampled_count' => 4, 'sample_truncated' => false])
        ->and($snapshot['failures']['top_categories'][0])->toMatchArray(['category' => 'App\\Jobs\\RunScheduledCommand', 'count' => 4])
        ->and($snapshot['failures']['latest_failure_at'])->toBe($end->toDateTimeString())
        ->and(DB::table('failed_jobs')->count())->toBe(5);
});

it('bounds category sampling and excludes free-form labels serialized bodies and exceptions', function () {
    queueSnapshotFailure(json_encode(['displayName' => 'App\\Jobs\\OldJob']), now()->subHours(3));
    queueSnapshotFailure('malformed JSON with private@example.com', now()->subHours(2));
    queueSnapshotFailure(json_encode(['displayName' => 'Email private@example.com token-secret',
        'data' => ['commandName' => 'App\\Jobs\\RunScheduledCommand', 'command' => str_repeat('serialized-secret', 10000)]]), now()->subHour());
    $snapshot = (new AdminQueueSnapshot(app(DatabaseManager::class), 2))->snapshot(now()->subDay()->startOfDay(), now()->startOfDay(), now());
    expect($snapshot['failures'])->toMatchArray(['retained_total' => 3, 'sampled_count' => 2, 'sample_limit' => 2, 'sample_truncated' => true])
        ->and(array_column($snapshot['failures']['top_categories'], 'category'))->toContain('Unknown job', 'App\\Jobs\\RunScheduledCommand')
        ->and(json_encode($snapshot))->not->toContain('private@example.com', 'token-secret', 'serialized-secret', 'Secret failure content', 'OldJob');
});

it('returns genuine empty metrics without conflating unavailable sources with zero', function () {
    $snapshot = app(AdminQueueSnapshot::class)->snapshot(now()->subDay()->startOfDay(), now()->startOfDay());
    expect($snapshot['queue']['available'])->toBeTrue()
        ->and($snapshot['queue']['totals']['pending_count'])->toBe(0)
        ->and($snapshot['queue']['totals']['oldest_waiting_age_seconds'])->toBeNull()
        ->and($snapshot['failures']['retained_total'])->toBe(0)
        ->and($snapshot['failures']['latest_failure_at'])->toBeNull();
    config(['queue.connections.database.table' => 'missing_jobs', 'queue.failed.table' => 'missing_failures']);
    $unavailable = app(AdminQueueSnapshot::class)->snapshot(now()->subDay()->startOfDay(), now()->startOfDay());
    expect($unavailable['queue']['available'])->toBeFalse()->and($unavailable['queue']['totals'])->toBeNull()
        ->and($unavailable['failures']['retained_total'])->toBeNull()->and($unavailable['errors'])->toHaveCount(2);
});

it('does not mistake the database jobs table for a non-database queue backlog', function () {
    config(['queue.default' => 'sync']);
    $snapshot = app(AdminQueueSnapshot::class)->snapshot(now()->subDay()->startOfDay(), now()->startOfDay());
    expect($snapshot['queue'])->toMatchArray(['available' => false, 'driver' => 'sync', 'queues' => [], 'totals' => null])
        ->and($snapshot['failures']['available'])->toBeTrue()
        ->and($snapshot['errors'][0]['component'])->toBe('queue');
});

it('rejects inverted or empty report periods', function () {
    expect(fn () => app(AdminQueueSnapshot::class)->snapshot(now(), now()))->toThrow(InvalidArgumentException::class);
});
