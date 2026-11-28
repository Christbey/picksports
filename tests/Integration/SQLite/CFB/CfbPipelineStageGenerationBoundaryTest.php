<?php

use App\Services\CFB\Data\CfbPipelineStage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
beforeEach(function () {
    expect((new ReflectionClass(CfbPipelineStage::class))->getFileName())->toBe(base_path('app/Services/CFB/Data/CfbPipelineStage.php'));
    $this->travelTo(CarbonImmutable::parse('2026-11-27T12:00:00-06:00'));
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
});
afterEach(function () {
    $this->travelBack();
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
});

it('preserves the newer same-input stage receipt when an expired older worker returns', function (string $newState, string $oldState) {
    $stage = app(CfbPipelineStage::class);
    $inner = null;
    $calls = [];
    $outer = $stage->run('game:synthetic', 'forecast', ['source' => 'same'], function () use ($stage, &$inner, &$calls, $newState, $oldState) {
        $calls[] = 'older-start';
        $this->travel(601)->seconds();
        $inner = $stage->run('game:synthetic', 'forecast', ['source' => 'same'], function () use (&$calls, $newState) {
            $calls[] = 'newer';

            return ['state' => $newState, 'marker' => 'newer'];
        });
        $calls[] = 'older-return';

        return ['state' => $oldState, 'marker' => 'older'];
    });
    expect($outer)->toBe(['state' => 'busy', 'reason' => 'receipt_generation_superseded']);
    expect($inner)->toBe(['state' => $newState, 'marker' => 'newer']);
    expect($calls)->toBe(['older-start', 'newer', 'older-return']);
    $row = DB::table('cfb_pipeline_steps')->sole();
    expect(json_decode($row->result, true))->toBe(['state' => $newState, 'marker' => 'newer']);
    expect($row->state)->toBe($newState)->and((int) $row->attempts)->toBe(2);
})->with([['complete', 'failed'], ['failed', 'complete']]);

it('does not reuse an older complete result over a newer failure after expired overlap', function () {
    $stage = app(CfbPipelineStage::class);
    $newer = null;
    $fresh = 0;
    $stage->run('game:resume', 'normalize', ['source' => 'same'], function () use ($stage, &$newer) {
        $this->travel(601)->seconds();
        $newer = $stage->run('game:resume', 'normalize', ['source' => 'same'], fn () => ['state' => 'failed', 'marker' => 'newer']);

        return ['state' => 'complete', 'marker' => 'older'];
    });
    expect($newer['state'])->toBe('failed');
    $result = $stage->run('game:resume', 'normalize', ['source' => 'same'], function () use (&$fresh) {
        $fresh++;

        return ['state' => 'failed', 'marker' => 'fresh-retry'];
    });
    expect($fresh)->toBe(1)->and($result)->toBe(['state' => 'failed', 'marker' => 'fresh-retry']);
});

it('preserves unexpired busy coalescing and ordinary complete resume', function () {
    $stage = app(CfbPipelineStage::class);
    $blocked = null;
    $innerCalls = 0;
    $outerCalls = 0;
    $result = $stage->run('game:busy', 'forecast', ['source' => 'same'], function () use ($stage, &$blocked, &$innerCalls, &$outerCalls) {
        $outerCalls++;
        $blocked = $stage->run('game:busy', 'forecast', ['source' => 'same'], function () use (&$innerCalls) {
            $innerCalls++;

            return ['state' => 'complete'];
        });

        return ['state' => 'complete', 'marker' => 'owner'];
    });
    expect($blocked)->toBe(['state' => 'busy'])->and($innerCalls)->toBe(0)->and($outerCalls)->toBe(1);
    expect($stage->run('game:busy', 'forecast', ['source' => 'same'], function () use (&$outerCalls) {
        $outerCalls++;

        return ['state' => 'failed'];
    }))->toBe($result);
    expect($outerCalls)->toBe(1)->and((int) DB::table('cfb_pipeline_steps')->sole()->attempts)->toBe(1);
});

it('preserves the full unsigned integer receipt generation without overflowing into work', function () {
    $stage = app(CfbPipelineStage::class);
    $calls = 0;
    $stage->run('game:max', 'normalize', [], fn () => ['state' => 'failed']);
    DB::table('cfb_pipeline_steps')->update(['attempts' => 4294967294]);
    $result = $stage->run('game:max', 'normalize', [], function () use (&$calls) {
        $calls++;

        return ['state' => 'failed'];
    });
    expect($result)->toBe(['state' => 'failed'])->and($calls)->toBe(1);
    expect((string) DB::table('cfb_pipeline_steps')->sole()->attempts)->toBe('4294967295');
    $exhausted = $stage->run('game:max', 'normalize', [], function () use (&$calls) {
        $calls++;

        return ['state' => 'complete'];
    });
    expect($exhausted)->toBe(['state' => 'busy', 'reason' => 'receipt_generation_exhausted'])->and($calls)->toBe(1);
    expect((string) DB::table('cfb_pipeline_steps')->sole()->attempts)->toBe('4294967295');
});

it('preserves cooldown and changed inputs without reusing a different revision', function () {
    $stage = app(CfbPipelineStage::class);
    $calls = 0;
    $failed = $stage->run('game:cooldown', 'normalize', ['source' => 1], function () use (&$calls) {
        $calls++;

        return ['state' => 'partial', 'marker' => 'first'];
    });
    $held = $stage->run('game:cooldown', 'normalize', ['source' => 1], function () use (&$calls) {
        $calls++;

        return ['state' => 'complete'];
    }, true, 60);
    expect($held)->toBe($failed)->and($calls)->toBe(1);
    $changed = $stage->run('game:cooldown', 'normalize', ['source' => 2], function () use (&$calls) {
        $calls++;

        return ['state' => 'complete', 'marker' => 'changed'];
    }, true, 60);
    expect($changed)->toBe(['state' => 'complete', 'marker' => 'changed'])->and($calls)->toBe(2);
    $this->travel(61)->seconds();
    $retry = $stage->run('game:cooldown', 'normalize', ['source' => 1], function () use (&$calls) {
        $calls++;

        return ['state' => 'complete', 'marker' => 'retry'];
    }, true, 60);
    expect($retry)->toBe(['state' => 'complete', 'marker' => 'retry'])->and($calls)->toBe(3);
});

it('retains a caller owned transaction without committing its receipt or outputs', function () {
    $connection = DB::connection();
    $connection->beginTransaction();
    try {
        $result = app(CfbPipelineStage::class)->run('game:outer', 'normalize', [], function () use ($connection) {
            expect($connection->transactionLevel())->toBe(1);

            return ['state' => 'complete'];
        });
        expect($result)->toBe(['state' => 'complete'])->and($connection->transactionLevel())->toBe(1);
        expect(DB::table('cfb_pipeline_steps')->count())->toBe(1);
    } finally {
        $connection->rollBack();
    }
    expect(DB::table('cfb_pipeline_steps')->count())->toBe(0);
});
