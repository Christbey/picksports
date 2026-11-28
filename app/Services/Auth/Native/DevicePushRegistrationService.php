<?php

namespace App\Services\Auth\Native;

use App\Models\DevicePushRegistration;
use App\Models\DeviceSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

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

        if ($environment !== null && ! in_array($environment, ['sandbox', 'production'], true)) {
            throw new InvalidArgumentException('Unsupported push environment.');
        }
        $connection = $session->getConnection();
        $registrationModel = new DevicePushRegistration;
        if ($registrationModel->getConnectionName() !== null
            && $registrationModel->getConnection()->getName() !== $connection->getName()) {
            throw new LogicException('Push registration requires the device session connection.');
        }

        try {
            return $connection->transaction(function () use ($session, $connection, $provider, $deviceToken, $environment): DevicePushRegistration {
                $current = $session->newQuery()->whereKey($session->getKey())->lockForUpdate()->first();
                if ($current === null || $current->revoked_at !== null || $current->user_id !== $session->user_id) {
                    throw ValidationException::withMessages(['device_token' => 'Push registration requires an active owned device session.']);
                }
                $identity = ['provider' => $provider, 'token_hash' => hash('sha256', $deviceToken)];
                $registration = DevicePushRegistration::on($connection->getName())->where($identity)->lockForUpdate()->first();
                if ($registration !== null) {
                    $owner = DeviceSession::on($connection->getName())->whereKey($registration->device_session_id)->lockForUpdate()->first();
                    if ($owner === null || $owner->user_id !== $current->user_id) {
                        throw ValidationException::withMessages(['device_token' => 'This push registration cannot be assigned to this account.']);
                    }
                } else {
                    // Do not use updateOrCreate's unique-race fallback: a competing
                    // account's insertion must never become an ownership transfer.
                    $registration = new DevicePushRegistration($identity);
                    $registration->setConnection($connection->getName());
                }
                $registration->fill([
                    'device_session_id' => $current->getKey(),
                    'device_token' => $deviceToken,
                    'environment' => $environment,
                    'last_registered_at' => now(),
                    'revoked_at' => null,
                ])->save();

                return $registration;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['device_token' => 'Push registration changed concurrently; retry this request.']);
        }
    }

    public function revoke(DeviceSession $session, string $provider, string $deviceToken): bool
    {
        return $session->pushRegistrations()
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

        return $session->pushRegistrations()
            ->where('provider', $provider)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
