<?php

namespace App\Services\MLB;

use App\Support\Json\JsonAllocationBudget;
use RuntimeException;

/** Allocation admission before json_decode; the decoder still validates the JSON grammar. */
final class MlbTabularJsonBudget
{
    public static function inspect(string $raw, int $maximumBytes, int $maximumTokens = 20000, int $maximumDepth = 32): int
    {
        return JsonAllocationBudget::inspect($raw, $maximumBytes, $maximumTokens, $maximumDepth, 'MLB JSON');
    }

    /** Admit plain structure before allocating its complete encoded representation. */
    public static function encode(mixed $value, int $maximumBytes, int $maximumTokens = 20000, int $maximumDepth = 24): string
    {
        $bytes = $tokens = 0;
        self::measure($value, 0, $bytes, $tokens, $maximumBytes, $maximumTokens, $maximumDepth);
        $raw = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
        if (strlen($raw) !== $bytes) {
            throw new RuntimeException('MLB JSON encoded length differs from its admitted structure.');
        }
        self::inspect($raw, $maximumBytes, $maximumTokens, $maximumDepth);

        return $raw;
    }

    private static function measure(mixed $value, int $depth, int &$bytes, int &$tokens, int $maximumBytes, int $maximumTokens, int $maximumDepth): void
    {
        if (is_array($value)) {
            if ($depth >= $maximumDepth || count($value) > $maximumTokens) {
                throw new RuntimeException('MLB JSON structure exceeds its budget before encoding.');
            }
            $bytes += 2;
            $tokens += 2;
            self::checkEncodingBudget($bytes, $tokens, $maximumBytes, $maximumTokens);
            $list = array_is_list($value);
            $first = true;
            foreach ($value as $key => $entry) {
                if (! $first) {
                    $bytes++;
                    $tokens++;
                }
                $first = false;
                if (! $list) {
                    self::measure((string) $key, $depth + 1, $bytes, $tokens, $maximumBytes, $maximumTokens, $maximumDepth);
                    $bytes++;
                    $tokens++;
                }
                self::measure($entry, $depth + 1, $bytes, $tokens, $maximumBytes, $maximumTokens, $maximumDepth);
            }

            return;
        }
        $tokens++;
        if (is_string($value)) {
            if (strlen($value) > $maximumBytes || preg_match('//u', $value) !== 1) {
                throw new RuntimeException('MLB JSON string exceeds its budget or UTF-8 contract before encoding.');
            }
            $bytes += 2;
            for ($i = 0, $length = strlen($value); $i < $length; $i++) {
                $byte = ord($value[$i]);
                $bytes += ($byte === 34 || $byte === 92) ? 2
                    : ($byte < 32 ? (in_array($byte, [8, 9, 10, 12, 13], true) ? 2 : 6) : 1);
                self::checkEncodingBudget($bytes, $tokens, $maximumBytes, $maximumTokens);
            }
        } elseif ($value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
            $bytes += strlen(json_encode($value, JSON_THROW_ON_ERROR));
        } else {
            throw new RuntimeException('MLB JSON supports only finite plain scalar/array structure before encoding.');
        }
        self::checkEncodingBudget($bytes, $tokens, $maximumBytes, $maximumTokens);
    }

    private static function checkEncodingBudget(int $bytes, int $tokens, int $maximumBytes, int $maximumTokens): void
    {
        if ($bytes > $maximumBytes || $tokens > $maximumTokens) {
            throw new RuntimeException('MLB JSON byte/token budget exceeded before encoding.');
        }
    }

    public static function decode(string $raw, int $maximumBytes, int $maximumTokens = 20000): mixed
    {
        self::inspect($raw, $maximumBytes, $maximumTokens);

        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }
}
