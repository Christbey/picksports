<?php

namespace App\Services\NFL;

use RuntimeException;

/** Allocation admission before json_decode; the decoder still validates the JSON grammar. */
final class NflTabularJsonBudget
{
    public static function inspect(string $raw, int $maximumBytes, int $maximumTokens = 20000, int $maximumDepth = 32): int
    {
        if (strlen($raw) > $maximumBytes) {
            throw new RuntimeException('NFL JSON byte budget exceeded before decode.');
        }
        $depth = 0;
        $tokens = 0;
        $quoted = false;
        $escaped = false;
        $atom = false;
        for ($i = 0, $length = strlen($raw); $i < $length; $i++) {
            $byte = $raw[$i];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($byte === '\\') {
                    $escaped = true;
                } elseif ($byte === '"') {
                    $quoted = false;
                }

                continue;
            }
            if ($byte === '"') {
                $quoted = true;
                $atom = false;
                $tokens++;
            } elseif (str_contains('{}[],:', $byte)) {
                $tokens++;
                $atom = false;
                if ($byte === '{' || $byte === '[') {
                    if (++$depth > $maximumDepth) {
                        throw new RuntimeException('NFL JSON depth budget exceeded before decode.');
                    }
                } elseif ($byte === '}' || $byte === ']') {
                    if (--$depth < 0) {
                        throw new RuntimeException('Malformed NFL JSON structure.');
                    }
                }
            } elseif (! ctype_space($byte) && ! $atom) {
                $atom = true;
                $tokens++;
            } elseif (ctype_space($byte)) {
                $atom = false;
            }
            if ($tokens > $maximumTokens) {
                throw new RuntimeException('NFL JSON token budget exceeded before decode.');
            }
        }
        if ($quoted || $depth !== 0) {
            throw new RuntimeException('Malformed NFL JSON structure.');
        }

        return $tokens;
    }

    public static function decode(string $raw, int $maximumBytes, int $maximumTokens = 20000): mixed
    {
        self::inspect($raw, $maximumBytes, $maximumTokens);

        return json_decode($raw, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }
}
