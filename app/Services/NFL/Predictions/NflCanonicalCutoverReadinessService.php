<?php

namespace App\Services\NFL\Predictions;

use App\Services\Predictions\CanonicalSportCutoverReadinessService;

class NflCanonicalCutoverReadinessService
{
    public function __construct(private readonly CanonicalSportCutoverReadinessService $readiness) {}

    /** @return array<string,mixed> */
    public function report(?int $season = null): array
    {
        return $this->readiness->report('nfl', $season);
    }
}
