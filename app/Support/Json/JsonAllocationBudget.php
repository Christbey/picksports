<?php

namespace App\Support\Json;

use RuntimeException;

/** Lexical allocation limits only; json_decode remains responsible for JSON grammar. */
final class JsonAllocationBudget
{
    public static function inspect(string $raw, int $maximumBytes, int $maximumTokens = 20000, int $maximumDepth = 32, string $label = 'JSON'): int
    {
        if (strlen($raw) > $maximumBytes) {
            throw new RuntimeException($label.' byte budget exceeded before decode.');
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
                        throw new RuntimeException($label.' depth budget exceeded before decode.');
                    }
                } elseif ($byte === '}' || $byte === ']') {
                    if (--$depth < 0) {
                        throw new RuntimeException('Malformed '.$label.' structure.');
                    }
                }
            } elseif (! ctype_space($byte) && ! $atom) {
                $atom = true;
                $tokens++;
            } elseif (ctype_space($byte)) {
                $atom = false;
            }
            if ($tokens > $maximumTokens) {
                throw new RuntimeException($label.' token budget exceeded before decode.');
            }
        }
        if ($quoted || $depth !== 0) {
            throw new RuntimeException('Malformed '.$label.' structure.');
        }

        return $tokens;
    }
}
