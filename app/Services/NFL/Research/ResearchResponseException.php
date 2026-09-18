<?php

namespace App\Services\NFL\Research;

use RuntimeException;
use Throwable;

class ResearchResponseException extends RuntimeException
{
    public function __construct(string $message, public readonly array $telemetry, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
