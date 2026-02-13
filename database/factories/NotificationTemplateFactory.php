<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
            'subject' => fake()->sentence(),
            'email_body' => fake()->paragraph(),
            'sms_body' => fake()->text(160),
            'push_title' => fake()->sentence(3),
            'push_body' => fake()->sentence(),
            'variables' => ['user_name', 'game', 'edge'],
            'active' => true,
        ];
    }
}
