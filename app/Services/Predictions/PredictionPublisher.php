<?php

namespace App\Services\Predictions;

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CanonicalPrediction;
use App\Models\PredictionMarket;
use Illuminate\Support\Facades\DB;

class PredictionPublisher
{
    public function __construct(private readonly CanonicalPayloadHasher $hasher) {}

    public function publish(CanonicalPrediction $prediction): CanonicalPrediction
    {
        return DB::transaction(function () use ($prediction): CanonicalPrediction {
            $prediction = CanonicalPrediction::query()
                ->with(['calculationRun', 'markets', 'sportEvent'])
                ->lockForUpdate()
                ->findOrFail($prediction->getKey());

            if ($prediction->publication_state === 'published') {
                return $prediction;
            }

            if ($prediction->publication_state !== 'draft') {
                throw new PredictionLifecycleException('Only draft canonical prediction revisions can be published.');
            }

            if ($prediction->calculationRun?->status !== 'succeeded'
                || ! hash_equals((string) $prediction->output_hash, (string) $prediction->calculationRun?->output_hash)) {
                throw new PredictionLifecycleException('A prediction requires a successful matching calculation run before publication.');
            }

            if (! hash_equals((string) $prediction->output_hash, $this->storedOutputHash($prediction))) {
                throw new PredictionLifecycleException('Canonical prediction output changed after its successful calculation run.');
            }

            if ($prediction->phase === 'pregame'
                && ($prediction->sportEvent?->starts_at === null || now()->greaterThanOrEqualTo($prediction->sportEvent->starts_at))) {
                throw new PredictionLifecycleException('Pregame canonical predictions must be published before the event starts.');
            }

            $current = CanonicalPrediction::query()
                ->where('sport_event_id', $prediction->sport_event_id)
                ->where('phase', $prediction->phase)
                ->where('publication_state', 'published')
                ->whereKeyNot($prediction->getKey())
                ->lockForUpdate()
                ->first();

            if ($current !== null) {
                $current->update([
                    'publication_state' => 'superseded',
                    'superseded_at' => now(),
                ]);
            }

            $prediction->update([
                'publication_state' => 'published',
                'published_at' => now(),
            ]);

            return $prediction->fresh(['markets', 'calculationRun.release', 'calculationRun.inputSnapshot']);
        });
    }

    private function storedOutputHash(CanonicalPrediction $prediction): string
    {
        $markets = $prediction->markets
            ->sortBy(fn (PredictionMarket $market): string => $market->market_type.':'.$market->selection)
            ->map(fn (PredictionMarket $market): array => [
                'market_type' => $market->market_type,
                'selection' => $market->selection,
                'projected_line' => $market->projected_line === null ? null : (float) $market->projected_line,
                'probability' => $market->probability === null ? null : (float) $market->probability,
                'confidence_score' => $market->confidence_score === null ? null : (float) $market->confidence_score,
            ])
            ->values()
            ->all();

        return $this->hasher->hash([
            'markets' => $markets,
            'metadata' => (array) $prediction->output_metadata,
        ]);
    }
}
