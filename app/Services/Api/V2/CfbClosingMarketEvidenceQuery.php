<?php

namespace App\Services\Api\V2;

use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionPostgameEnrichment;
use App\Services\CFB\CfbSnapshotCaptureIntegrity;
use App\Services\Predictions\CanonicalPayloadHasher;
use Illuminate\Support\Collection;

/** One batched evidence lookup; presentation never writes or refreshes providers. */
final class CfbClosingMarketEvidenceQuery
{
    public function __construct(private readonly CfbSnapshotCaptureIntegrity $integrity, private readonly CanonicalPayloadHasher $hasher) {}

    public function forPredictions(Collection $predictions, Collection $snapshots): Collection
    {
        $ids = $snapshots->pluck('id')->filter()->values();
        $records = collect();
        foreach ($ids->chunk(100) as $batch) {
            $records = $records->concat(PredictionPostgameEnrichment::whereIn('prediction_feature_snapshot_id', $batch)
                ->where('evidence_type', PredictionPostgameEnrichment::CLOSING_MARKET)
                ->whereIn('id', PredictionPostgameEnrichment::selectRaw('MAX(id)')->whereIn('prediction_feature_snapshot_id', $batch)
                    ->where('evidence_type', PredictionPostgameEnrichment::CLOSING_MARKET)->groupBy('prediction_feature_snapshot_id'))
                ->get());
        }
        $records = $records->keyBy('prediction_feature_snapshot_id');

        return $predictions->filter(fn ($prediction) => $prediction instanceof Prediction)->mapWithKeys(function (Prediction $prediction) use ($snapshots, $records): array {
            $snapshot = $snapshots->get($prediction->id);
            $record = $snapshot === null ? null : $records->get($snapshot->id);
            $game = $prediction->relationLoaded('game') ? $prediction->getRelation('game') : null;

            return [$prediction->id => $this->present($prediction, $game instanceof Game ? $game : null, $snapshot, $record)];
        });
    }

    private function present(Prediction $prediction, ?Game $game, ?PredictionFeatureSnapshot $snapshot, ?PredictionPostgameEnrichment $record): array
    {
        $reasons = [];
        $integrityStatus = 'legacy_unverified';
        if ($snapshot === null) {
            $reasons[] = 'entry_snapshot_missing';
        } elseif ($game === null) {
            $reasons[] = 'current_game_context_unavailable';
        } else {
            $proof = $this->integrity->inspect($snapshot, $game);
            $integrityStatus = $proof['status'];
            $reasons = $proof['reasons'];
        }
        if ($record === null) {
            $reasons[] = 'postgame_evidence_not_recorded';
        } else {
            if ($snapshot === null || $record->prediction_feature_snapshot_id !== $snapshot->id
                || $record->prediction_table !== $prediction->getTable() || (int) $record->prediction_id !== (int) $prediction->id
                || $record->game_table !== 'cfb_games' || (int) $record->game_id !== (int) $prediction->game_id || $record->sport !== 'cfb') {
                $reasons[] = 'postgame_evidence_identity_mismatch';
            }
            if ($game !== null && ! hash_equals($record->game_context_hash, $this->integrity->gameContextHash($game))) {
                $reasons[] = 'postgame_game_context_changed';
            }
            if (isset($proof) && ! hash_equals($record->entry_payload_hash, $proof['hash'])) {
                $reasons[] = 'postgame_entry_payload_changed';
            }
            $semantic = ['snapshot_id' => $record->prediction_feature_snapshot_id, 'type' => $record->evidence_type,
                ...$record->only(['source_feature_hash', 'entry_payload_hash', 'game_context_hash', 'quote_context_hash',
                    'source_integrity_status', 'availability_status', 'reason_codes', 'entry_context', 'source_quotes', 'output'])];
            $expectedEvidence = $this->hasher->hash([...$semantic, 'revision' => $record->revision,
                'supersedes' => $record->supersedes_enrichment_id, 'observed_at_utc' => $record->observed_at->format('Y-m-d H:i:s.u')]);
            if (! hash_equals($record->semantic_hash, $this->hasher->hash($semantic)) || ! hash_equals($record->evidence_hash, $expectedEvidence)) {
                $reasons[] = 'postgame_evidence_hash_mismatch';
            }
            $reasons = [...$reasons, ...(array) $record->reason_codes];
            if ($record->availability_status !== 'available' && $reasons === []) {
                $reasons[] = 'postgame_evidence_unavailable';
            }
        }
        $reasons = array_values(array_unique($reasons));
        $available = $record !== null && $reasons === [];

        return ['type' => PredictionPostgameEnrichment::CLOSING_MARKET, 'phase' => 'postgame',
            'availability_status' => $available ? 'available' : 'unavailable', 'reason_codes' => $reasons,
            'source_integrity_status' => $integrityStatus, 'receipt_id' => $record?->id, 'revision' => $record?->revision,
            'entry_snapshot_id' => $snapshot?->id, 'observed_at' => $record?->observed_at?->format('Y-m-d\TH:i:s.u\Z'),
            'source_max_captured_at' => $record?->source_max_captured_at?->format('Y-m-d\TH:i:s.u\Z'),
            'timestamp_policy' => 'legacy_quote_storage_and_app_cast;receipt_observed_at_utc',
            'output' => $available ? $record->output : null,
            'source_quotes' => $available ? $record->source_quotes : []];
    }
}
