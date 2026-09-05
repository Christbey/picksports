<?php

namespace Database\Factories;

use App\Models\DeveloperMeterBatch;
use App\Models\DeveloperMeterBatchItem;
use App\Models\DeveloperOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeveloperMeterBatchItem> */
class DeveloperMeterBatchItemFactory extends Factory
{
    protected $model = DeveloperMeterBatchItem::class;

    public function definition(): array
    {
        return [
            'developer_meter_batch_id' => DeveloperMeterBatch::factory(),
            'developer_organization_id' => DeveloperOrganization::factory(),
            'developer_product_id' => null,
            'idempotency_key' => hash('sha256', fake()->uuid()),
            'usage_record_count' => 1,
            'units' => 1,
            'dimensions' => [],
        ];
    }
}
