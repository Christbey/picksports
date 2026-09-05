<?php

namespace Database\Factories;

use App\Models\DeviceSession;
use App\Models\DeviceSessionRefreshToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeviceSessionRefreshToken> */
class DeviceSessionRefreshTokenFactory extends Factory
{
    protected $model = DeviceSessionRefreshToken::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'device_session_id' => DeviceSession::factory(),
            'token_hash' => hash('sha256', fake()->unique()->uuid()),
            'expires_at' => now()->addDays(30),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subSecond()]);
    }
}
