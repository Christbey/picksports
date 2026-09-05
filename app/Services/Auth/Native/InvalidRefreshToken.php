<?php

namespace App\Services\Auth\Native;

use RuntimeException;

final class InvalidRefreshToken extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The refresh token is invalid.');
    }
}
