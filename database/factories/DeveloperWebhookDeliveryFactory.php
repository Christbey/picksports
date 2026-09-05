<?php

namespace Database\Factories;

use App\Models\DeveloperWebhookDelivery;
use App\Models\DeveloperWebhookEndpoint;
use App\Models\DeveloperWebhookOutboxEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeveloperWebhookDelivery> */
class DeveloperWebhookDeliveryFactory extends Factory
{
    protected $model = DeveloperWebhookDelivery::class;

    public function definition(): array
    {
        return [
            'developer_webhook_outbox_event_id' => DeveloperWebhookOutboxEvent::factory(),
            'developer_webhook_endpoint_id' => DeveloperWebhookEndpoint::factory(),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
        ];
    }
}
