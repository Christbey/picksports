<?php

namespace App\DataTransferObjects\NFL;

/** Recorded result used by calibration; current roster/model state is intentionally absent. */
final readonly class CalibrationGameResult
{
    public function __construct(
        public int $id,
        public int $home_score,
        public int $away_score,
    ) {}
}
