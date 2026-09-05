<?php

namespace App\Services\MLB\Predictions;

use App\Services\Predictions\CanonicalSportCutoverReadinessService;

class MlbCanonicalCutoverReadinessService
{
    public function __construct(private readonly CanonicalSportCutoverReadinessService $readiness) {}

    /** @return array<string,mixed> */
    public function report(?int $season = null): array
    {
        return $this->readiness->report('mlb', $season);
    }
}
