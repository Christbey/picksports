<?php

namespace App\Services\Api\V2;

use App\Models\PredictionFeatureSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class SportPredictionFeatureSnapshotQuery
{
    /**
     * @param  Collection<int, Model>  $predictions
     * @return Collection<int, PredictionFeatureSnapshot>
     */
    public function latestForPredictions(Collection $predictions): Collection
    {
        $prediction = $predictions->first();

        if (! $prediction instanceof Model) {
            return collect();
        }

        $predictionIds = $predictions
            ->map(fn (Model $prediction): int => (int) $prediction->getKey())
            ->filter()
            ->unique()
            ->values();

        if ($predictionIds->isEmpty()) {
            return collect();
        }

        return PredictionFeatureSnapshot::query()
            ->where('prediction_table', $prediction->getTable())
            ->whereIn('prediction_id', $predictionIds)
            ->latest('generated_at')
            ->latest('id')
            ->get()
            ->unique(fn (PredictionFeatureSnapshot $snapshot): int => (int) $snapshot->prediction_id)
            ->keyBy(fn (PredictionFeatureSnapshot $snapshot): int => (int) $snapshot->prediction_id);
    }
}
