<?php

namespace App\DataTransferObjects\MLB;

use App\Models\PredictionFeatureSnapshot;

/** Export-only context stays separate from persisted feature snapshot attributes. */
final readonly class TrainingExportSnapshot
{
    public function __construct(
        public PredictionFeatureSnapshot $snapshot,
        public string $predictionHorizon,
    ) {}
}
