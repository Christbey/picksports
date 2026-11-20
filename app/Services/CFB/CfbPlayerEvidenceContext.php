<?php

namespace App\Services\CFB;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final readonly class CfbPlayerEvidenceContext
{
    public function __construct(
        public Collection $history,
        public Collection $injuries,
        public CarbonImmutable $asOf,
        public array $availability = [],
    ) {}
}
