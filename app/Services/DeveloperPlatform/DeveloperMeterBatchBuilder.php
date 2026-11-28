<?php

namespace App\Services\DeveloperPlatform;

use App\Models\DeveloperApiUsageRecord;
use App\Models\DeveloperMeterBatch;
use App\Models\DeveloperMeterBatchItem;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class DeveloperMeterBatchBuilder
{
    public function build(
        DateTimeInterface $periodStart,
        DateTimeInterface $periodEnd,
        string $meterCode = 'api_request_units',
    ): DeveloperMeterBatch {
        $meterCode = trim($meterCode);
        $start = CarbonImmutable::instance($periodStart)->utc()->startOfSecond();
        $end = CarbonImmutable::instance($periodEnd)->utc()->startOfSecond();

        if ($meterCode === '' || mb_strlen($meterCode) > 64) {
            throw new InvalidArgumentException('Meter code must contain between 1 and 64 characters.');
        }

        if (! $start->lessThan($end)) {
            throw new InvalidArgumentException('Meter period end must be after its start.');
        }

        $key = hash('sha256', implode('|', ['v1', $meterCode, $start->toIso8601String(), $end->toIso8601String()]));

        try {
            return DB::transaction(function () use ($meterCode, $start, $end, $key): DeveloperMeterBatch {
                $existing = DeveloperMeterBatch::query()
                    ->where('idempotency_key', $key)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing->load('items');
                }

                $groups = DeveloperApiUsageRecord::query()
                    ->where('occurred_at', '>=', $start)
                    ->where('occurred_at', '<', $end)
                    ->select(['developer_organization_id', 'developer_product_id'])
                    ->selectRaw('COUNT(*) as usage_record_count')
                    ->selectRaw('SUM(units) as total_units')
                    ->groupBy(['developer_organization_id', 'developer_product_id'])
                    ->orderBy('developer_organization_id')
                    ->orderBy('developer_product_id')
                    ->get();

                $batchValues = [
                    'meter_code' => $meterCode,
                    'period_start' => $start,
                    'period_end' => $end,
                    'status' => 'pending',
                    'idempotency_key' => $key,
                    'usage_record_count' => (int) $groups->sum('usage_record_count'),
                    'total_units' => (int) $groups->sum('total_units'),
                    'generated_at' => now(),
                ];
                $preparedItems = [];
                foreach ($groups as $group) {
                    $organizationId = (int) $group->developer_organization_id;
                    $productId = $group->developer_product_id === null ? null : (int) $group->developer_product_id;

                    $preparedItems[] = [
                        'developer_organization_id' => $organizationId,
                        'developer_product_id' => $productId,
                        'idempotency_key' => hash('sha256', implode('|', [$key, $organizationId, $productId ?? 'none'])),
                        'usage_record_count' => (int) $group->usage_record_count,
                        'units' => (int) $group->total_units,
                        'dimensions' => ['meter_version' => 1],
                    ];
                }
                $connection = (new DeveloperMeterBatch)->getConnection();
                $batchExpected = (new DeveloperMeterBatch)->forceFill($batchValues)->getAttributes();
                $batch = DeveloperMeterBatch::query()->create($batchValues);
                if (! $batch->exists || ! is_int($batch->getKey())) {
                    throw new RuntimeException('Meter batch persistence was refused.');
                }
                $batchId = $batch->getKey();
                $itemPlans = [];

                foreach ($preparedItems as $values) {
                    $values['developer_meter_batch_id'] = $batchId;
                    $expected = (new DeveloperMeterBatchItem)->forceFill($values)->getAttributes();
                    $item = $batch->items()->create($values);
                    if (! $item->exists || ! is_int($item->getKey())) {
                        throw new RuntimeException('Meter item persistence was refused.');
                    }
                    $itemPlans[$item->getKey()] = $expected;
                }

                $current = $connection->table('developer_meter_batches')->where('id', $batchId)->first();
                if ($current === null || ! $this->matches((array) $current, $batchExpected)) {
                    throw new RuntimeException('Meter batch persisted values changed.');
                }
                $items = $connection->table('developer_meter_batch_items')->where('developer_meter_batch_id', $batchId)->orderBy('id')->get();
                if ($items->count() !== count($itemPlans)) {
                    throw new RuntimeException('Meter item membership changed.');
                }
                foreach ($items as $row) {
                    $expected = $itemPlans[$row->id] ?? null;
                    if ($expected === null || ! $this->matches((array) $row, $expected)) {
                        throw new RuntimeException('Meter item persisted values changed.');
                    }
                }

                return $batch->load('items');
            }, 3);
        } catch (QueryException $exception) {
            $existing = DeveloperMeterBatch::query()
                ->where('idempotency_key', $key)
                ->first();

            if ($existing !== null) {
                return $existing->load('items');
            }

            throw $exception;
        }
    }

    private function matches(array $actual, array $expected): bool
    {
        foreach ($expected as $field => $value) {
            if (! array_key_exists($field, $actual)) {
                return false;
            }
            if ($field === 'dimensions') {
                if (json_decode((string) $actual[$field], true, flags: JSON_THROW_ON_ERROR) !== json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR)) {
                    return false;
                }
            } elseif ($actual[$field] === null || $value === null) {
                if ($actual[$field] !== $value) {
                    return false;
                }
            } elseif ((string) $actual[$field] !== (string) $value) {
                return false;
            }
        }

        return true;
    }
}
