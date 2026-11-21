<?php

namespace App\Services\Predictions\Review;

use App\Models\AnalysisWorkItem;
use App\Models\PredictionReviewRevision;
use App\Services\Predictions\PredictionReviewDecisionPolicy;
use App\Services\Predictions\PredictionReviewInputBuilder;
use App\Services\Predictions\SportsAiPublishingDecisionPolicy;
use App\Services\Research\GameResearchService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** Read-only applicability check. Reading a game never creates work or calls AI. */
class CurrentPredictionReview
{
    public function __construct(
        private readonly PredictionReviewInputBuilder $inputs,
        private readonly PredictionReviewCoordinator $coordinator,
        private readonly ReviewFingerprint $fingerprints,
        private readonly PredictionReviewDecisionPolicy $decisions,
        private readonly GameResearchService $research,
        private readonly SportsAiPublishingDecisionPolicy $nflPolicy,
    ) {}

    /** @return array<string, mixed> */
    public function forGame(Model $game, string $sport): array
    {
        $empty = ['status' => 'unavailable', 'reviewed_at' => null, 'summary' => null, 'context_summary' => null,
            'key_factors' => [], 'risk_flags' => [], 'decision' => null, 'reason_code' => null];
        if (! $this->coordinator->enabled($sport)) {
            return [...$empty, 'reason_code' => 'review_disabled'];
        }
        if (! Schema::hasTable('analysis_work_items') || ! Schema::hasTable('prediction_review_revisions')) {
            return [...$empty, 'reason_code' => 'review_storage_unavailable'];
        }
        $input = $this->inputs->forGame($game, $sport);
        if ($input === null) {
            return [...$empty, 'reason_code' => 'selected_forecast_unavailable'];
        }
        $evidence = $this->research->forGame($game, $sport);
        $fingerprint = $this->fingerprints->forInput($input, $evidence)['fingerprint'];
        $work = AnalysisWorkItem::query()->where('sport', $sport)
            ->where('game_table', $game->getTable())->where('game_id', $game->getKey())
            ->where('stage', 'prediction_review')->first();
        if ($work === null) {
            return [...$empty, 'status' => 'pending', 'reason_code' => 'review_not_enqueued'];
        }
        $revision = PredictionReviewRevision::query()->where('analysis_work_item_id', $work->id)
            ->where('applicability', 'current')
            ->where('desired_fingerprint', $fingerprint)->orderByDesc('id')->first();
        if ($revision !== null && $revision->expires_at?->isFuture()
            && ! $revision->created_at?->isFuture()) {
            $output = (array) $revision->output;
            $decision = $this->decisions->constrain($input, $output);
            $summary = $sport === 'nfl'
                ? $this->nflPolicy->decide('nfl', ['decision_contract' => $input->decisionContract], ['summary' => '', ...$output], null)['summary']
                : $this->selectionSummary($decision);

            return [...$empty, 'status' => 'current', 'reviewed_at' => $revision->created_at?->toIso8601String(),
                'summary' => $summary, 'context_summary' => $output['summary'] ?? null,
                'key_factors' => array_values((array) ($output['key_factors'] ?? [])),
                'risk_flags' => array_values((array) ($output['risk_flags'] ?? [])),
                // Approval withdrawal, changed quote, and policy changes are reflected
                // in the freshly built input; never trust stored AI selection fields.
                'decision' => $decision];
        }
        if ($revision !== null) {
            return [...$empty, 'status' => 'expired', 'reason_code' => 'review_expired'];
        }
        if ($work->desired_fingerprint !== $fingerprint || $work->state === 'succeeded') {
            return [...$empty, 'status' => 'outdated', 'reason_code' => 'review_inputs_changed'];
        }

        return [...$empty, 'status' => $work->state === 'cancelled' ? 'unavailable' : $work->state,
            'reason_code' => $work->reason_code];
    }

    /** @param array<string, mixed> $decision */
    private function selectionSummary(array $decision): string
    {
        if (($decision['recommendation'] ?? 'pass') === 'pass' || ! is_array($decision['selection'] ?? null)) {
            return 'This review selects no bet. The model forecast remains separate.';
        }
        $selection = $decision['selection'];
        $side = (string) ($selection['pick_side'] ?? $selection['side'] ?? '');
        $line = is_numeric($selection['line'] ?? null) ? ' '.$selection['line'] : '';
        $price = is_numeric($selection['odds'] ?? null) ? ' at '.$selection['odds'] : '';

        return 'Review classification: '.$decision['bet_classification'].'. Existing deterministic selection: '
            .trim($side.' '.$decision['recommendation']).$line.$price.'.';
    }
}
