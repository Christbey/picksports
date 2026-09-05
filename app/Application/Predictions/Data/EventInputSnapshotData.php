<?php

namespace App\Application\Predictions\Data;

use Carbon\CarbonImmutable;

final readonly class EventInputSnapshotData
{
    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, string|null>  $sourceTimestamps
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $schemaVersion,
        public array $inputs,
        public CarbonImmutable $capturedAt,
        public ?CarbonImmutable $cutoffAt = null,
        public ?CarbonImmutable $latestSourceAvailableAt = null,
        public array $sourceTimestamps = [],
        public string $pregameSafetyStatus = 'unknown',
        public ?string $objectUri = null,
        public array $metadata = [],
    ) {}
}
