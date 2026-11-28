<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** New receipt DATETIME(6) values are UTC text, independent of SQL session/app timezone. */
final class UtcEvidenceDateTime implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : $this->parse($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        $date = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value)->utc() : $this->parse($value);

        return $date->format('Y-m-d H:i:s.u');
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : $this->parse($value)->format('Y-m-d\TH:i:s.u\Z');
    }

    private function parse(mixed $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }
        if (! is_string($value) || preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2})[T ]([0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})?\z/', $value, $parts) !== 1) {
            throw new InvalidArgumentException('Evidence observation requires an explicit datetime.');
        }
        $parsed = CarbonImmutable::parse($value, 'UTC');
        if ($parsed->format('Y-m-d H:i:s') !== $parts[1].' '.$parts[2]) {
            throw new InvalidArgumentException('Evidence observation datetime is invalid.');
        }

        return $parsed->utc();
    }
}
