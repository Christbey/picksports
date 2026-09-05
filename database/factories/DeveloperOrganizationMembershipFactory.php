<?php

namespace Database\Factories;

use App\Enums\DeveloperOrganizationRole;
use App\Models\DeveloperOrganization;
use App\Models\DeveloperOrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeveloperOrganizationMembership> */
class DeveloperOrganizationMembershipFactory extends Factory
{
    protected $model = DeveloperOrganizationMembership::class;

    public function definition(): array
    {
        return [
            'developer_organization_id' => DeveloperOrganization::factory(),
            'user_id' => User::factory(),
            'role' => DeveloperOrganizationRole::Developer,
        ];
    }
}
