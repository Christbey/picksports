<?php

namespace App\Services\CBB;

use App\Models\CBB\Prediction;
use App\Models\PredictionFeatureSnapshot;
use Illuminate\Database\Eloquent\Builder;

class CbbForecastEvidence
{
    public function cohort(Builder $query, string $cohort): Builder
    {
        if (! in_array($cohort, ['pregame', 'reconstruction', 'all'], true)) {
            throw new \InvalidArgumentException('Cohort must be pregame, reconstruction, or all.');
        }
        if ($cohort === 'all') {
            return $query;
        }

        return $query->whereIn('cbb_predictions.id', PredictionFeatureSnapshot::query()
            ->select('prediction_id')->where('sport', 'cbb')->where('prediction_table', 'cbb_predictions')
            ->where('pregame_safe', $cohort === 'pregame')
            ->when($cohort === 'pregame', fn (Builder $snapshots) => $snapshots->where('availability_status', 'observed_pregame')
                ->whereColumn('generated_at', '<', 'game_start_at'))
            ->when($cohort === 'reconstruction', fn (Builder $snapshots) => $snapshots->where('lineage_metadata->run_type', 'historical_reconstruction'))
            ->where('id', PredictionFeatureSnapshot::query()->from('prediction_feature_snapshots as latest_cbb_evidence')
                ->select('latest_cbb_evidence.id')->where('latest_cbb_evidence.sport', 'cbb')
                ->where('latest_cbb_evidence.prediction_table', 'cbb_predictions')
                ->whereColumn('latest_cbb_evidence.prediction_id', 'prediction_feature_snapshots.prediction_id')
                ->orderByDesc('latest_cbb_evidence.id')->limit(1)));
    }

    public function latest(Prediction $prediction): ?PredictionFeatureSnapshot
    {
        return PredictionFeatureSnapshot::query()->where('sport', 'cbb')->where('prediction_table', 'cbb_predictions')
            ->where('prediction_id', $prediction->id)->latest('id')->first();
    }
}
