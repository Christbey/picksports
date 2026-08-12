<?php

namespace App\Services\Api\V2;

use App\Models\PredictionFeatureSnapshot;

final readonly class SportPredictionPresentationData
{
    /**
     * @param  array<int, array<string, mixed>>  $periodInsights
     * @param  array<string, mixed>|null  $recommendation
     * @param  array<string, mixed>|null  $marketAwareProjection
     * @param  array<string, mixed>|null  $valueSignal
     */
    public function __construct(
        public array $periodInsights = [],
        public ?PredictionFeatureSnapshot $featureSnapshot = null,
        public ?array $recommendation = null,
        public ?array $marketAwareProjection = null,
        public ?array $valueSignal = null,
    ) {}
}
