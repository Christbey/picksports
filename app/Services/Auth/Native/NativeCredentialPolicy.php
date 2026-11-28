<?php

namespace App\Services\Auth\Native;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class NativeCredentialPolicy
{
    public const VERSION = 'native-session-v1';

    public const PROFILES = [
        'native_mobile' => ['mobile:read', 'mobile:write'],
        'native_read_only' => ['mobile:read'],
        'native_write_only' => ['mobile:write'],
    ];

    public static function validEpoch(mixed $value): bool
    {
        return (is_int($value) || is_string($value)) && preg_match('/\A[1-9][0-9]{0,11}\z/', (string) $value)
            && (int) $value <= 253402300799;
    }

    public static function profile(array $abilities): string
    {
        foreach (self::PROFILES as $profile => $scopes) {
            if ($abilities === $scopes) {
                return $profile;
            }
        }

        throw new InvalidArgumentException('Native abilities must match a server-owned mobile scope profile.');
    }

    public static function ttl(mixed $value, int $maximum): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A[1-9][0-9]*\z/', (string) $value) !== 1
            || strlen((string) $value) > 6 || (int) $value > $maximum) {
            throw new InvalidArgumentException('Native token TTL must be a bounded positive whole duration.');
        }

        return (int) $value;
    }

    public static function instant(mixed $version, mixed $epoch, mixed $raw): ?CarbonImmutable
    {
        return $version === self::VERSION && self::validEpoch($epoch) && $raw === null
            ? CarbonImmutable::createFromTimestampUTC((int) $epoch) : null;
    }
}
