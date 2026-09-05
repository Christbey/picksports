<?php

namespace Database\Factories;

use App\Models\DeveloperApiCredential;
use App\Models\DeveloperOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DeveloperApiCredential> */
class DeveloperApiCredentialFactory extends Factory
{
    protected $model = DeveloperApiCredential::class;

    public function definition(): array
    {
        return [
            'developer_organization_id' => DeveloperOrganization::factory(),
            'name' => 'Test credential',
            'prefix' => strtolower(Str::random(12)),
            'secret_hash' => hash('sha256', Str::random(48)),
            'scopes' => ['events:read'],
        ];
    }
}
