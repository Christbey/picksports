<?php

namespace App\Console\Commands\ESPN\Concerns;

trait ResolvesJobClass
{
    /**
     * @return class-string
     */
    protected function requiredJobClass(string $value, string $constantName): string
    {
        if ($value === '') {
            throw new \LogicException("{$constantName} must be configured.");
        }

        return $value;
    }
}
