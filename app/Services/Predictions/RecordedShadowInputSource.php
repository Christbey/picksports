<?php

namespace App\Services\Predictions;

use App\Application\Sports\ReadModels\CanonicalShadowSnapshot;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ShadowModelOutput;
use App\Services\Api\V2\SportContextResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class RecordedShadowInputSource
{
    public function __construct(
        private readonly CanonicalShadowInputs $canonical,
        private readonly SportContextResolver $sports,
    ) {}

    /** @param Collection<int, ShadowModelOutput> $outputs */
    public function preload(Collection $outputs, string $sport, bool $forMonitoring = false): void
    {
        $legacy = new EloquentCollection($outputs->whereNull('canonical_prediction_id')->all());
        if ($forMonitoring) {
            $legacy->loadMissing(['featureSnapshot' => fn (BelongsTo $query): BelongsTo => $query
                ->without('metadataPayload')->select(['id', 'outputs', 'pregame_safe', 'feature_hash'])]);
        } else {
            $legacy->loadMissing('featureSnapshot');
        }

        $native = new EloquentCollection($outputs->whereNotNull('canonical_prediction_id')->all());
        if ($this->sports->find($sport) === null) {
            $native->each(fn (ShadowModelOutput $output) => $output->setRelation('canonicalPrediction', null));

            return;
        }
        $native->loadMissing($forMonitoring ? [
            'canonicalPrediction:id,public_id,sport,sport_event_id,calculation_run_id,phase,publication_state,published_at,generated_at,model_version',
            'canonicalPrediction.markets',
            'canonicalPrediction.calculationRun:id,event_input_snapshot_id,calculation_release_id,status',
            'canonicalPrediction.calculationRun.release:id,status',
            'canonicalPrediction.calculationRun.inputSnapshot:id,sport,sport_event_id,phase,pregame_safety_status,captured_at,cutoff_at,latest_source_available_at,content_hash,schema_version',
            'canonicalPrediction.sportEvent:id,starts_at',
            'canonicalPrediction.sportEvent.'.$sport.'Game:id,sport_event_id',
        ] : [
            'canonicalPrediction.markets',
            'canonicalPrediction.calculationRun.inputSnapshot',
            'canonicalPrediction.calculationRun.release',
            'canonicalPrediction.sportEvent.'.$sport.'Game',
        ]);
    }

    public function fromOutput(ShadowModelOutput $output, string $sport): PredictionFeatureSnapshot|CanonicalShadowSnapshot|null
    {
        if ($output->canonical_prediction_id === null) {
            return $output->featureSnapshot;
        }
        $prediction = $output->canonicalPrediction;
        if ($prediction === null || $prediction->sport !== $sport || $output->sport !== $sport
            || $output->prediction_table !== 'predictions' || (int) $output->prediction_id !== (int) $prediction->id
            || $this->sports->find($sport) === null) {
            return null;
        }
        $snapshot = $this->canonical->fromPrediction($prediction);

        return $snapshot !== null && $snapshot->game_id === (int) $output->game_id ? $snapshot : null;
    }
}
