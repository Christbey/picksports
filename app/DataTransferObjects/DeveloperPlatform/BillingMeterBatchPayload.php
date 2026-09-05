<?php

namespace App\DataTransferObjects\DeveloperPlatform;

use App\Models\DeveloperMeterBatch;

final readonly class BillingMeterBatchPayload
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        public string $batchId,
        public string $meterCode,
        public string $periodStart,
        public string $periodEnd,
        public string $idempotencyKey,
        public int $totalUnits,
        public array $items,
    ) {}

    public static function fromBatch(DeveloperMeterBatch $batch): self
    {
        $batch->loadMissing('items.organization', 'items.product');

        return new self(
            batchId: $batch->public_id,
            meterCode: $batch->meter_code,
            periodStart: $batch->period_start->toIso8601String(),
            periodEnd: $batch->period_end->toIso8601String(),
            idempotencyKey: $batch->idempotency_key,
            totalUnits: $batch->total_units,
            items: $batch->items->map(fn ($item): array => [
                'organization_id' => $item->organization->public_id,
                'product_code' => $item->product?->code,
                'units' => $item->units,
                'usage_record_count' => $item->usage_record_count,
                'idempotency_key' => $item->idempotency_key,
                'dimensions' => $item->dimensions ?? [],
            ])->all(),
        );
    }
}
