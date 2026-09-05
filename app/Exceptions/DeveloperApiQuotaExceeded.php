<?php

namespace App\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

class DeveloperApiQuotaExceeded extends RuntimeException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $used,
        public readonly CarbonImmutable $resetsAt,
    ) {
        parent::__construct('The developer API quota has been exhausted.');
    }
}
