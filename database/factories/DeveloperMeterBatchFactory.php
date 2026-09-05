<?php

namespace Database\Factories;

use App\Models\DeveloperMeterBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeveloperMeterBatch> */
class DeveloperMeterBatchFactory extends Factory
{
    protected $model = DeveloperMeterBatch::class;

    public function definition(): array
    {
        $start = now()->subDay()->startOfDay();

        return [
            'meter_code' => 'api_request_units',
            'period_start' => $start,
            'period_end' => $start->copy()->addDay(),
            'status' => 'pending',
            'idempotency_key' => hash('sha256', fake()->uuid()),
            'usage_record_count' => 0,
            'total_units' => 0,
            'generated_at' => now(),
        ];
    }
}
