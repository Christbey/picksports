<?php

namespace App\Services\Auth\Native;

use App\Models\DevicePushRegistration;
use App\Models\DeviceSession;
use App\Models\DeviceSessionRefreshToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
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
        $this->assertConnection($user);
        $platform = strtolower(trim($platform));
        $this->assertPlatform($platform);
        $abilities ??= array_values((array) config('native_auth.abilities', ['mobile:read']));
        NativeCredentialPolicy::profile($abilities);
        $canonical = $this->epochIssuanceEnabled();
        $this->accessTtlMinutes();
        $this->refreshTtlDays();

        return DB::transaction(function () use ($user, $deviceName, $platform, $deviceIdentifier, $abilities, $canonical): DeviceTokenPair {
            [$accessExpiresAt, $refreshExpiresAt] = $this->expiries($canonical);
            $access = $this->createAccess($user, $deviceName, $abilities, $accessExpiresAt, $canonical);
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
                'access_token_expires_at' => $canonical ? null : $accessExpiresAt,
                'native_policy_version' => $canonical ? NativeCredentialPolicy::VERSION : null,
                'access_expires_epoch' => $canonical ? $accessExpiresAt->getTimestamp() : null,
                'last_used_at' => now(),
            ]);

            DeviceSessionRefreshToken::query()->create([
                'device_session_id' => $session->getKey(),
                'token_hash' => $this->hashRefreshToken($plainRefreshToken),
                'expires_at' => $canonical ? null : $refreshExpiresAt,
                'native_policy_version' => $canonical ? NativeCredentialPolicy::VERSION : null,
                'expires_epoch' => $canonical ? $refreshExpiresAt->getTimestamp() : null,
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
        $this->assertConnection();
        $result = DB::transaction(function () use ($plainRefreshToken): DeviceTokenPair|string {
            $refreshToken = DeviceSessionRefreshToken::query()
                ->where('token_hash', $this->hashRefreshToken($plainRefreshToken))
                ->lockForUpdate()
                ->first();

            if (! $refreshToken) {
                return 'unknown';
            }

            $session = DeviceSession::query()
                ->with('accessToken')
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

            $recorded = $this->recordedPolicy($session, $refreshToken);
            if ($recorded === null || $refreshToken->expires_at === null) {
                return 'policy';
            }
            if ($recorded ? $refreshToken->expires_at->getTimestamp() <= CarbonImmutable::now('UTC')->getTimestamp() : $refreshToken->expires_at->isPast()) {
                $this->revokeFamily($session, 'refresh_token_expired');

                return 'expired';
            }

            $abilities = array_values($session->abilities ?? []);
            $canonical = $recorded || $this->epochIssuanceEnabled();
            if ($canonical) {
                NativeCredentialPolicy::profile($abilities);
            }
            [$accessExpiresAt, $refreshExpiresAt] = $this->expiries($canonical);
            $this->assertConnection($session->user);
            $session->accessToken?->delete();
            $access = $this->createAccess($session->user, $session->device_name, $abilities, $accessExpiresAt, $canonical);
            $replacementPlainText = $this->newRefreshToken();
            $replacement = DeviceSessionRefreshToken::query()->create([
                'device_session_id' => $session->getKey(),
                'token_hash' => $this->hashRefreshToken($replacementPlainText),
                'expires_at' => $canonical ? null : $refreshExpiresAt,
                'native_policy_version' => $canonical ? NativeCredentialPolicy::VERSION : null,
                'expires_epoch' => $canonical ? $refreshExpiresAt->getTimestamp() : null,
            ]);

            $refreshToken->forceFill([
                'used_at' => now(),
                'replaced_by_token_id' => $replacement->getKey(),
            ])->save();

            $session->forceFill([
                'access_token_id' => $access->accessToken->getKey(),
                'access_token_expires_at' => $canonical ? null : $accessExpiresAt,
                'native_policy_version' => $canonical ? NativeCredentialPolicy::VERSION : null,
                'access_expires_epoch' => $canonical ? $accessExpiresAt->getTimestamp() : null,
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
        $this->assertConnection($user);

        return DB::transaction(function () use ($user, $deviceSessionPublicId, $reason): bool {
            $session = DeviceSession::query()
                ->with('accessToken')
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
        $this->assertConnection($user);
        $accessToken = $user->currentAccessToken();

        if (! $accessToken instanceof PersonalAccessToken) {
            return false;
        }

        return DB::transaction(function () use ($user, $accessToken, $reason): bool {
            $session = DeviceSession::query()
                ->with('accessToken')
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
        $this->assertConnection($user);

        return DB::transaction(function () use ($user, $reason): int {
            $sessions = DeviceSession::query()
                ->with('accessToken')
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

    private function assertConnection(?User $user = null): void
    {
        $connection = DB::connection()->getName();
        $models = [new User, new DeviceSession, new DeviceSessionRefreshToken, new DevicePushRegistration, new PersonalAccessToken];
        if ($user !== null) {
            $models[] = $user;
            $models[] = $user->tokens()->getRelated();
        }
        foreach ($models as $model) {
            if ($model->getConnection()->getName() !== $connection) {
                throw new \LogicException('Native token lifecycle requires one default database connection.');
            }
        }
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

    private function epochIssuanceEnabled(): bool
    {
        $enabled = config('native_auth.epoch_issuance_enabled', false);
        if (! is_bool($enabled)) {
            throw new \LogicException('Native epoch issuance activation must be boolean.');
        }

        return $enabled;
    }

    private function expiries(bool $canonical): array
    {
        $access = $this->accessTtlMinutes();
        $refresh = $this->refreshTtlDays();
        if ($canonical) {
            $now = CarbonImmutable::now('UTC');

            return [$now->addSeconds($access * 60), $now->addSeconds($refresh * 86400)];
        }

        return [now()->addMinutes($access), now()->addDays($refresh)];
    }

    private function createAccess(User $user, string $deviceName, array $abilities, CarbonInterface $expires, bool $canonical): NewAccessToken
    {
        $access = $user->createToken($this->accessTokenName($deviceName), $abilities, $canonical ? null : $expires);
        if ($canonical) {
            $access->accessToken->forceFill(['api_policy_version' => NativeCredentialPolicy::VERSION,
                'api_token_profile' => NativeCredentialPolicy::profile($abilities), 'api_expires_epoch' => $expires->getTimestamp()])->save();
        }

        return $access;
    }

    /** Null means malformed/mixed policy, false is untouched legacy, true is recorded epoch policy. */
    private function recordedPolicy(DeviceSession $session, DeviceSessionRefreshToken $refresh): ?bool
    {
        $sessionParts = [$session->native_policy_version, $session->getAttributes()['access_expires_epoch'] ?? null];
        $refreshParts = [$refresh->native_policy_version, $refresh->getAttributes()['expires_epoch'] ?? null];
        if ($sessionParts === [null, null] && $refreshParts === [null, null]) {
            return false;
        }
        if (NativeCredentialPolicy::instant(...[...$sessionParts, $session->getRawOriginal('access_token_expires_at')]) === null
            || NativeCredentialPolicy::instant(...[...$refreshParts, $refresh->getRawOriginal('expires_at')]) === null) {
            return null;
        }
        try {
            NativeCredentialPolicy::profile($session->abilities ?? []);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return true;
    }

    private function accessTtlMinutes(): int
    {
        return NativeCredentialPolicy::ttl(config('native_auth.access_token_ttl_minutes', 15), 1440);
    }

    private function refreshTtlDays(): int
    {
        return NativeCredentialPolicy::ttl(config('native_auth.refresh_token_ttl_days', 30), 365);
    }

    private function assertPlatform(string $platform): void
    {
        if (! in_array($platform, ['ios', 'android'], true)) {
            throw new \InvalidArgumentException('Native device platform must be ios or android.');
        }
    }
}
