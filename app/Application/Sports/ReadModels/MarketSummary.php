<?php

namespace App\Application\Sports\ReadModels;

final readonly class MarketSummary
{
    public function __construct(
        public string $type,
        public string $selection,
        public ?float $projectedLine,
        public ?float $probability,
        public ?float $confidenceScore,
    ) {}
}
