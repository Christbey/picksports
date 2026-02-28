<?php

namespace App\Console\Commands\Concerns;

trait ResolvesRequiredConfig
{
    protected function requiredString(string $value, string $message): string
    {
        if ($value === '') {
            throw new \RuntimeException($message);
        }

        return $value;
    }

    protected function requiredNonDefaultString(string $value, string $default, string $message): string
    {
        if ($value === $default) {
            throw new \RuntimeException($message);
        }

        return $value;
    }
}
