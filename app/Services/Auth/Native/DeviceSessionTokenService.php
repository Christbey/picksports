<?php

namespace App\Services\Auth\Native;

use App\Models\DeviceSession;
use App\Models\DeviceSessionRefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class DeviceSessionTokenService
{
    /**
     * @param  list<string>|null  $abilities
     */
    public function issue(
        User $user,
        string $deviceName,
        string $platform,
        ?string $deviceIdentifier = null,
        ?array $abilities = null,
    ): DeviceTokenPair {
        $platform = strtolower(trim($platform));
        $this->assertPlatform($platform);
        $abilities ??= array_values((array) config('native_auth.abilities', ['mobile:read']));

        return DB::transaction(function () use ($user, $deviceName, $platform, $deviceIdentifier, $abilities): DeviceTokenPair {
            $accessExpiresAt = now()->addMinutes($this->accessTtlMinutes());
            $refreshExpiresAt = now()->addDays($this->refreshTtlDays());
            $access = $user->createToken(
                $this->accessTokenName($deviceName),
                $abilities,
                $accessExpiresAt,
            );
            $plainRefreshToken = $this->newRefreshToken();

            $session = DeviceSession::query()->create([
                'user_id' => $user->getKey(),
                'access_token_id' => $access->accessToken->getKey(),
                'device_name' => trim($deviceName) ?: 'mobile-device',
                'platform' => $platform,
                'device_identifier_hash' => filled($deviceIdentifier)
                    ? hash('sha256', trim((string) $deviceIdentifier))
                    : null,
                'abilities' => $abilities,
                'access_token_expires_at' => $accessExpiresAt,
                'last_used_at' => now(),
            ]);

            DeviceSessionRefreshToken::query()->create([
                'device_session_id' => $session->getKey(),
                'token_hash' => $this->hashRefreshToken($plainRefreshToken),
                'expires_at' => $refreshExpiresAt,
            ]);

            return new DeviceTokenPair(
                deviceSession: $session,
                accessToken: $access->plainTextToken,
                refreshToken: $plainRefreshToken,
                accessTokenExpiresAt: $accessExpiresAt,
                refreshTokenExpiresAt: $refreshExpiresAt,
            );
        }, attempts: 3);
    }

    public function rotate(string $plainRefreshToken): DeviceTokenPair
    {
        $result = DB::transaction(function () use ($plainRefreshToken): DeviceTokenPair|string {
            $refreshToken = DeviceSessionRefreshToken::query()
                ->where('token_hash', $this->hashRefreshToken($plainRefreshToken))
                ->lockForUpdate()
                ->first();

            if (! $refreshToken) {
                return 'unknown';
            }

            $session = DeviceSession::query()
                ->with('user')
                ->lockForUpdate()
                ->find($refreshToken->device_session_id);

            if (! $session || $refreshToken->used_at !== null) {
                if ($session) {
                    $this->revokeFamily($session, 'refresh_token_reuse');
                }

                return 'reused';
            }

            if ($session->revoked_at !== null || $refreshToken->revoked_at !== null) {
                return 'revoked';
            }

            if ($refreshToken->expires_at->isPast()) {
                $this->revokeFamily($session, 'refresh_token_expired');

                return 'expired';
            }

            $session->accessToken?->delete();

            $abilities = array_values($session->abilities ?? []);
            $accessExpiresAt = now()->addMinutes($this->accessTtlMinutes());
            $refreshExpiresAt = now()->addDays($this->refreshTtlDays());
            $access = $session->user->createToken(
                $this->accessTokenName($session->device_name),
                $abilities,
                $accessExpiresAt,
            );
            $replacementPlainText = $this->newRefreshToken();
            $replacement = DeviceSessionRefreshToken::query()->create([
                'device_session_id' => $session->getKey(),
                'token_hash' => $this->hashRefreshToken($replacementPlainText),
                'expires_at' => $refreshExpiresAt,
            ]);

            $refreshToken->forceFill([
                'used_at' => now(),
                'replaced_by_token_id' => $replacement->getKey(),
            ])->save();

            $session->forceFill([
                'access_token_id' => $access->accessToken->getKey(),
                'access_token_expires_at' => $accessExpiresAt,
                'last_used_at' => now(),
            ])->save();

            return new DeviceTokenPair(
                deviceSession: $session,
                accessToken: $access->plainTextToken,
                refreshToken: $replacementPlainText,
                accessTokenExpiresAt: $accessExpiresAt,
                refreshTokenExpiresAt: $refreshExpiresAt,
            );
        }, attempts: 3);

        if (is_string($result)) {
            throw new InvalidRefreshToken($result);
        }

        return $result;
    }

    public function revoke(User $user, string $deviceSessionPublicId, string $reason = 'manual'): bool
    {
        return DB::transaction(function () use ($user, $deviceSessionPublicId, $reason): bool {
            $session = DeviceSession::query()
                ->where('user_id', $user->getKey())
                ->where('public_id', $deviceSessionPublicId)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                return false;
            }

            $this->revokeFamily($session, $reason);

            return true;
        }, attempts: 3);
    }

    public function revokeCurrent(User $user, string $reason = 'logout'): bool
    {
        $accessToken = $user->currentAccessToken();

        if (! $accessToken instanceof PersonalAccessToken) {
            return false;
        }

        return DB::transaction(function () use ($user, $accessToken, $reason): bool {
            $session = DeviceSession::query()
                ->where('user_id', $user->getKey())
                ->where('access_token_id', $accessToken->getKey())
                ->lockForUpdate()
                ->first();

            if (! $session) {
                return false;
            }

            $this->revokeFamily($session, $reason);

            return true;
        }, attempts: 3);
    }

    public function revokeAll(User $user, string $reason = 'logout_all'): int
    {
        return DB::transaction(function () use ($user, $reason): int {
            $sessions = DeviceSession::query()
                ->where('user_id', $user->getKey())
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $session) {
                $this->revokeFamily($session, $reason);
            }

            return $sessions->count();
        }, attempts: 3);
    }

    private function revokeFamily(DeviceSession $session, string $reason): void
    {
        $now = now();

        $session->refreshTokens()
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now,
                'revocation_reason' => $reason,
                'updated_at' => $now,
            ]);
        $session->pushRegistrations()
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);
        $session->accessToken?->delete();
        $session->forceFill([
            'access_token_id' => null,
            'revoked_at' => $session->revoked_at ?? $now,
            'revocation_reason' => $reason,
        ])->save();
    }

    private function newRefreshToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    private function hashRefreshToken(string $plainRefreshToken): string
    {
        return hash('sha256', $plainRefreshToken);
    }

    private function accessTokenName(string $deviceName): string
    {
        return 'native:'.(trim($deviceName) ?: 'mobile-device');
    }

    private function accessTtlMinutes(): int
    {
        return max(1, (int) config('native_auth.access_token_ttl_minutes', 15));
    }

    private function refreshTtlDays(): int
    {
        return max(1, (int) config('native_auth.refresh_token_ttl_days', 30));
    }

    private function assertPlatform(string $platform): void
    {
        if (! in_array($platform, ['ios', 'android'], true)) {
            throw new \InvalidArgumentException('Native device platform must be ios or android.');
        }
    }
}
