<?php

namespace Database\Factories;

use App\Models\DevicePushRegistration;
use App\Models\DeviceSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DevicePushRegistration> */
class DevicePushRegistrationFactory extends Factory
{
    protected $model = DevicePushRegistration::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $token = fake()->unique()->sha256();

        return [
            'device_session_id' => DeviceSession::factory(),
            'provider' => fake()->randomElement(['apns', 'fcm']),
            'token_hash' => hash('sha256', $token),
            'device_token' => $token,
            'environment' => 'production',
            'last_registered_at' => now(),
        ];
    }
}
