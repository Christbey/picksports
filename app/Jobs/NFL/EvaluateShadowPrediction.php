<?php

namespace App\Jobs\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\PredictionFeatureSnapshot;
use App\Services\ML\ShadowModelOutputRecorder;
use App\Services\NFL\NflFullHistoricalShadowInferenceService;
use App\Services\Predictions\PredictionDeliverySource;
use App\Services\Predictions\SnapshotProvenanceResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class EvaluateShadowPrediction implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public int $snapshotId, public array $tabularFeatures = []) {}

    /**
     * Execute the job.
     */
    public function handle(NflFullHistoricalShadowInferenceService $inference, ShadowModelOutputRecorder $recorder): void
    {
        if (app(PredictionDeliverySource::class)->legacyWriterRetired('nfl')) {
            return;
        }
        $snapshot = PredictionFeatureSnapshot::query()->find($this->snapshotId);
        if (! $snapshot || $snapshot->sport !== 'nfl' || $snapshot->prediction_table !== 'nfl_predictions') {
            return;
        }
        $game = Game::query()->with(['homeTeam', 'awayTeam'])->find($snapshot->game_id);
        if (! $game || $game->status === 'STATUS_FINAL') {
            return;
        }
        $kickoff = app(SnapshotProvenanceResolver::class)->gameStartAt($game);
        if ($kickoff === null || now()->gte($kickoff)) {
            return;
        }
        $baseline = array_intersect_key($snapshot->outputs ?? [], array_flip([
            'predicted_spread', 'predicted_total', 'win_probability', 'confidence_score',
        ]));
        $shadow = $inference->evaluate($game, $baseline, $this->tabularFeatures);
        if ($shadow === null || now()->gte($kickoff)) {
            return;
        }
        $inferredAt = now();
        $shadow['baseline_generated_at'] = $snapshot->generated_at?->toIso8601String();
        $shadow['inferred_at'] = $inferredAt->toIso8601String();

        DB::transaction(function () use ($snapshot, $shadow, $recorder, $inferredAt): void {
            $prediction = Prediction::query()->lockForUpdate()->find($snapshot->prediction_id);
            $auditSnapshot = clone $snapshot;
            $auditSnapshot->generated_at = $inferredAt;
            $auditSnapshot->model_metadata = [...($snapshot->model_metadata ?? []), 'shadow_inference' => $shadow];
            $recorder->record($auditSnapshot);
            $latestId = PredictionFeatureSnapshot::query()->where('sport', 'nfl')
                ->where('prediction_table', 'nfl_predictions')->where('prediction_id', $snapshot->prediction_id)->max('id');
            if ($prediction && (int) $latestId === (int) $snapshot->id
                && $prediction->model_version === $snapshot->model_version) {
                $prediction->model_metadata = [...($prediction->model_metadata ?? []), 'shadow_inference' => $shadow];
                $prediction->timestamps = false;
                $prediction->save();
            }
        });
    }
}
