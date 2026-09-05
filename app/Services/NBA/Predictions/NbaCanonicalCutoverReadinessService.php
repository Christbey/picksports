<?php

namespace App\Services\NBA\Predictions;

use App\Services\Predictions\CanonicalSportCutoverReadinessService;

class NbaCanonicalCutoverReadinessService
{
    public function __construct(private readonly CanonicalSportCutoverReadinessService $readiness) {}

    /** @return array<string, mixed> */
    public function report(?int $season = null): array
    {
        return $this->readiness->report('nba', $season);
    }
}
