<?php

namespace App\Services\Predictions;

final readonly class PredictionResourcePresentationData
{
    /**
     * @param  array<string, bool>  $fieldAccess
     * @param  array<string, mixed>|null  $narrative
     * @param  array<string, mixed>|null  $aiAnalysis
     * @param  array<int, array<string, mixed>>|null  $bettingValue
     * @param  array<string, mixed>|null  $bettingValueSummary
     * @param  array<string, mixed>|null  $predictionAnalysis
     * @param  array<string, mixed>|null  $marketAwareProjection
     */
    public function __construct(
        public array $fieldAccess = [],
        public bool $includeGame = true,
        public ?array $narrative = null,
        public ?array $aiAnalysis = null,
        public ?array $bettingValue = null,
        public ?array $bettingValueSummary = null,
        public ?array $predictionAnalysis = null,
        public ?array $marketAwareProjection = null,
    ) {}

    public function canView(string $field): bool
    {
        return $this->fieldAccess[$field] ?? false;
    }
}
