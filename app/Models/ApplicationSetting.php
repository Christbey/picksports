<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use JsonException;

class ApplicationSetting extends Model
{
    use HasFactory;

    private const ENCODED_TYPE_KEY = '__application_setting_type';

    private const ENCODED_VALUE_KEY = 'value';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $value = static::query()
            ->where('key', $key)
            ->value('value');

        if ($value === null) {
            return $default;
        }

        return static::decodeValue($value);
    }

    public static function setValue(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => static::encodeValue($value)]
        );
    }

    private static function encodeValue(mixed $value): string
    {
        return json_encode([
            self::ENCODED_TYPE_KEY => get_debug_type($value),
            self::ENCODED_VALUE_KEY => $value,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function decodeValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $value;
        }

        if (
            ! is_array($decoded)
            || ! array_key_exists(self::ENCODED_TYPE_KEY, $decoded)
            || ! array_key_exists(self::ENCODED_VALUE_KEY, $decoded)
        ) {
            return $value;
        }

        return $decoded[self::ENCODED_VALUE_KEY];
    }
}
