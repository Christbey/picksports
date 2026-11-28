<?php

namespace App\Services\CFB\Scoring;

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\SourceAssessmentRevision;
use App\Services\CFB\Data\CfbSourceAssessmentRecorder;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonInterface;

/** Bounded live admission of frozen training provenance, not scientific model validation. */
class CfbTrainingSourceProvenance
{
    /** Only declared game timing/venue primitives; not full feature equivalence. */
    public function gameFeatureHash(Game $game): string
    {
        $event = $game->sportEvent;
        $values = [];
        foreach (['season_type', 'game_time', 'venue', 'venue_name', 'venue_city', 'venue_state'] as $field) {
            $values[$field] = $game->{$field} === null ? null : (string) $game->{$field};
        }
        $values += [
            'game_date' => $game->game_date?->format('Y-m-d'),
            'season' => (int) $game->season, 'week' => $game->week === null ? null : (int) $game->week,
            'neutral_site' => (bool) $game->neutral_site,
            'sport_event_id' => $game->sport_event_id === null ? null : (int) $game->sport_event_id,
            'starts_at' => $event?->starts_at?->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
        ];

        return app(CanonicalPayloadHasher::class)->hash($values);
    }

    public function rejection(array $artifact, ?CarbonInterface $asOf = null): ?string
    {
        $ids = $artifact['training_ids'] ?? [];
        $provenance = $artifact['source_assessment_provenance'] ?? [];
        $proofs = $provenance['games'] ?? [];
        if (($provenance['policy'] ?? null) !== 'cfb-observed-assessment-v1' || ! is_array($ids)
            || $ids === [] || count($ids) > 1000 || ! is_array($proofs)
            || count(array_unique($ids, SORT_REGULAR)) !== count($ids) || count($proofs) !== count($ids)) {
            return 'unknown_training_source_provenance';
        }
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1 || ! is_array($proofs[$id] ?? null) || count($proofs[$id]) !== 2) {
                return 'unknown_training_source_provenance';
            }
            foreach (['boxscore', 'plays'] as $component) {
                $proof = $proofs[$id][$component] ?? [];
                if (! is_int($proof['receipt_id'] ?? null) || $proof['receipt_id'] < 1 || ! is_int($proof['check_id'] ?? null) || $proof['check_id'] < 1
                    || ! is_string($proof['context_hash'] ?? null) || ! is_string($proof['source_hash'] ?? null)
                    || ! is_string($proof['game_feature_hash'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $proof['game_feature_hash'])
                    || ! preg_match('/^[a-f0-9]{64}$/', $proof['context_hash'] ?? '')
                    || ! preg_match('/^[a-f0-9]{64}$/', $proof['source_hash'] ?? '')) {
                    return 'unknown_training_source_provenance';
                }
            }
        }
        $hasher = app(CanonicalPayloadHasher::class);
        foreach (array_chunk($ids, 50) as $chunk) {
            $games = Game::with('sportEvent')->whereIn('id', $chunk)->get()->keyBy('id');
            $selected = collect($chunk)->flatMap(fn (int $id) => array_values($proofs[$id]));
            $checks = GameDataCheck::whereIn('id', $selected->pluck('check_id'))->get()->keyBy('id');
            $receipts = SourceAssessmentRevision::whereIn('id', $selected->pluck('receipt_id'))->get()->keyBy('id');
            $newer = SourceAssessmentRevision::query()->join('cfb_game_data_checks as source_check', 'source_check.id', '=', 'cfb_source_assessment_revisions.game_data_check_id')
                ->where('evidence_kind', '!=', 'imported_unknown_baseline')->where(function ($query) use ($chunk, $proofs): void {
                    foreach ($chunk as $id) {
                        foreach (['boxscore', 'plays'] as $component) {
                            $query->orWhere(fn ($row) => $row->where('source_check.game_id', $id)->where('source_check.component', $component)
                                ->where('cfb_source_assessment_revisions.id', '>', $proofs[$id][$component]['receipt_id']));
                        }
                    }
                })->exists();
            if ($newer || $games->count() !== count($chunk)) {
                return 'training_source_assessment_changed';
            }
            foreach ($chunk as $id) {
                $contextHash = $hasher->hash(app(CfbSourceAssessmentRecorder::class)->context($games[$id]));
                foreach (['boxscore', 'plays'] as $component) {
                    $proof = $proofs[$id][$component];
                    if ($proof['game_feature_hash'] !== $this->gameFeatureHash($games[$id])) {
                        return 'training_game_features_changed';
                    }
                    $check = $checks->get($proof['check_id']);
                    $receipt = $receipts->get($proof['receipt_id']);
                    if ($check === null || $receipt === null || $check->game_id !== $id || $check->component !== $component
                        || $receipt->game_data_check_id !== $check->id || $receipt->context_hash !== $contextHash
                        || $proof['context_hash'] !== $contextHash || $check->source_hash !== $proof['source_hash']
                        || $check->state !== 'complete' || $check->accepted_at === null
                        || $check->accepted_at->gt($asOf ?? now()) || $receipt->recorded_at->gt($asOf ?? now())
                        || ! in_array($receipt->evidence_kind, ['observed_assessment', 'acceptance_transition'], true)) {
                        return 'training_source_assessment_changed';
                    }
                    $projection = $check->attributesToArray();
                    unset($projection['created_at'], $projection['updated_at']);
                    if ($hasher->hash($projection) !== $hasher->hash($receipt->projection_snapshot)) {
                        return 'training_source_assessment_changed';
                    }
                }
            }
        }

        return null;
    }
}
