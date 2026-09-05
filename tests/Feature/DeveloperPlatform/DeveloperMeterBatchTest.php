<?php

use App\Contracts\DeveloperPlatform\StripeMeterAdapter;
use App\DataTransferObjects\DeveloperPlatform\BillingMeterBatchPayload;
use App\Models\DeveloperApiUsageRecord;
use App\Models\DeveloperMeterBatch;
use App\Models\DeveloperOrganization;
use App\Models\DeveloperProduct;
use App\Services\DeveloperPlatform\DeveloperMeterBatchBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

it('aggregates a half-open usage period by organization and product', function () {
    $from = CarbonImmutable::parse('2026-08-01T00:00:00Z');
    $to = $from->addDay();
    $organization = DeveloperOrganization::factory()->create();
    $otherOrganization = DeveloperOrganization::factory()->create();
    $product = DeveloperProduct::factory()->create(['code' => 'events-core']);

    DeveloperApiUsageRecord::factory()->create([
        'developer_organization_id' => $organization->id,
        'developer_product_id' => $product->id,
        'units' => 2,
        'occurred_at' => $from,
    ]);
    DeveloperApiUsageRecord::factory()->create([
        'developer_organization_id' => $organization->id,
        'developer_product_id' => $product->id,
        'units' => 3,
        'occurred_at' => $from->addHour(),
    ]);
    DeveloperApiUsageRecord::factory()->create([
        'developer_organization_id' => $otherOrganization->id,
        'developer_product_id' => null,
        'units' => 4,
        'occurred_at' => $to->subSecond(),
    ]);
    DeveloperApiUsageRecord::factory()->create([
        'developer_organization_id' => $organization->id,
        'developer_product_id' => $product->id,
        'units' => 100,
        'occurred_at' => $to,
    ]);

    $batch = app(DeveloperMeterBatchBuilder::class)->build($from, $to);

    expect(Str::isUlid($batch->public_id))->toBeTrue()
        ->and($batch->status)->toBe('pending')
        ->and($batch->usage_record_count)->toBe(3)
        ->and($batch->total_units)->toBe(9)
        ->and($batch->items)->toHaveCount(2)
        ->and($batch->items->firstWhere('developer_organization_id', $organization->id)?->units)->toBe(5)
        ->and($batch->items->firstWhere('developer_organization_id', $organization->id)?->usage_record_count)->toBe(2)
        ->and($batch->items->firstWhere('developer_organization_id', $otherOrganization->id)?->units)->toBe(4);

    $payload = BillingMeterBatchPayload::fromBatch($batch);

    expect($payload->batchId)->toBe($batch->public_id)
        ->and($payload->items[0])->toHaveKeys(['organization_id', 'product_code', 'units', 'usage_record_count', 'idempotency_key']);
});

it('returns the same batch when the meter period is built again', function () {
    $from = CarbonImmutable::parse('2026-08-02T00:00:00Z');
    $to = $from->addDay();
    DeveloperApiUsageRecord::factory()->create(['occurred_at' => $from->addHour(), 'units' => 7]);
    $builder = app(DeveloperMeterBatchBuilder::class);

    $first = $builder->build($from, $to, 'api_request_units');
    DeveloperApiUsageRecord::factory()->create(['occurred_at' => $from->addHours(2), 'units' => 99]);
    $second = $builder->build($from, $to, 'api_request_units');

    expect($second->is($first))->toBeTrue()
        ->and($second->total_units)->toBe(7)
        ->and(DeveloperMeterBatch::query()->count())->toBe(1)
        ->and($second->items)->toHaveCount(1);
});

it('builds a persisted batch from the command without contacting stripe', function () {
    $adapter = Mockery::mock(StripeMeterAdapter::class);
    $adapter->shouldNotReceive('export');
    app()->instance(StripeMeterAdapter::class, $adapter);
    DeveloperApiUsageRecord::factory()->create([
        'occurred_at' => CarbonImmutable::parse('2026-08-03T12:00:00Z'),
        'units' => 2,
    ]);

    $this->artisan('developer-platform:build-meter-batch', [
        '--from' => '2026-08-03T00:00:00Z',
        '--to' => '2026-08-04T00:00:00Z',
        '--json' => true,
    ])->assertSuccessful()->expectsOutputToContain('"totalUnits": 2');

    expect(DeveloperMeterBatch::query()->sole()->status)->toBe('pending');
});

it('rejects invalid meter periods without persisting a batch', function () {
    $this->artisan('developer-platform:build-meter-batch', [
        '--from' => '2026-08-04T00:00:00Z',
        '--to' => '2026-08-03T00:00:00Z',
    ])->assertFailed();

    expect(DeveloperMeterBatch::query()->count())->toBe(0);
});
