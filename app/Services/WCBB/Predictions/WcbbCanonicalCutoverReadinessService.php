<?php

namespace App\Services\WCBB\Predictions;

use App\Services\Predictions\CanonicalSportCutoverReadinessService;

class WcbbCanonicalCutoverReadinessService
{
    public function __construct(private readonly CanonicalSportCutoverReadinessService $readiness) {}

    /** @return array<string, mixed> */
    public function report(?int $season = null): array
    {
        return $this->readiness->report('wcbb', $season);
    }
}
