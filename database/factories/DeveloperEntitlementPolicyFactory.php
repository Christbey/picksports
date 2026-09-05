<?php

namespace Database\Factories;

use App\Models\DeveloperEntitlementPolicy;
use App\Models\DeveloperOrganization;
use App\Models\DeveloperProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeveloperEntitlementPolicy> */
class DeveloperEntitlementPolicyFactory extends Factory
{
    protected $model = DeveloperEntitlementPolicy::class;

    public function definition(): array
    {
        return [
            'developer_organization_id' => DeveloperOrganization::factory(),
            'developer_product_id' => DeveloperProduct::factory(),
            'status' => 'active',
            'scopes' => ['events:read'],
            'limits' => ['requests_per_month' => 1000],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addYear(),
        ];
    }
}
