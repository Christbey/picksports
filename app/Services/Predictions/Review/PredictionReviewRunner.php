<?php

namespace App\Services\Predictions\Review;

use App\AI\Agents\PredictionReviewAgent;
use App\Application\Sports\ReadModels\PredictionReviewInput;
use App\Models\AiGeneration;
use App\Models\AnalysisWorkItem;
use App\Models\PredictionReviewRevision;
use App\Services\AI\AiGenerationRecorder;
use App\Services\AI\AiProviderRateLimitCircuitBreaker;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Predictions\PredictionReviewDecisionPolicy;
use App\Services\Predictions\PredictionReviewInputBuilder;
use App\Services\Research\GameResearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class PredictionReviewRunner
{
    public function __construct(
        private readonly PredictionReviewCoordinator $coordinator,
        private readonly PredictionReviewInputBuilder $inputs,
        private readonly ReviewFingerprint $fingerprints,
        private readonly ReviewSpendAdmission $admission,
        private readonly AiGenerationRecorder $recorder,
        private readonly PredictionReviewDecisionPolicy $policy,
        private readonly SportContextResolver $sports,
        private readonly AiProviderRateLimitCircuitBreaker $circuit,
        private readonly NbaReviewReuse $nbaReuse,
    ) {}

    public function run(AnalysisWorkItem $item): string
    {
        if (! $this->coordinator->enabled($item->sport)) {
            return 'disabled';
        }
        $claimed = $this->claim($item);
        if ($claimed === null) {
            return $item->fresh()?->state === 'failed' ? 'failed' : 'not_due';
        }
        $item = $claimed;
        $fingerprint = $item->desired_fingerprint;
        $token = $item->lease_token;
        $generation = null;
        $started = microtime(true);
        try {
            [$game, $input, $evidence] = $this->current($item);
            if ($input === null || $this->fingerprints->forInput($input, $evidence)['fingerprint'] !== $fingerprint) {
                if ($game !== null) {
                    $this->coordinator->reconcileGame($game, $item->sport, $evidence);
                }
                $this->finish($item, $token, 'cancelled', 'inputs_changed');

                return 'outdated';
            }
            $spec = $item->execution_spec;
            if (trim((string) (config('ai.providers.'.$spec['provider'].'.key') ?: config('services.'.$spec['provider'].'.api_key', ''))) === ''
                && ! PredictionReviewAgent::isFaked()) {
                throw new ReviewDeferred('provider_not_configured', 3600);
            }
            // A process can die after recording a paid response but before storing
            // its revision. Recover that response instead of buying it twice.
            $generation = AiGeneration::where('purpose', 'prediction_review')->where('context_type', 'analysis_work_item')
                ->where('context_id', (string) $item->id)->where('status', 'completed')
                ->where('metadata->desired_fingerprint', $fingerprint)
                ->where('metadata->review_validated', true)
                ->whereNotIn('id', PredictionReviewRevision::whereNotNull('ai_generation_id')->select('ai_generation_id'))
                ->latest('id')->first();
            $output = data_get($generation?->metadata, 'review_output');
            if (! is_array($output)) {
                $generation = $this->admission->reserve($item);
                $response = app(PredictionReviewAgent::class)->prompt(
                    json_encode(['prediction_review_input' => $input->toArray(), 'research' => $evidence], JSON_THROW_ON_ERROR),
                    provider: $spec['provider'], model: $spec['model'],
                );
                if (! $response instanceof StructuredAgentResponse) {
                    throw new \UnexpectedValueException('review_structured_response_required');
                }
                $output = $response->toArray();
                $valid = true;
                try {
                    $this->validate($output);
                } catch (\UnexpectedValueException) {
                    $valid = false;
                }
                // Persist paid usage before validation: malformed output is still billable.
                $this->recorder->complete($generation, $output, $this->latency($started), [
                    'input' => $response->usage->promptTokens, 'output' => $response->usage->completionTokens,
                    'cached_input' => $response->usage->cacheReadInputTokens,
                ], metadata: ['invocation_id' => $response->invocationId, 'usage_cost_status' => 'unpriced_reserved', 'review_output' => $output, 'review_validated' => $valid]);
            }
            $this->validate($output);
            [$latestGame, $latestInput, $latestEvidence] = $this->current($item);
            $current = $latestInput !== null && $this->fingerprints->forInput($latestInput, $latestEvidence)['fingerprint'] === $fingerprint;
            $decision = $this->policy->constrain($input, $output);
            $hashes = $this->fingerprints->forInput($input, $evidence);
            $applicable = DB::transaction(function () use ($item, $generation, $output, $decision, $hashes, $current, $token, $evidence): bool {
                $locked = AnalysisWorkItem::lockForUpdate()->findOrFail($item->id);
                $applicable = $current && $locked->desired_fingerprint === $item->desired_fingerprint && $locked->lease_token === $token;
                $revision = PredictionReviewRevision::firstOrCreate(['ai_generation_id' => $generation->id], [
                    'analysis_work_item_id' => $item->id, 'sport_event_id' => $item->sport_event_id,
                    'canonical_prediction_id' => data_get($item->input_payload, 'identity.source') === 'canonical' ? data_get($item->input_payload, 'identity.prediction_id') : null,
                    'sport' => $item->sport, 'prediction_table' => data_get($item->input_payload, 'identity.prediction_table'),
                    'prediction_id' => data_get($item->input_payload, 'identity.prediction_id'),
                    'desired_fingerprint' => $item->desired_fingerprint, 'input_hash' => $hashes['input_hash'],
                    'execution_hash' => $hashes['execution_hash'], 'evidence_hash' => $hashes['evidence_hash'],
                    'input_payload' => $item->input_payload, 'execution_spec' => $item->execution_spec,
                    'output' => $output, 'decision' => $decision, 'applicability' => $applicable ? 'current' : 'superseded',
                    'expires_at' => min($item->deadline_at, now()->addMinutes(max(1, (int) config('prediction_review.valid_minutes', 360)))), 'created_at' => now(),
                ]);
                if ($applicable) {
                    $this->nbaReuse->record($revision, $evidence);
                    $locked->update(['state' => 'succeeded', 'reason_code' => null, 'lease_token' => null, 'lease_expires_at' => null]);
                }

                return $applicable;
            });
            if (! $current && $latestGame !== null) {
                $this->coordinator->reconcileGame($latestGame, $item->sport, $latestEvidence);
            }

            return $applicable ? 'succeeded' : 'outdated';
        } catch (ReviewDeferred $exception) {
            $this->finish($item, $token, 'deferred', $exception->getMessage(), $exception->retrySeconds);
            AnalysisWorkItem::whereKey($item->id)->where('desired_fingerprint', $fingerprint)->where('state', 'deferred')
                ->where('attempts', $item->attempts)->decrement('attempts');

            return 'deferred';
        } catch (Throwable $exception) {
            if ($generation !== null && $generation->fresh()?->status === 'running') {
                $this->recorder->fail($generation, 'review_failed', $this->latency($started), ['exception_class' => $exception::class, 'usage_cost_status' => 'unknown_reserved']);
            }
            $rateLimited = $this->circuit->isRateLimitFailure($exception->getMessage());
            if ($rateLimited) {
                $this->circuit->trip($item->execution_spec['provider'], $this->circuit->isQuotaExhausted($exception->getMessage()));
            }
            $retry = $item->attempts < max(1, (int) config('prediction_review.max_attempts', 3));
            $this->finish($item, $token, $retry ? 'deferred' : 'failed', $rateLimited ? 'provider_rate_limited' : 'review_failed',
                min(3600, max(1, (int) config('prediction_review.retry_seconds', 60)) * (2 ** min(6, $item->attempts))) + random_int(0, 15));
            report($exception);

            return $retry ? 'deferred' : 'failed';
        }
    }

    private function claim(AnalysisWorkItem $item): ?AnalysisWorkItem
    {
        return DB::transaction(function () use ($item): ?AnalysisWorkItem {
            $row = AnalysisWorkItem::lockForUpdate()->findOrFail($item->id);
            if ($row->deadline_at?->isPast()) {
                $row->update(['state' => 'cancelled', 'reason_code' => 'pregame_deadline_passed']);

                return null;
            }
            $abandoned = $row->state === 'running' && $row->lease_expires_at?->lte(now());
            if ((! in_array($row->state, ['pending', 'queued', 'deferred'], true) && ! $abandoned) || $row->next_attempt_at?->isFuture()) {
                return null;
            }
            if ($row->attempts >= max(1, (int) config('prediction_review.max_attempts', 3))) {
                $row->update(['state' => 'failed', 'reason_code' => 'attempt_limit_reached', 'lease_token' => null, 'lease_expires_at' => null]);

                return null;
            }
            $row->update(['state' => 'running', 'attempts' => $row->attempts + 1, 'last_attempt_at' => now(),
                'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addSeconds(max(60, (int) config('prediction_review.lease_seconds', 180)))]);

            return $row->refresh();
        });
    }

    /** @return array{mixed, ?PredictionReviewInput, array<string, mixed>} */
    private function current(AnalysisWorkItem $item): array
    {
        $gameClass = $this->sports->resolve($item->sport)->models['game'];
        $game = $gameClass::find($item->game_id);
        if ($game === null || ! in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true) || $item->deadline_at?->lte(now())) {
            return [$game, null, []];
        }
        $evidence = class_exists(GameResearchService::class) ? app(GameResearchService::class)->forGame($game, $item->sport) : [];

        return [$game, $this->inputs->forGame($game, $item->sport), $evidence];
    }

    private function finish(AnalysisWorkItem $item, string $token, string $state, string $reason, int $retry = 0): void
    {
        AnalysisWorkItem::whereKey($item->id)->where('desired_fingerprint', $item->desired_fingerprint)->where('lease_token', $token)
            ->update(['state' => $state, 'reason_code' => $reason, 'lease_token' => null, 'lease_expires_at' => null,
                'next_attempt_at' => $retry > 0 ? now()->addSeconds($retry) : null]);
    }

    private function validate(array $output): void
    {
        if (! is_string($output['summary'] ?? null) || trim($output['summary']) === ''
            || ! in_array($output['bet_classification'] ?? null, ['bet', 'lean', 'watch', 'pass'], true)
            || ! in_array($output['recommendation'] ?? null, ['moneyline', 'spread', 'total', 'prop', 'parlay_piece', 'pass'], true)) {
            throw new \UnexpectedValueException('review_output_invalid');
        }
    }

    private function latency(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
