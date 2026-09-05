<?php

namespace Database\Factories;

use App\Models\DeveloperOrganization;
use App\Models\DeveloperWebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DeveloperWebhookEndpoint> */
class DeveloperWebhookEndpointFactory extends Factory
{
    protected $model = DeveloperWebhookEndpoint::class;

    public function definition(): array
    {
        return [
            'developer_organization_id' => DeveloperOrganization::factory(),
            'name' => 'Test webhook',
            'url' => 'https://example.test/webhooks/picksports',
            'signing_secret' => Str::random(48),
            'events' => ['prediction.updated'],
            'status' => 'active',
        ];
    }
}
