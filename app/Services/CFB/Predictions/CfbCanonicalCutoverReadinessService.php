<?php

namespace App\Services\CFB\Predictions;

use App\Services\Predictions\CanonicalSportCutoverReadinessService;

class CfbCanonicalCutoverReadinessService
{
    public function __construct(private readonly CanonicalSportCutoverReadinessService $readiness) {}

    /** @return array<string,mixed> */
    public function report(?int $season = null, ?int $week = null): array
    {
        return $this->readiness->report('cfb', $season, $week);
    }
}
