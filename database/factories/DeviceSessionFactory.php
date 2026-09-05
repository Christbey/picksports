<?php

namespace Database\Factories;

use App\Models\DeviceSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeviceSession> */
class DeviceSessionFactory extends Factory
{
    protected $model = DeviceSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_name' => fake()->randomElement(['iPhone', 'iPad', 'Android Phone']),
            'platform' => fake()->randomElement(['ios', 'android']),
            'device_identifier_hash' => hash('sha256', fake()->uuid()),
            'abilities' => ['mobile:read', 'mobile:write'],
            'access_token_expires_at' => now()->addMinutes(15),
            'last_used_at' => now(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
            'revocation_reason' => 'manual',
        ]);
    }
}
