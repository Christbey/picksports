<?php

namespace App\Services\MLB;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/** Semantic binding only: Python still verifies its own canonical schema hash before native admission. */
final class MlbTabularFeatureSchema
{
    private const MAX_BYTES = 65536;

    public function assertMatches(string $requestedPath, array $requested, string $returnedPath, array $returned, string $version): void
    {
        $yaml = $this->read($requestedPath, $requested);
        // This compiled schema contract has no YAML anchors, aliases or custom tags.
        // Refusal is intentionally conservative, including unusual quoted spellings.
        if (strpbrk($yaml, '&*!') !== false) {
            throw new RuntimeException('MLB remote schema tags and alias syntax are unsupported.');
        }
        // Inert map ASTs preserve {} versus []; PARSE_OBJECT (arbitrary tagged objects) is never enabled.
        $expected = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_OBJECT_FOR_MAP);
        $json = $this->read($returnedPath, $returned);
        MlbTabularJsonBudget::inspect($json, self::MAX_BYTES, 10000, 16);
        // Do not convert out-of-range JSON integers into strings and erase their scalar type.
        $actual = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        $nodes = 0;
        if (! $expected instanceof \stdClass || ! $actual instanceof \stdClass
            || ! $this->equal($expected, $actual, 0, $nodes)
            || ($actual->schema_version ?? null) !== $version
            || ! ($expected->features ?? null) instanceof \stdClass || ! ($actual->features ?? null) instanceof \stdClass
            || array_keys(get_object_vars($expected->features)) !== array_keys(get_object_vars($actual->features))) {
            throw new RuntimeException('MLB returned schema differs from the exact requested schema or feature order.');
        }
    }

    private function read(string $path, array $descriptor): string
    {
        if (count($descriptor) !== 2 || ! is_int($descriptor['bytes'] ?? null) || $descriptor['bytes'] < 1 || $descriptor['bytes'] > self::MAX_BYTES
            || ! is_string($descriptor['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $descriptor['sha256']) !== 1
            || ! is_file($path) || is_link($path)) {
            throw new RuntimeException('MLB remote schema descriptor exceeds its fixed byte contract.');
        }
        $input = fopen($path, 'rb');
        if ($input === false) {
            throw new RuntimeException('MLB remote schema cannot be opened.');
        }
        try {
            $body = stream_get_contents($input, $descriptor['bytes'] + 1);
            if (! is_string($body) || strlen($body) !== $descriptor['bytes'] || ! feof($input)
                || ! hash_equals($descriptor['sha256'], hash('sha256', $body))) {
                throw new RuntimeException('MLB remote schema consumed bytes differ from their descriptor.');
            }

            return $body;
        } finally {
            fclose($input);
        }
    }

    private function equal(mixed $left, mixed $right, int $depth, int &$nodes): bool
    {
        if (++$nodes > 10000 || $depth > 16) {
            throw new RuntimeException('MLB remote schema semantic structure exceeds its budget.');
        }
        foreach ([$left, $right] as $value) {
            if ((is_int($value) || is_float($value)) && (! is_finite((float) $value) || abs($value) > 9007199254740992)) {
                throw new RuntimeException('MLB remote schema numbers exceed the exact supported scalar range.');
            }
        }
        $leftMap = $left instanceof \stdClass;
        $rightMap = $right instanceof \stdClass;
        if ($leftMap !== $rightMap) {
            return false;
        }
        if ($leftMap) {
            $left = get_object_vars($left);
            $right = get_object_vars($right);
            ksort($left, SORT_STRING);
            ksort($right, SORT_STRING);
        }
        if (is_array($left) && is_array($right)) {
            if ((! $leftMap && array_is_list($left) !== array_is_list($right)) || count($left) !== count($right)) {
                return false;
            }
            if (array_keys($left) !== array_keys($right)) {
                return false;
            }
            foreach ($left as $key => $value) {
                if (! $this->equal($value, $right[$key], $depth + 1, $nodes)) {
                    return false;
                }
            }

            return true;
        }
        if ((is_int($left) && is_float($right)) || (is_float($left) && is_int($right))) {
            return (float) $left === (float) $right;
        }

        return get_debug_type($left) === get_debug_type($right) && $left === $right;
    }
}
