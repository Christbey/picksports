<?php

namespace App\Services\Predictions;

use App\Application\Sports\ReadModels\CanonicalPredictionSummaryMapper;
use App\Application\Sports\ReadModels\PredictionSummary;
use App\Models\CanonicalPrediction;
use App\Models\SportEvent;

class CanonicalPredictionReadRepository
{
    public function __construct(private readonly CanonicalPredictionSummaryMapper $summaries) {}

    public function currentForEvent(SportEvent $event, string $phase = 'pregame'): ?CanonicalPrediction
    {
        return CanonicalPrediction::query()
            ->with(['markets', 'calculationRun.release'])
            ->where('sport_event_id', $event->getKey())
            ->where('phase', $phase)
            ->published()
            ->orderByDesc('revision')
            ->first();
    }

    public function findPublished(string $publicId): CanonicalPrediction
    {
        return CanonicalPrediction::query()
            ->with(['markets', 'sportEvent', 'calculationRun.release'])
            ->where('public_id', $publicId)
            ->published()
            ->firstOrFail();
    }

    public function summaryForEvent(SportEvent $event, string $phase = 'pregame'): ?PredictionSummary
    {
        $prediction = $this->currentForEvent($event, $phase);

        return $prediction === null ? null : $this->summaries->fromModel($prediction);
    }
}
