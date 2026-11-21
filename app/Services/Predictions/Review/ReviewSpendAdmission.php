<?php

namespace App\Services\Predictions\Review;

use App\Models\AiGeneration;
use App\Models\AnalysisWorkItem;
use App\Services\AI\AiGenerationRecorder;
use App\Services\AI\AiProviderRateLimitCircuitBreaker;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class ReviewSpendAdmission
{
    public function __construct(private readonly AiGenerationRecorder $recorder, private readonly AiProviderRateLimitCircuitBreaker $circuit) {}

    public function reserve(AnalysisWorkItem $item): AiGeneration
    {
        $spec = $item->execution_spec;
        $cooldown = $this->circuit->retryAfterSeconds($spec['provider']);
        if ($cooldown > 0) {
            throw new ReviewDeferred('provider_cooldown', $cooldown);
        }

        try {
            return Cache::lock('prediction-review:spend-admission', 15)->block(3, function () use ($item, $spec): AiGeneration {
                $rows = AiGeneration::where('started_at', '>=', now()->subDay())->get();
                $reservation = max(0.001, (float) config('prediction_review.budget.reservation_usd', 0.05));
                $cost = fn (AiGeneration $row): float => $row->cost_usd !== null ? (float) $row->cost_usd
                    : max($reservation, (float) data_get($row->metadata, 'reserved_cost_usd', 0));
                $sport = $rows->filter(fn ($row) => data_get($row->metadata, 'sport') === $item->sport
                    || ($item->sport === 'nfl' && $row->context_type === 'nfl_game'));
                $event = $sport->filter(fn ($row) => data_get($row->metadata, 'game_table') === $item->game_table
                    && (int) data_get($row->metadata, 'game_id') === (int) $item->game_id
                    || ($item->sport === 'nfl' && $row->context_type === 'nfl_game' && (int) $row->context_id === (int) $item->game_id));
                $uncertainWindow = max(60, (int) config('prediction_review.unknown_usage_retry_seconds', 3600));
                if ($event->contains(fn ($row) => data_get($row->metadata, 'desired_fingerprint') === $item->desired_fingerprint
                    && ($row->status === 'running' || data_get($row->metadata, 'usage_cost_status') === 'unknown_reserved')
                    && $row->started_at?->gt(now()->subSeconds($uncertainWindow)))) {
                    throw new ReviewDeferred('previous_usage_unknown', $uncertainWindow);
                }
                foreach ([['application', $rows], ['sport', $sport], ['event', $event]] as [$scope, $selected]) {
                    if ($selected->sum($cost) + $reservation > (float) config('prediction_review.budget.'.$scope.'_daily_usd', 0)) {
                        throw new ReviewDeferred($scope.'_budget_reached', 3600);
                    }
                }
                $attemptLimit = max(1, (int) config('prediction_review.budget.event_daily_attempts', 3));
                if ($item->deadline_at?->gt(now()->addHours(max(1, (int) config('prediction_review.budget.pregame_reserve_hours', 12))))) {
                    $attemptLimit = max(0, $attemptLimit - max(0, (int) config('prediction_review.budget.pregame_reserved_attempts', 1)));
                }
                if ($event->count() >= $attemptLimit) {
                    throw new ReviewDeferred('event_attempt_limit_reached', 3600);
                }

                return $this->recorder->start(
                    purpose: 'prediction_review', promptVersion: $spec['prompt_version'], provider: $spec['provider'], model: $spec['model'],
                    input: ['input' => $item->input_payload, 'evidence' => $item->evidence], contextType: 'analysis_work_item', contextId: (string) $item->id,
                    metadata: ['reserved_cost_usd' => $reservation, 'sport' => $item->sport, 'game_table' => $item->game_table,
                        'game_id' => $item->game_id, 'desired_fingerprint' => $item->desired_fingerprint],
                );
            });
        } catch (LockTimeoutException) {
            throw new ReviewDeferred('budget_admission_busy', 30);
        }
    }
}
