<?php

namespace App\Services\ProviderData\Refresh;

use RuntimeException;

final class RefreshBudget
{
    private float $deadline;

    private int $bytes = 0;

    private int $references = 0;

    private int $tokens = 0;

    public function __construct()
    {
        $seconds = config('provider_refresh.deadline_seconds', 90);
        if (! is_int($seconds) || $seconds < 1 || $seconds > 90) {
            throw new RuntimeException('Invalid refresh acquisition deadline.');
        }
        $this->deadline = hrtime(true) / 1e9 + $seconds;
    }

    public function remaining(): float
    {
        $remaining = $this->deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            throw new RuntimeException('Refresh aggregate acquisition deadline expired.');
        }

        return $remaining;
    }

    public function responseLimit(): int
    {
        // Small raw bound is intentional: it bounds JSON object materialization before structural admission.
        return $this->limit('maximum_response_bytes', 262144);
    }

    public function consumeRaw(string $body): void
    {
        $this->remaining();
        if (strlen($body) > $this->responseLimit()) {
            throw new RuntimeException('Refresh response byte limit exceeded.');
        }
        $this->inspectJsonStructure($body);
        $this->bytes += strlen($body);
        if ($this->bytes > $this->limit('maximum_acquisition_bytes', 1048576)) {
            throw new RuntimeException('Refresh aggregate byte limit exceeded.');
        }
    }

    /** Lexical allocation admission only; json_decode still owns actual JSON validation. */
    private function inspectJsonStructure(string $body): void
    {
        $quoted = false;
        $escaped = false;
        $depth = 0;
        $tokens = 0;
        $tokenLimit = $this->limit('maximum_json_tokens', 20000);
        $aggregateLimit = $this->limit('maximum_acquisition_json_tokens', 40000);
        $depthLimit = $this->limit('maximum_json_depth', 64);
        for ($index = 0, $length = strlen($body); $index < $length; $index++) {
            $byte = $body[$index];
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

                continue;
            }
            if (str_contains('{}[],:', $byte)) {
                if (++$tokens > $tokenLimit || ++$this->tokens > $aggregateLimit) {
                    throw new RuntimeException('Refresh JSON structure token limit exceeded before decode.');
                }
            }
            if ($byte === '{' || $byte === '[') {
                if (++$depth > $depthLimit) {
                    throw new RuntimeException('Refresh JSON structure depth limit exceeded before decode.');
                }
            } elseif ($byte === '}' || $byte === ']') {
                $depth--;
            }
        }
    }

    public function rows(int $count): void
    {
        $this->remaining();
        if ($count < 0 || $count > $this->limit('maximum_rows', 2000)) {
            throw new RuntimeException('Refresh source row limit exceeded.');
        }
    }

    public function reference(): void
    {
        $this->remaining();
        if (++$this->references > $this->limit('maximum_references', 100)) {
            throw new RuntimeException('Refresh reference limit exceeded.');
        }
    }

    private function limit(string $key, int $ceiling): int
    {
        $value = config('provider_refresh.'.$key, $ceiling);
        if (! is_int($value) || $value < 1 || $value > $ceiling) {
            throw new RuntimeException('Invalid refresh source ceiling.');
        }

        return $value;
    }
}
