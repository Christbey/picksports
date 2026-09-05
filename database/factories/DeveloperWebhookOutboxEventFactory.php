<?php

namespace Database\Factories;

use App\Models\DeveloperOrganization;
use App\Models\DeveloperWebhookOutboxEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DeveloperWebhookOutboxEvent> */
class DeveloperWebhookOutboxEventFactory extends Factory
{
    protected $model = DeveloperWebhookOutboxEvent::class;

    public function definition(): array
    {
        $payload = ['prediction_id' => fake()->numberBetween(1, 1000)];

        return [
            'developer_organization_id' => DeveloperOrganization::factory(),
            'event_id' => (string) Str::ulid(),
            'event_type' => 'prediction.updated',
            'payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'occurred_at' => now(),
        ];
    }
}
