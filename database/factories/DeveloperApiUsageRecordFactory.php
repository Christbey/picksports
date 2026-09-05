<?php

namespace Database\Factories;

use App\Models\DeveloperApiUsageRecord;
use App\Models\DeveloperOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DeveloperApiUsageRecord> */
class DeveloperApiUsageRecordFactory extends Factory
{
    protected $model = DeveloperApiUsageRecord::class;

    public function definition(): array
    {
        return [
            'developer_organization_id' => DeveloperOrganization::factory(),
            'developer_api_credential_id' => null,
            'request_id' => (string) Str::ulid(),
            'operation' => 'events.index',
            'scope' => 'events:read',
            'units' => 1,
            'status_code' => 200,
            'occurred_at' => now(),
            'metadata' => [],
        ];
    }
}
