<?php

namespace App\Services\WNBA\Predictions;

use App\Services\Predictions\CanonicalSportCutoverReadinessService;

class WnbaCanonicalCutoverReadinessService
{
    public function __construct(private readonly CanonicalSportCutoverReadinessService $readiness) {}

    /** @return array<string, mixed> */
    public function report(?int $season = null): array
    {
        return $this->readiness->report('wnba', $season);
    }
}
