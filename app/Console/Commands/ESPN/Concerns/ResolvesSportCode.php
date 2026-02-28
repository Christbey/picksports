<?php

namespace App\Console\Commands\ESPN\Concerns;

trait ResolvesSportCode
{
    protected function sportCode(): string
    {
        if (static::SPORT_CODE === '') {
            throw new \LogicException('SPORT_CODE must be configured.');
        }

        return static::SPORT_CODE;
    }
}
