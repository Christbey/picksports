<?php

use App\Support\Queue\BoundedReadyQueueScripts;

beforeEach(function () {
    $socket = getenv('PICKSPORTS_TEST_REDIS_SOCKET');
    $runId = getenv('PICKSPORTS_TEST_REDIS_RUN_ID');
    $pid = getenv('PICKSPORTS_TEST_REDIS_PID');
    $directory = is_string($socket) ? realpath(dirname($socket)) : false;
    $tempRoot = realpath(sys_get_temp_dir());
    $owner = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    if (getenv('PICKSPORTS_TEST_REDIS') !== '1' || ! class_exists(Redis::class)
        || ! is_string($socket) || ! is_string($runId) || ! preg_match('/^[a-f0-9]{40}$/', $runId)
        || ! is_string($pid) || ! ctype_digit($pid) || $directory === false
        || $tempRoot === false || ! str_starts_with($directory, $tempRoot.'/picksports-readiness-redis-')
        || is_link($socket) || realpath($socket) !== $directory.'/redis.sock'
        || fileowner($directory) !== $owner || (fileperms($directory) & 0777) !== 0700) {
        throw new RuntimeException('Explicit isolated private Redis fixture is required.');
    }
    $this->redis = new Redis;
    $this->redis->connect($socket, 0, 1.0);
    $this->redis->setOption(Redis::OPT_READ_TIMEOUT, 2.0);
    $info = $this->redis->info('server');
    if (($info['run_id'] ?? null) !== $runId || (string) ($info['process_id'] ?? '') !== $pid
        || (int) ($info['tcp_port'] ?? -1) !== 0) {
        $this->redis->close();
        unset($this->redis);
        throw new RuntimeException('Isolated Redis fixture identity does not match.');
    }
    $prefix = 'picksports-pruner-test:'.bin2hex(random_bytes(12));
    $this->ready = $prefix.':ready';
    $this->notify = $this->ready.':notify';
    $this->lock = $prefix.':unique-lock';
    $this->delayed = $this->ready.':delayed';
    $this->reserved = $this->ready.':reserved';
});

afterEach(function () {
    if (isset($this->redis, $this->ready)) {
        foreach ([$this->ready, $this->notify, $this->lock, $this->delayed, $this->reserved] as $key) {
            $this->redis->del($key);
        }
        $this->redis->close();
    }
});

function seedPrunerOverflow(Redis $redis, string $key, string $kind): void
{
    if ($kind === 'queue_count') {
        $redis->rPush($key, ...array_fill(0, BoundedReadyQueueScripts::MAX_JOBS, 'x'));
    } elseif ($kind === 'payload_bytes') {
        $redis->rPush($key, str_repeat('x', BoundedReadyQueueScripts::MAX_PAYLOAD_BYTES + 1));
    } else {
        $redis->rPush($key, ...array_fill(0, 16, str_repeat('x', BoundedReadyQueueScripts::MAX_PAYLOAD_BYTES)));
    }
}

it('executes real Lua capacity guards without returning an oversized snapshot', function (string $kind) {
    $this->redis->rPush($this->ready, 'target');
    seedPrunerOverflow($this->redis, $this->ready, $kind);
    $before = $this->redis->lLen($this->ready);
    $result = $this->redis->eval(BoundedReadyQueueScripts::snapshot(), [$this->ready], 1);

    expect($result[0])->toBe($kind)->and($result)->toHaveCount(3)
        ->and($this->redis->lLen($this->ready))->toBe($before);
})->with(['queue_count', 'payload_bytes', 'queue_bytes']);

it('rechecks current bounds before removing any formerly inspected payload', function (string $kind) {
    $this->redis->rPush($this->ready, 'target');
    $this->redis->rPush($this->notify, '1');
    expect($this->redis->eval(BoundedReadyQueueScripts::snapshot(), [$this->ready], 1)[0])->toBe('ok');
    seedPrunerOverflow($this->redis, $this->ready, $kind);
    $count = $this->redis->lLen($this->ready);
    $result = $this->redis->eval(BoundedReadyQueueScripts::remove(), [$this->ready, $this->notify, 'target'], 2);

    expect($result[0])->toBe($kind)->and($this->redis->lLen($this->ready))->toBe($count)
        ->and($this->redis->lIndex($this->ready, 0))->toBe('target')
        ->and($this->redis->lLen($this->notify))->toBe(1);
})->with(['queue_count', 'payload_bytes', 'queue_bytes']);

it('removes exact raw multiplicities while preserving unrelated order notifications and unique locks', function () {
    $target = '{"uuid":"same","data":"stale"}';
    $keep = '{"uuid":"same","data":"unrelated replacement"}';
    $this->redis->rPush($this->ready, $target, $keep, 'middle', $target, 'tail', $target);
    $this->redis->rPush($this->notify, ...array_fill(0, 6, '1'));
    $this->redis->setEx($this->lock, 3600, 'new-owner');
    $this->redis->zAdd($this->reserved, 1, 'reserved-sentinel');
    $this->redis->zAdd($this->delayed, 1, 'delayed-sentinel');
    $result = $this->redis->eval(BoundedReadyQueueScripts::remove(), [$this->ready, $this->notify, $target, $target], 2);

    expect($result)->toBe(['ok', 2])
        ->and($this->redis->lRange($this->ready, 0, 5))->toBe([$keep, 'middle', 'tail', $target])
        ->and($this->redis->lLen($this->notify))->toBe(4)
        ->and($this->redis->get($this->lock))->toBe('new-owner')
        ->and($this->redis->ttl($this->lock))->toBeGreaterThan(3500)
        ->and($this->redis->zRange($this->reserved, 0, 0))->toBe(['reserved-sentinel'])
        ->and($this->redis->zRange($this->delayed, 0, 0))->toBe(['delayed-sentinel']);
});

it('does not remove a changed payload that reuses the inspected uuid', function () {
    $old = '{"uuid":"same","data":"old"}';
    $new = '{"uuid":"same","data":"new"}';
    $this->redis->rPush($this->ready, $old);
    $this->redis->rPush($this->notify, '1');
    $snapshot = $this->redis->eval(BoundedReadyQueueScripts::snapshot(), [$this->ready], 1);
    $this->redis->lSet($this->ready, 0, $new);
    $result = $this->redis->eval(BoundedReadyQueueScripts::remove(), [$this->ready, $this->notify, $snapshot[3][0]], 2);

    expect($result)->toBe(['ok', 0])->and($this->redis->lIndex($this->ready, 0))->toBe($new)
        ->and($this->redis->lLen($this->notify))->toBe(1);
});

it('checks notification key type before a queue mutation', function () {
    $this->redis->rPush($this->ready, 'target');
    $this->redis->set($this->notify, 'wrong-type-sentinel');
    $result = $this->redis->eval(BoundedReadyQueueScripts::remove(), [$this->ready, $this->notify, 'target'], 2);

    expect($result[0])->toBe('notify_type')->and($this->redis->lIndex($this->ready, 0))->toBe('target')
        ->and($this->redis->get($this->notify))->toBe('wrong-type-sentinel');
});
