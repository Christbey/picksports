<?php

namespace App\Services\CBB\Predictions;

use App\Services\Predictions\CanonicalSportCutoverReadinessService;

class CbbCanonicalCutoverReadinessService
{
    public function __construct(private readonly CanonicalSportCutoverReadinessService $readiness) {}

    /** @return array<string, mixed> */
    public function report(?int $season = null): array
    {
        return $this->readiness->report('cbb', $season);
    }
}
