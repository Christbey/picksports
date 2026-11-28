<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** New Elo chronology is stored as UTC DATETIME text, independently of app/session timezone. */
class UtcEloDateTime implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : $this->parse($value)->utc();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            throw new InvalidArgumentException('Elo chronology requires an explicit datetime.');
        }
        $date = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : $this->parse($value);

        return $date->utc()->format('Y-m-d H:i:s.u');
    }

    /** Supported game columns contain a UTC date (SQLite may retain its time suffix) and an observed time. */
    public function observed(string $date, string $time): CarbonImmutable
    {
        if (preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2})(?: [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?)?\z/', $date, $parts) !== 1) {
            throw new InvalidArgumentException('Elo source date is invalid.');
        }

        return $this->parse($parts[1].' '.$time)->utc();
    }

    private function parse(string $value): CarbonImmutable
    {
        if (preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2})[T ]([0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})?\z/', $value, $parts) !== 1) {
            throw new InvalidArgumentException('Elo chronology requires an explicit date and time.');
        }
        $date = CarbonImmutable::parse($value, 'UTC');
        if ($date->format('Y-m-d H:i:s') !== $parts[1].' '.$parts[2]) {
            throw new InvalidArgumentException('Elo chronology is not a valid observed datetime.');
        }

        return $date;
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        $date = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : $this->parse($value);

        return $date->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
