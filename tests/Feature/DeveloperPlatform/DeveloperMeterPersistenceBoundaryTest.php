<?php

use App\Contracts\DeveloperPlatform\StripeMeterAdapter;
use App\Models\DeveloperApiUsageRecord;
use App\Models\DeveloperMeterBatch;
use App\Models\DeveloperMeterBatchItem;
use App\Models\DeveloperOrganization;
use App\Models\DeveloperProduct;
use App\Services\DeveloperPlatform\DeveloperMeterBatchBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $adapter = Mockery::mock(StripeMeterAdapter::class);
    $adapter->shouldNotReceive('export');
    app()->instance(StripeMeterAdapter::class, $adapter);
});

function meterPersistenceFixture(): array
{
    $from = CarbonImmutable::parse('2026-08-01T00:00:00Z');
    $to = $from->addDay();
    $first = DeveloperOrganization::factory()->create();
    $second = DeveloperOrganization::factory()->create();
    $product = DeveloperProduct::factory()->create(['code' => 'meter-persistence-synthetic']);
    foreach ([[$first->id, 2, $from], [$first->id, 3, $from->addHour()], [$second->id, 4, $to->subSecond()]] as [$organization, $units, $at]) {
        DeveloperApiUsageRecord::factory()->create(['developer_organization_id' => $organization,
            'developer_product_id' => $product->id, 'units' => $units, 'occurred_at' => $at]);
    }
    // The supported period is half open; this row must never enter the batch.
    DeveloperApiUsageRecord::factory()->create(['developer_organization_id' => $first->id,
        'developer_product_id' => $product->id, 'units' => 100, 'occurred_at' => $to]);

    return [$from, $to];
}

function meterPersistenceRows(): array
{
    $state = [];
    foreach (['developer_meter_batches', 'developer_meter_batch_items', 'developer_api_usage_records'] as $table) {
        $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    return $state;
}

function meterPersistenceCli(): int
{
    return Artisan::call('developer-platform:build-meter-batch', [
        '--from' => '2026-08-01T00:00:00Z', '--to' => '2026-08-02T00:00:00Z', '--json' => true,
    ]);
}

it('refuses a cancelled meter parent save without publishing a pending batch', function () {
    meterPersistenceFixture();
    $before = meterPersistenceRows();
    $original = DeveloperMeterBatch::getEventDispatcher();
    DeveloperMeterBatch::setEventDispatcher(clone $original);
    $seen = 0;
    DeveloperMeterBatch::saving(function () use (&$seen): bool {
        $seen++;

        return false;
    });
    try {
        $exit = meterPersistenceCli();
    } finally {
        DeveloperMeterBatch::setEventDispatcher($original);
    }
    expect($seen)->toBeGreaterThan(0)->and($exit)->toBe(1)
        ->and(meterPersistenceRows())->toBe($before);
    Http::assertNothingSent();
});

it('refuses a cancelled later meter item save and rolls back the complete batch', function () {
    meterPersistenceFixture();
    $before = meterPersistenceRows();
    $original = DeveloperMeterBatchItem::getEventDispatcher();
    DeveloperMeterBatchItem::setEventDispatcher(clone $original);
    $seen = 0;
    DeveloperMeterBatchItem::saving(function () use (&$seen): ?bool {
        return ++$seen === 2 ? false : null;
    });
    try {
        $exit = meterPersistenceCli();
    } finally {
        DeveloperMeterBatchItem::setEventDispatcher($original);
    }
    expect($seen)->toBe(2)->and($exit)->toBe(1)
        ->and(meterPersistenceRows())->toBe($before);
    Http::assertNothingSent();
});

it('refuses observer rewritten meter item units instead of acknowledging inconsistent totals', function () {
    meterPersistenceFixture();
    $before = meterPersistenceRows();
    $original = DeveloperMeterBatchItem::getEventDispatcher();
    DeveloperMeterBatchItem::setEventDispatcher(clone $original);
    $seen = 0;
    DeveloperMeterBatchItem::saving(function (DeveloperMeterBatchItem $item) use (&$seen): void {
        if (++$seen === 2) {
            $item->units = 999;
        }
    });
    try {
        $exit = meterPersistenceCli();
    } finally {
        DeveloperMeterBatchItem::setEventDispatcher($original);
    }
    expect($seen)->toBe(2)->and($exit)->toBe(1)
        ->and(meterPersistenceRows())->toBe($before);
    Http::assertNothingSent();
});

it('retains supported service aggregation with persisted exact items and no provider export', function () {
    [$from, $to] = meterPersistenceFixture();
    $usageBefore = meterPersistenceRows()['developer_api_usage_records'];
    $batch = app(DeveloperMeterBatchBuilder::class)->build($from, $to);
    expect($batch->exists)->toBeTrue()->and($batch->status)->toBe('pending')
        ->and($batch->usage_record_count)->toBe(3)->and($batch->total_units)->toBe(9)
        ->and($batch->items)->toHaveCount(2)
        ->and($batch->items->pluck('units')->all())->toBe([5, 4])
        ->and($batch->items->sum('usage_record_count'))->toBe(3)
        ->and(meterPersistenceRows()['developer_api_usage_records'])->toBe($usageBefore);
    Http::assertNothingSent();
});

it('replays the actual meter command without rewriting a frozen pending batch after late usage', function () {
    [$from] = meterPersistenceFixture();
    expect(meterPersistenceCli())->toBe(0);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $before = meterPersistenceRows();
    DeveloperApiUsageRecord::factory()->create(['occurred_at' => $from->addHours(2), 'units' => 99]);
    $usageBeforeReplay = meterPersistenceRows()['developer_api_usage_records'];
    expect(meterPersistenceCli())->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe($payload);
    $after = meterPersistenceRows();
    expect($after['developer_meter_batches'])->toBe($before['developer_meter_batches'])
        ->and($after['developer_meter_batch_items'])->toBe($before['developer_meter_batch_items'])
        ->and($after['developer_api_usage_records'])->toBe($usageBeforeReplay);
    Http::assertNothingSent();
});

it('refuses a later meter item observer changing an earlier checked item', function () {
    meterPersistenceFixture();
    $before = meterPersistenceRows();
    $original = DeveloperMeterBatchItem::getEventDispatcher();
    DeveloperMeterBatchItem::setEventDispatcher(clone $original);
    $seen = 0;
    DeveloperMeterBatchItem::saved(function (DeveloperMeterBatchItem $item) use (&$seen): void {
        if (++$seen === 2) {
            DB::table('developer_meter_batch_items')->where('developer_meter_batch_id', $item->developer_meter_batch_id)
                ->where('id', '<', $item->id)->update(['units' => 777]);
        }
    });
    try {
        $exit = meterPersistenceCli();
    } finally {
        DeveloperMeterBatchItem::setEventDispatcher($original);
    }
    expect($seen)->toBe(2)->and($exit)->toBe(1)
        ->and(meterPersistenceRows())->toBe($before);
    Http::assertNothingSent();
});
