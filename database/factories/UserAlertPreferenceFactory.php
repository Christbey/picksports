<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserAlertPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserAlertPreference>
 */
class UserAlertPreferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'enabled' => $this->faker->boolean(70),
            'sports' => $this->faker->randomElements(['nfl', 'nba', 'cbb', 'wcbb', 'mlb', 'cfb', 'wnba'], $this->faker->numberBetween(1, 5)),
            'notification_types' => $this->faker->randomElements(['email', 'push', 'sms', 'whatsapp'], $this->faker->numberBetween(1, 2)),
            'minimum_edge' => $this->faker->randomFloat(2, 3.0, 10.0),
            'time_window_start' => '09:00:00',
            'time_window_end' => '23:00:00',
            'digest_mode' => $this->faker->randomElement(['realtime', 'daily_summary']),
            'digest_time' => null,
            'daily_digest_subscribed' => true,
            'phone_number' => null,
        ];
    }
}
