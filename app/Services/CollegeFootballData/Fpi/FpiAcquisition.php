<?php

namespace App\Services\CollegeFootballData\Fpi;

/** An instance-owned reservation; its public fields alone grant no commit authority. */
final readonly class FpiAcquisition
{
    public function __construct(public int $season, public int $week, public int $attemptId, public int $sequence) {}
}
