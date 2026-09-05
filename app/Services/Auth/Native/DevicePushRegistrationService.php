<?php

namespace App\Services\Auth\Native;

use App\Models\DevicePushRegistration;
use App\Models\DeviceSession;
use InvalidArgumentException;

final class DevicePushRegistrationService
{
    public function register(
        DeviceSession $session,
        string $provider,
        string $deviceToken,
        ?string $environment = null,
    ): DevicePushRegistration {
        $provider = strtolower(trim($provider));
        $deviceToken = trim($deviceToken);

        if (! in_array($provider, ['apns', 'fcm'], true)) {
            throw new InvalidArgumentException('Push provider must be apns or fcm.');
        }

        if ($deviceToken === '') {
            throw new InvalidArgumentException('Push device token is required.');
        }

        if ($session->revoked_at !== null) {
            throw new InvalidArgumentException('Push registration requires an active device session.');
        }

        return DevicePushRegistration::query()->updateOrCreate(
            [
                'provider' => $provider,
                'token_hash' => hash('sha256', $deviceToken),
            ],
            [
                'device_session_id' => $session->getKey(),
                'device_token' => $deviceToken,
                'environment' => $environment,
                'last_registered_at' => now(),
                'revoked_at' => null,
            ],
        );
    }

    public function revoke(DeviceSession $session, string $provider, string $deviceToken): bool
    {
        return DevicePushRegistration::query()
            ->where('device_session_id', $session->getKey())
            ->where('provider', strtolower(trim($provider)))
            ->where('token_hash', hash('sha256', trim($deviceToken)))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]) === 1;
    }

    public function revokeProvider(DeviceSession $session, string $provider): int
    {
        $provider = strtolower(trim($provider));

        if (! in_array($provider, ['apns', 'fcm'], true)) {
            throw new InvalidArgumentException('Push provider must be apns or fcm.');
        }

        return DevicePushRegistration::query()
            ->where('device_session_id', $session->getKey())
            ->where('provider', $provider)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
