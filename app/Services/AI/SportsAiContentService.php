<?php

namespace App\Services\AI;

use App\AI\Agents\DailyDigestSummaryAgent;
use App\AI\Agents\DataFreshnessAgent;
use App\AI\Agents\MarketReadinessAgent;
use App\AI\Agents\ModelAuditAgent;
use App\AI\Agents\PlayerPropNarrativeAgent;
use App\AI\Agents\PublishingGuardrailAgent;
use App\AI\Agents\SportsDailyPredictionAnalysisAgent;
use App\AI\Agents\SportsPredictionNarrativeAgent;
use App\AI\Agents\ValidationReviewSummaryAgent;
use App\Models\ValidationFinding;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class SportsAiContentService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   recommendation:string,
     *   bet_classification:string,
     *   ai_confidence:int,
     *   analysis_confidence:int,
     *   summary:string,
     *   key_factors:array<int,string>,
     *   risk_flags:array<int,string>,
     *   reason_codes:array<int,string>,
     *   market_notes:array{moneyline:string|null,spread:string|null,total:string|null,props:string|null},
     *   generated_by:string
     * }|null
     */
    public function generateDailyPredictionAnalysis(
        array $payload,
        ?string $provider = null,
        ?string $model = null,
    ): ?array {
        if (! config('ai.features.daily_prediction_analysis.enabled', true)) {
            return null;
        }

        $provider ??= (string) config('ai.features.daily_prediction_analysis.provider', 'openai');
        $model ??= (string) config('ai.features.daily_prediction_analysis.model', 'gpt-4o-mini');

        if (! $this->providerIsConfigured($provider)) {
            return null;
        }

        try {
            $response = app(SportsDailyPredictionAnalysisAgent::class)->prompt(
                $this->buildDailyPredictionAnalysisPrompt($payload),
                provider: $provider,
                model: $model,
            );

            if (! $response instanceof StructuredAgentResponse) {
                logger()->warning('Daily prediction analysis agent returned an unexpected response type.', [
                    'provider' => $provider,
                    'response_class' => $response::class,
                ]);

                return null;
            }

            $analysis = $this->normalizeDailyPredictionAnalysisPayload($response->toArray());

            if (! $analysis) {
                logger()->warning('Daily prediction analysis response failed normalization.', [
                    'provider' => $provider,
                    'model' => $response->meta->model ?? $model,
                ]);

                return null;
            }

            $analysis['generated_by'] = (string) ($response->meta->provider ?? $provider)
                .':'
                .(string) ($response->meta->model ?? $model);

            return $analysis;
        } catch (Throwable $exception) {
            logger()->warning('Daily prediction analysis request threw exception.', [
                'provider' => $provider,
                'model' => $model,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $analysis
     * @return array{
     *   model_status:string,
     *   signal_score:int,
     *   confidence_alignment:string,
     *   summary:string,
     *   supporting_factors:array<int,string>,
     *   model_risk_flags:array<int,string>,
     *   reason_codes:array<int,string>,
     *   recommended_classification:string,
     *   generated_by:string
     * }|null
     */
    public function generateModelAuditAssessment(
        array $payload,
        ?array $analysis,
        ?string $provider = null,
        ?string $model = null,
    ): ?array {
        if (! config('ai.features.model_audit_review.enabled', true)) {
            return null;
        }

        $provider ??= (string) config('ai.features.model_audit_review.provider', 'openai');
        $model ??= (string) config('ai.features.model_audit_review.model', 'gpt-4o-mini');

        if (! $this->providerIsConfigured($provider)) {
            return null;
        }

        try {
            $response = app(ModelAuditAgent::class)->prompt(
                $this->buildModelAuditPrompt($payload, $analysis),
                provider: $provider,
                model: $model,
            );

            if (! $response instanceof StructuredAgentResponse) {
                logger()->warning('Model audit agent returned an unexpected response type.', [
                    'provider' => $provider,
                    'response_class' => $response::class,
                ]);

                return null;
            }

            $assessment = $this->normalizeModelAuditPayload($response->toArray());

            if (! $assessment) {
                logger()->warning('Model audit response failed normalization.', [
                    'provider' => $provider,
                    'model' => $response->meta->model ?? $model,
                ]);

                return null;
            }

            $assessment['generated_by'] = (string) ($response->meta->provider ?? $provider)
                .':'
                .(string) ($response->meta->model ?? $model);

            return $assessment;
        } catch (Throwable $exception) {
            logger()->warning('Model audit request threw exception.', [
                'provider' => $provider,
                'model' => $model,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   freshness_status:string,
     *   trust_score:int,
     *   latest_data_fresh_at:string|null,
     *   summary:string,
     *   stale_inputs:array<int,string>,
     *   missing_inputs:array<int,string>,
     *   blocked_outputs:array<int,string>,
     *   recommended_actions:array<int,string>,
     *   generated_by:string
     * }|null
     */
    public function generateDataFreshnessAssessment(array $payload, ?string $provider = null, ?string $model = null): ?array
    {
        if (! config('ai.features.data_freshness_review.enabled', true)) {
            return null;
        }

        $provider ??= (string) config('ai.features.data_freshness_review.provider', 'openai');
        $model ??= (string) config('ai.features.data_freshness_review.model', 'gpt-4o-mini');

        if (! $this->providerIsConfigured($provider)) {
            return null;
        }

        try {
            $response = app(DataFreshnessAgent::class)->prompt(
                $this->buildDataFreshnessPrompt($payload),
                provider: $provider,
                model: $model,
            );

            if (! $response instanceof StructuredAgentResponse) {
                logger()->warning('Data freshness agent returned an unexpected response type.', [
                    'provider' => $provider,
                    'response_class' => $response::class,
                ]);

                return null;
            }

            $assessment = $this->normalizeDataFreshnessPayload($response->toArray());

            if (! $assessment) {
                logger()->warning('Data freshness response failed normalization.', [
                    'provider' => $provider,
                    'model' => $response->meta->model ?? $model,
                ]);

                return null;
            }

            $assessment['generated_by'] = (string) ($response->meta->provider ?? $provider)
                .':'
                .(string) ($response->meta->model ?? $model);

            return $assessment;
        } catch (Throwable $exception) {
            logger()->warning('Data freshness request threw exception.', [
                'provider' => $provider,
                'model' => $model,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   market_status:string,
     *   readiness_score:int,
     *   summary:string,
     *   available_markets:array<int,string>,
     *   missing_markets:array<int,string>,
     *   risk_flags:array<int,string>,
     *   recommended_actions:array<int,string>,
     *   publishable_recommendation:string,
     *   generated_by:string
     * }|null
     */
    public function generateMarketReadinessAssessment(array $payload, ?string $provider = null, ?string $model = null): ?array
    {
        if (! config('ai.features.market_readiness_review.enabled', true)) {
            return null;
        }

        $provider ??= (string) config('ai.features.market_readiness_review.provider', 'openai');
        $model ??= (string) config('ai.features.market_readiness_review.model', 'gpt-4o-mini');

        if (! $this->providerIsConfigured($provider)) {
            return null;
        }

        try {
            $response = app(MarketReadinessAgent::class)->prompt(
                $this->buildMarketReadinessPrompt($payload),
                provider: $provider,
                model: $model,
            );

            if (! $response instanceof StructuredAgentResponse) {
                logger()->warning('Market readiness agent returned an unexpected response type.', [
                    'provider' => $provider,
                    'response_class' => $response::class,
                ]);

                return null;
            }

            $assessment = $this->normalizeMarketReadinessPayload($response->toArray());

            if (! $assessment) {
                logger()->warning('Market readiness response failed normalization.', [
                    'provider' => $provider,
                    'model' => $response->meta->model ?? $model,
                ]);

                return null;
            }

            $assessment['generated_by'] = (string) ($response->meta->provider ?? $provider)
                .':'
                .(string) ($response->meta->model ?? $model);

            return $assessment;
        } catch (Throwable $exception) {
            logger()->warning('Market readiness request threw exception.', [
                'provider' => $provider,
                'model' => $model,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $analysis
     * @param  array<string, mixed>|null  $dataFreshness
     * @param  array<string, mixed>|null  $marketReadiness
     * @param  array<string, mixed>|null  $modelAudit
     * @return array{
     *   decision:string,
     *   publishable_classification:string,
     *   confidence:int,
     *   summary:string,
     *   reasons:array<int,string>,
     *   blocked_outputs:array<int,string>,
     *   required_actions:array<int,string>,
     *   generated_by:string
     * }|null
     */
    public function generatePublishingGuardrailAssessment(
        array $payload,
        ?array $analysis,
        ?array $dataFreshness,
        ?array $marketReadiness,
        ?array $modelAudit = null,
        ?string $provider = null,
        ?string $model = null,
    ): ?array {
        if (! config('ai.features.publishing_guardrail_review.enabled', true)) {
            return null;
        }

        $provider ??= (string) config('ai.features.publishing_guardrail_review.provider', 'openai');
        $model ??= (string) config('ai.features.publishing_guardrail_review.model', 'gpt-4o-mini');

        if (! $this->providerIsConfigured($provider)) {
            return null;
        }

        try {
            $response = app(PublishingGuardrailAgent::class)->prompt(
                $this->buildPublishingGuardrailPrompt($payload, $analysis, $dataFreshness, $marketReadiness, $modelAudit),
                provider: $provider,
                model: $model,
            );

            if (! $response instanceof StructuredAgentResponse) {
                logger()->warning('Publishing guardrail agent returned an unexpected response type.', [
                    'provider' => $provider,
                    'response_class' => $response::class,
                ]);

                return null;
            }

            $assessment = $this->normalizePublishingGuardrailPayload($response->toArray());

            if (! $assessment) {
                logger()->warning('Publishing guardrail response failed normalization.', [
                    'provider' => $provider,
                    'model' => $response->meta->model ?? $model,
                ]);

                return null;
            }

            $assessment['generated_by'] = (string) ($response->meta->provider ?? $provider)
                .':'
                .(string) ($response->meta->model ?? $model);

            return $assessment;
        } catch (Throwable $exception) {
            logger()->warning('Publishing guardrail request threw exception.', [
                'provider' => $provider,
                'model' => $model,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{
     *   summary:string,
     *   key_points:array<int,string>,
     *   risk_note:string,
     *   betting_plan:array{bet_pick:string,reasoning:string}|null,
     *   social_caption:string|null,
     *   generated_by:string
     * }|null
     */
    public function generatePredictionNarrative(
        string $prompt,
        ?string $provider = null,
        ?string $model = null,
    ): ?array {
        $provider ??= (string) config('ai.features.sports_prediction_narratives.provider', 'openai');
        $model ??= (string) config('ai.features.sports_prediction_narratives.model', 'gpt-4o-mini');

        if (! $this->providerIsConfigured($provider)) {
            return null;
        }

        try {
            $response = app(SportsPredictionNarrativeAgent::class)->prompt(
                $prompt,
                provider: $provider,
                model: $model,
            );

            if (! $response instanceof StructuredAgentResponse) {
                logger()->warning('Sports AI narrative agent returned an unexpected response type.', [
                    'provider' => $provider,
                    'response_class' => $response::class,
                ]);

                return null;
            }

            $payload = $this->normalizePredictionNarrativePayload($response->toArray());

            if (! $payload) {
                logger()->warning('Sports AI narrative response failed normalization.', [
                    'provider' => $provider,
                    'model' => $response->meta->model ?? $model,
                ]);

                return null;
            }

            $payload['generated_by'] = (string) ($response->meta->provider ?? $provider)
                .':'
                .(string) ($response->meta->model ?? $model);

            return $payload;
        } catch (Throwable $exception) {
            logger()->warning('Sports AI narrative request threw exception.', [
                'provider' => $provider,
                'model' => $model,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{
     *   summary:string,
     *   key_points:array<int,string>,
     *   risk_note:string,
     *   betting_plan:array{bet_pick:string,reasoning:string}|null,
     *   social_caption:string|null,
     *   generated_by:string
     * }|null
     */
    public function generatePlayerPropNarrative(
        string $prompt,
        ?string $provider = null,
        ?string $model = null,
    ): ?array {
        $provider ??= (string) config('ai.features.player_prop_narratives.provider', 'openai');
        $model ??= (string) config('ai.features.player_prop_narratives.model', 'gpt-4o-mini');

        if (! $this->providerIsConfigured($provider)) {
            return null;
        }

        try {
            $response = app(PlayerPropNarrativeAgent::class)->prompt(
                $prompt,
                provider: $provider,
                model: $model,
            );

            if (! $response instanceof StructuredAgentResponse) {
                logger()->warning('Player prop AI narrative agent returned an unexpected response type.', [
                    'provider' => $provider,
                    'response_class' => $response::class,
                ]);

                return null;
            }

            $payload = $this->normalizePredictionNarrativePayload($response->toArray());

            if (! $payload) {
                logger()->warning('Player prop AI narrative response failed normalization.', [
                    'provider' => $provider,
                    'model' => $response->meta->model ?? $model,
                ]);

                return null;
            }

            $payload['generated_by'] = (string) ($response->meta->provider ?? $provider)
                .':'
                .(string) ($response->meta->model ?? $model);

            return $payload;
        } catch (Throwable $exception) {
            logger()->warning('Player prop AI narrative request threw exception.', [
                'provider' => $provider,
                'model' => $model,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $predictions
     * @param  array<int, array<string, mixed>>  $playerProps
     * @param  array<int, string>  $selectedSports
     * @return array{headline:string,intro:string,highlights:array<int,string>}|null
     */
    public function generateDailyDigestSummary(
        array $predictions,
        array $playerProps,
        array $selectedSports = [],
    ): ?array {
        if (! config('ai.features.daily_digest_summary.enabled', false)) {
            return null;
        }

        $provider = (string) config('ai.features.daily_digest_summary.provider', 'openai');
        $model = (string) config('ai.features.daily_digest_summary.model', 'gpt-4o-mini');

        if (! $this->providerIsConfigured($provider)) {
            return null;
        }

        try {
            $response = app(DailyDigestSummaryAgent::class)->prompt(
                $this->buildDailyDigestPrompt($predictions, $playerProps, $selectedSports),
                provider: $provider,
                model: $model,
            );

            if (! $response instanceof StructuredAgentResponse) {
                logger()->warning('Daily digest summary agent returned an unexpected response type.', [
                    'provider' => $provider,
                    'response_class' => $response::class,
                ]);

                return null;
            }

            $payload = $this->normalizeDailyDigestPayload($response->toArray());

            if (! $payload) {
                logger()->warning('Daily digest AI summary response failed normalization.', [
                    'provider' => $provider,
                    'model' => $response->meta->model ?? $model,
                ]);
            }

            return $payload;
        } catch (Throwable $exception) {
            logger()->warning('Daily digest AI summary request threw exception.', [
                'provider' => $provider,
                'model' => $model,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  iterable<ValidationFinding>  $findings
     * @return array{headline:string,intro:string,highlights:array<int,string>,recommended_actions:array<int,string>,latest_data_fresh_at:string,data_schedule_today:array<int,string>,tweak_recommendations:array<int,string>,operational_status:string,trust_score:int,blocked_outputs:array<int,string>,safe_adjustments:array<int,string>,data_quality_notes:array<int,string>,generated_by:string}|null
     */
    public function generateValidationReviewSummary(iterable $findings): ?array
    {
        if (! config('ai.features.validation_review_summary.enabled', false)) {
            return null;
        }

        $provider = (string) config('ai.features.validation_review_summary.provider', 'openai');
        $model = (string) config('ai.features.validation_review_summary.model', 'gpt-4o-mini');

        if (! $this->providerIsConfigured($provider)) {
            return null;
        }

        try {
            $response = app(ValidationReviewSummaryAgent::class)->prompt(
                $this->buildValidationReviewPrompt($findings),
                provider: $provider,
                model: $model,
            );

            if (! $response instanceof StructuredAgentResponse) {
                logger()->warning('Validation review summary agent returned an unexpected response type.', [
                    'provider' => $provider,
                    'response_class' => $response::class,
                ]);

                return null;
            }

            $payload = $this->normalizeValidationReviewPayload($response->toArray());

            if (! $payload) {
                logger()->warning('Validation review AI summary response failed normalization.', [
                    'provider' => $provider,
                    'model' => $response->meta->model ?? $model,
                ]);

                return null;
            }

            $payload['generated_by'] = (string) ($response->meta->provider ?? $provider)
                .':'
                .(string) ($response->meta->model ?? $model);

            return $payload;
        } catch (Throwable $exception) {
            logger()->warning('Validation review AI summary request threw exception.', [
                'provider' => $provider,
                'model' => $model,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function providerIsConfigured(string $provider): bool
    {
        $providerKey = trim((string) config("ai.providers.{$provider}.key", ''));

        if ($providerKey !== '') {
            return true;
        }

        return $provider === 'openai'
            && trim((string) config('services.openai.api_key', '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{
     *   summary:string,
     *   key_points:array<int,string>,
     *   risk_note:string,
     *   betting_plan:array{bet_pick:string,reasoning:string}|null,
     *   social_caption:string|null
     * }|null
     */
    private function normalizePredictionNarrativePayload(array $decoded): ?array
    {
        $summary = trim((string) ($decoded['summary'] ?? ''));
        $riskNote = trim((string) ($decoded['risk_note'] ?? ''));
        $socialCaption = trim((string) ($decoded['social_caption'] ?? ''));
        $keyPoints = $decoded['key_points'] ?? null;

        if ($summary === '' || $riskNote === '' || ! is_array($keyPoints)) {
            return null;
        }

        $normalizedKeyPoints = array_values(array_filter(
            array_map(fn ($point) => trim((string) $point), $keyPoints),
            fn ($point) => $point !== ''
        ));

        if ($normalizedKeyPoints === []) {
            return null;
        }

        $bettingPlan = $decoded['betting_plan'] ?? null;
        $normalizedBettingPlan = null;

        if (is_array($bettingPlan)) {
            $betPick = trim((string) ($bettingPlan['bet_pick'] ?? ''));
            $reasoning = trim((string) ($bettingPlan['reasoning'] ?? ''));

            if ($betPick !== '' && $reasoning !== '') {
                $normalizedBettingPlan = [
                    'bet_pick' => Str::finish($betPick, '.'),
                    'reasoning' => $reasoning,
                ];
            }
        }

        return [
            'summary' => $summary,
            'key_points' => $normalizedKeyPoints,
            'risk_note' => $riskNote,
            'betting_plan' => $normalizedBettingPlan,
            'social_caption' => $socialCaption !== '' ? $socialCaption : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{
     *   recommendation:string,
     *   bet_classification:string,
     *   ai_confidence:int,
     *   analysis_confidence:int,
     *   summary:string,
     *   key_factors:array<int,string>,
     *   risk_flags:array<int,string>,
     *   reason_codes:array<int,string>,
     *   market_notes:array{moneyline:string|null,spread:string|null,total:string|null,props:string|null}
     * }|null
     */
    private function normalizeDailyPredictionAnalysisPayload(array $decoded): ?array
    {
        $recommendation = trim((string) ($decoded['recommendation'] ?? ''));
        $classification = trim((string) ($decoded['bet_classification'] ?? ''));
        $summary = trim((string) ($decoded['summary'] ?? ''));
        $keyFactors = $this->stringList($decoded['key_factors'] ?? []);
        $riskFlags = $this->stringList($decoded['risk_flags'] ?? []);
        $reasonCodes = $this->stringList($decoded['reason_codes'] ?? []);

        if ($recommendation === '' || $classification === '' || $summary === '' || $keyFactors === [] || $reasonCodes === []) {
            return null;
        }

        $marketNotes = is_array($decoded['market_notes'] ?? null) ? $decoded['market_notes'] : [];

        return [
            'recommendation' => Str::of($recommendation)->lower()->replace(' ', '_')->toString(),
            'bet_classification' => Str::of($classification)->lower()->replace(' ', '_')->toString(),
            'ai_confidence' => $this->score($decoded['ai_confidence'] ?? 0),
            'analysis_confidence' => $this->score($decoded['analysis_confidence'] ?? 0),
            'summary' => $summary,
            'key_factors' => array_slice($keyFactors, 0, 8),
            'risk_flags' => array_slice($riskFlags, 0, 8),
            'reason_codes' => array_slice($reasonCodes, 0, 12),
            'market_notes' => [
                'moneyline' => $this->nullableString($marketNotes['moneyline'] ?? null),
                'spread' => $this->nullableString($marketNotes['spread'] ?? null),
                'total' => $this->nullableString($marketNotes['total'] ?? null),
                'props' => $this->nullableString($marketNotes['props'] ?? null),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{freshness_status:string,trust_score:int,latest_data_fresh_at:string|null,summary:string,stale_inputs:array<int,string>,missing_inputs:array<int,string>,blocked_outputs:array<int,string>,recommended_actions:array<int,string>}|null
     */
    private function normalizeDataFreshnessPayload(array $decoded): ?array
    {
        $status = Str::of((string) ($decoded['freshness_status'] ?? 'unknown'))
            ->lower()
            ->replace(' ', '_')
            ->toString();
        $summary = trim((string) ($decoded['summary'] ?? ''));

        if (! in_array($status, ['fresh', 'watch', 'stale', 'blocked', 'unknown'], true)) {
            $status = 'unknown';
        }

        if ($summary === '') {
            return null;
        }

        return [
            'freshness_status' => $status,
            'trust_score' => $this->score($decoded['trust_score'] ?? 0),
            'latest_data_fresh_at' => $this->nullableString($decoded['latest_data_fresh_at'] ?? null),
            'summary' => $summary,
            'stale_inputs' => array_slice($this->stringList($decoded['stale_inputs'] ?? []), 0, 8),
            'missing_inputs' => array_slice($this->stringList($decoded['missing_inputs'] ?? []), 0, 8),
            'blocked_outputs' => array_slice($this->stringList($decoded['blocked_outputs'] ?? []), 0, 8),
            'recommended_actions' => array_slice($this->stringList($decoded['recommended_actions'] ?? []), 0, 8),
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{market_status:string,readiness_score:int,summary:string,available_markets:array<int,string>,missing_markets:array<int,string>,risk_flags:array<int,string>,recommended_actions:array<int,string>,publishable_recommendation:string}|null
     */
    private function normalizeMarketReadinessPayload(array $decoded): ?array
    {
        $status = Str::of((string) ($decoded['market_status'] ?? 'unknown'))
            ->lower()
            ->replace(' ', '_')
            ->toString();
        $publishableRecommendation = Str::of((string) ($decoded['publishable_recommendation'] ?? 'watch'))
            ->lower()
            ->replace(' ', '_')
            ->toString();
        $summary = trim((string) ($decoded['summary'] ?? ''));

        if (! in_array($status, ['ready', 'watch', 'incomplete', 'blocked', 'unknown'], true)) {
            $status = 'unknown';
        }

        if (! in_array($publishableRecommendation, ['official_bet', 'model_lean', 'watchlist', 'pass', 'blocked'], true)) {
            $publishableRecommendation = 'watchlist';
        }

        if ($summary === '') {
            return null;
        }

        return [
            'market_status' => $status,
            'readiness_score' => $this->score($decoded['readiness_score'] ?? 0),
            'summary' => $summary,
            'available_markets' => array_slice($this->stringList($decoded['available_markets'] ?? []), 0, 8),
            'missing_markets' => array_slice($this->stringList($decoded['missing_markets'] ?? []), 0, 8),
            'risk_flags' => array_slice($this->stringList($decoded['risk_flags'] ?? []), 0, 8),
            'recommended_actions' => array_slice($this->stringList($decoded['recommended_actions'] ?? []), 0, 8),
            'publishable_recommendation' => $publishableRecommendation,
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{model_status:string,signal_score:int,confidence_alignment:string,summary:string,supporting_factors:array<int,string>,model_risk_flags:array<int,string>,reason_codes:array<int,string>,recommended_classification:string}|null
     */
    private function normalizeModelAuditPayload(array $decoded): ?array
    {
        $status = Str::of((string) ($decoded['model_status'] ?? 'unknown'))
            ->lower()
            ->replace(' ', '_')
            ->toString();
        $confidenceAlignment = Str::of((string) ($decoded['confidence_alignment'] ?? 'unknown'))
            ->lower()
            ->replace(' ', '_')
            ->toString();
        $classification = Str::of((string) ($decoded['recommended_classification'] ?? 'watch'))
            ->lower()
            ->replace(' ', '_')
            ->toString();
        $summary = trim((string) ($decoded['summary'] ?? ''));

        if (! in_array($status, ['strong', 'usable', 'thin', 'contradictory', 'blocked', 'unknown'], true)) {
            $status = 'unknown';
        }

        if (! in_array($confidenceAlignment, ['aligned', 'overstated', 'understated', 'unclear'], true)) {
            $confidenceAlignment = 'unclear';
        }

        if (! in_array($classification, ['bet', 'lean', 'watch', 'pass', 'blocked'], true)) {
            $classification = 'watch';
        }

        if ($summary === '') {
            return null;
        }

        return [
            'model_status' => $status,
            'signal_score' => $this->score($decoded['signal_score'] ?? 0),
            'confidence_alignment' => $confidenceAlignment,
            'summary' => $summary,
            'supporting_factors' => array_slice($this->stringList($decoded['supporting_factors'] ?? []), 0, 8),
            'model_risk_flags' => array_slice($this->stringList($decoded['model_risk_flags'] ?? []), 0, 8),
            'reason_codes' => array_slice($this->stringList($decoded['reason_codes'] ?? []), 0, 12),
            'recommended_classification' => $classification,
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{decision:string,publishable_classification:string,confidence:int,summary:string,reasons:array<int,string>,blocked_outputs:array<int,string>,required_actions:array<int,string>}|null
     */
    private function normalizePublishingGuardrailPayload(array $decoded): ?array
    {
        $decision = Str::of((string) ($decoded['decision'] ?? 'hold'))
            ->lower()
            ->replace(' ', '_')
            ->toString();
        $classification = Str::of((string) ($decoded['publishable_classification'] ?? 'watch'))
            ->lower()
            ->replace(' ', '_')
            ->toString();
        $summary = trim((string) ($decoded['summary'] ?? ''));
        $reasons = array_slice($this->stringList($decoded['reasons'] ?? []), 0, 8);

        if (! in_array($decision, ['keep', 'downgrade', 'hold', 'block'], true)) {
            $decision = 'hold';
        }

        if (! in_array($classification, ['bet', 'lean', 'watch', 'pass', 'blocked'], true)) {
            $classification = 'watch';
        }

        if ($summary === '' || $reasons === []) {
            return null;
        }

        return [
            'decision' => $decision,
            'publishable_classification' => $classification,
            'confidence' => $this->score($decoded['confidence'] ?? 0),
            'summary' => $summary,
            'reasons' => $reasons,
            'blocked_outputs' => array_slice($this->stringList($decoded['blocked_outputs'] ?? []), 0, 8),
            'required_actions' => array_slice($this->stringList($decoded['required_actions'] ?? []), 0, 8),
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{headline:string,intro:string,highlights:array<int,string>}|null
     */
    private function normalizeDailyDigestPayload(array $decoded): ?array
    {
        $headline = trim((string) ($decoded['headline'] ?? ''));
        $intro = trim((string) ($decoded['intro'] ?? ''));
        $highlights = $decoded['highlights'] ?? [];

        if ($headline === '' || $intro === '' || ! is_array($highlights)) {
            return null;
        }

        return [
            'headline' => $headline,
            'intro' => $intro,
            'highlights' => array_values(array_slice(array_filter(
                array_map(fn ($highlight) => trim((string) $highlight), $highlights),
                fn ($highlight) => $highlight !== ''
            ), 0, 3)),
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{headline:string,intro:string,highlights:array<int,string>,recommended_actions:array<int,string>,latest_data_fresh_at:string,data_schedule_today:array<int,string>,tweak_recommendations:array<int,string>,operational_status:string,trust_score:int,blocked_outputs:array<int,string>,safe_adjustments:array<int,string>,data_quality_notes:array<int,string>}|null
     */
    private function normalizeValidationReviewPayload(array $decoded): ?array
    {
        $headline = trim((string) ($decoded['headline'] ?? ''));
        $intro = trim((string) ($decoded['intro'] ?? ''));
        $highlights = $decoded['highlights'] ?? [];
        $recommendedActions = $decoded['recommended_actions'] ?? [];
        $latestDataFreshAt = trim((string) ($decoded['latest_data_fresh_at'] ?? ''));
        $dataScheduleToday = $decoded['data_schedule_today'] ?? [];
        $tweakRecommendations = $decoded['tweak_recommendations'] ?? [];
        $operationalStatus = Str::of((string) ($decoded['operational_status'] ?? 'unknown'))
            ->lower()
            ->replace(' ', '_')
            ->toString();
        $blockedOutputs = $decoded['blocked_outputs'] ?? [];
        $safeAdjustments = $decoded['safe_adjustments'] ?? [];
        $dataQualityNotes = $decoded['data_quality_notes'] ?? [];

        if (
            $headline === ''
            || $intro === ''
            || $latestDataFreshAt === ''
            || ! is_array($highlights)
            || ! is_array($recommendedActions)
            || ! is_array($dataScheduleToday)
            || ! is_array($tweakRecommendations)
            || ! is_array($blockedOutputs)
            || ! is_array($safeAdjustments)
            || ! is_array($dataQualityNotes)
        ) {
            return null;
        }

        if (! in_array($operationalStatus, ['healthy', 'watch', 'degraded', 'critical'], true)) {
            $operationalStatus = 'degraded';
        }

        return [
            'headline' => $headline,
            'intro' => $intro,
            'highlights' => array_values(array_slice(array_filter(
                array_map(fn ($highlight) => trim((string) $highlight), $highlights),
                fn ($highlight) => $highlight !== ''
            ), 0, 4)),
            'recommended_actions' => array_values(array_slice(array_filter(
                array_map(fn ($action) => trim((string) $action), $recommendedActions),
                fn ($action) => $action !== ''
            ), 0, 4)),
            'latest_data_fresh_at' => $latestDataFreshAt,
            'data_schedule_today' => array_values(array_slice(array_filter(
                array_map(fn ($item) => trim((string) $item), $dataScheduleToday),
                fn ($item) => $item !== ''
            ), 0, 6)),
            'tweak_recommendations' => array_values(array_slice(array_filter(
                array_map(fn ($recommendation) => trim((string) $recommendation), $tweakRecommendations),
                fn ($recommendation) => $recommendation !== ''
            ), 0, 4)),
            'operational_status' => $operationalStatus,
            'trust_score' => $this->score($decoded['trust_score'] ?? 0),
            'blocked_outputs' => array_values(array_slice(array_filter(
                array_map(fn ($output) => trim((string) $output), $blockedOutputs),
                fn ($output) => $output !== ''
            ), 0, 6)),
            'safe_adjustments' => array_values(array_slice(array_filter(
                array_map(fn ($adjustment) => trim((string) $adjustment), $safeAdjustments),
                fn ($adjustment) => $adjustment !== ''
            ), 0, 6)),
            'data_quality_notes' => array_values(array_slice(array_filter(
                array_map(fn ($note) => trim((string) $note), $dataQualityNotes),
                fn ($note) => $note !== ''
            ), 0, 6)),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $predictions
     * @param  array<int, array<string, mixed>>  $playerProps
     * @param  array<int, string>  $selectedSports
     */
    private function buildDailyDigestPrompt(array $predictions, array $playerProps, array $selectedSports): string
    {
        $predictionLines = Collection::make($predictions)
            ->take(3)
            ->map(function (array $prediction): string {
                $bits = [
                    (string) ($prediction['sport'] ?? 'SPORT'),
                    (string) ($prediction['matchup'] ?? 'Matchup'),
                    'pick '.(string) ($prediction['pick'] ?? 'lean'),
                ];

                if (($prediction['confidence'] ?? null) !== null) {
                    $bits[] = 'confidence '.number_format((float) $prediction['confidence'], 1).'%';
                }

                if (($prediction['predicted_spread'] ?? null) !== null) {
                    $bits[] = 'spread '.number_format((float) $prediction['predicted_spread'], 1);
                }

                if (($prediction['predicted_total'] ?? null) !== null) {
                    $bits[] = 'total '.number_format((float) $prediction['predicted_total'], 1);
                }

                return '- '.implode(', ', $bits);
            })
            ->implode("\n");

        $propLines = Collection::make($playerProps)
            ->take(3)
            ->map(function (array $prop): string {
                $bits = [
                    (string) ($prop['sport'] ?? 'SPORT'),
                    (string) ($prop['player_name'] ?? 'Player'),
                    (string) ($prop['market'] ?? 'Market'),
                    (string) ($prop['recommendation'] ?? 'Recommendation'),
                ];

                if (($prop['confidence'] ?? null) !== null) {
                    $bits[] = 'confidence '.number_format((float) $prop['confidence'], 0).'%';
                }

                if (($prop['edge'] ?? null) !== null) {
                    $bits[] = 'edge '.number_format((float) $prop['edge'], 1).'%';
                }

                return '- '.implode(', ', $bits);
            })
            ->implode("\n");

        return implode("\n", array_filter([
            'Write a short daily digest summary for sportsbook picks.',
            'Return a factual headline, a 1-2 sentence intro, and up to 3 short highlight bullets.',
            'Do not promise outcomes, do not mention AI, and do not invent games or props.',
            'Keep the tone helpful, data-driven, and concise.',
            $selectedSports !== [] ? 'Selected sports: '.implode(', ', array_map('strtoupper', $selectedSports)) : null,
            $predictionLines !== '' ? "Predictions:\n{$predictionLines}" : null,
            $propLines !== '' ? "Player props:\n{$propLines}" : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildDailyPredictionAnalysisPrompt(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Analyze this daily sports prediction packet.

Rules:
- Use only the supplied JSON.
- Treat calculated_model as the deterministic model output.
- Your job is to audit, explain, and classify the bet quality, not to invent a new projection.
- If price/odds are missing, classify conservatively.
- Separate calculated edge from analysis confidence.
- Recommendation must be one of: moneyline, spread, total, prop, parlay_piece, pass.
- Bet classification must be one of: bet, lean, watch, pass.
- Include risk flags whenever data is stale, missing, contradictory, or fragile.
- Keep the summary concise and actionable for a daily betting workflow.

Prediction packet:
{$json}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildDataFreshnessPrompt(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Audit this sports prediction packet for data freshness only.

Rules:
- Use only the supplied JSON.
- Treat operational_context as the source of truth.
- freshness_status must be one of: fresh, watch, stale, blocked, unknown.
- trust_score is 0-100 based on whether the game, odds, injury, prop, futures, weather, and pipeline data are current enough to trust.
- blocked_outputs should name outputs that should be held, caveated, or regenerated.
- recommended_actions must come from operational_context.required_actions or validation findings.
- Do not classify the bet itself.

Prediction packet:
{$json}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildMarketReadinessPrompt(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Audit this sports prediction packet for market readiness only.

Rules:
- Use only the supplied JSON.
- Treat market_context and operational_context as authoritative.
- market_status must be one of: ready, watch, incomplete, blocked, unknown.
- publishable_recommendation must be one of: official_bet, model_lean, watchlist, pass, blocked.
- available_markets and missing_markets should describe what betting markets are usable or unavailable.
- risk_flags should include stale odds, missing props, missing futures, missing weather, stale injuries, or pipeline order issues when present.
- recommended_actions must come from operational_context.required_actions or validation findings.
- Do not invent prices, books, players, or lines.

Prediction packet:
{$json}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $analysis
     */
    private function buildModelAuditPrompt(array $payload, ?array $analysis): string
    {
        $json = json_encode([
            'prediction_packet' => $payload,
            'primary_analysis' => $analysis,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Audit this sports prediction model signal.

Rules:
- Use only the supplied JSON.
- Treat calculated_model as deterministic model output.
- Treat primary_analysis as the current recommendation, not as proof that the model is strong.
- model_status must be one of: strong, usable, thin, contradictory, blocked, unknown.
- confidence_alignment must be one of: aligned, overstated, understated, unclear.
- recommended_classification must be one of: bet, lean, watch, pass, blocked.
- signal_score is 0-100 based only on model strength, confidence, edge, reason codes, and consistency.
- Do not use stale/missing market data as the main reason unless it directly affects whether the model signal can be interpreted.
- Do not invent stats, trends, injuries, or odds.

Model package:
{$json}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $analysis
     * @param  array<string, mixed>|null  $dataFreshness
     * @param  array<string, mixed>|null  $marketReadiness
     * @param  array<string, mixed>|null  $modelAudit
     */
    private function buildPublishingGuardrailPrompt(
        array $payload,
        ?array $analysis,
        ?array $dataFreshness,
        ?array $marketReadiness,
        ?array $modelAudit,
    ): string {
        $json = json_encode([
            'prediction_packet' => $payload,
            'primary_analysis' => $analysis,
            'shadow_reviews' => [
                'data_freshness' => $dataFreshness,
                'market_readiness' => $marketReadiness,
                'model_audit' => $modelAudit,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Review this sports prediction package for publishing safety.

Rules:
- Use only the supplied JSON.
- The primary_analysis is the current live recommendation, but it is not automatically safe.
- Treat operational_context and shadow_reviews as authoritative for freshness, market readiness, model signal, and blocked outputs.
- decision must be one of: keep, downgrade, hold, block.
- publishable_classification must be one of: bet, lean, watch, pass, blocked.
- If operational_context.publication_guardrails.status is blocked, decision must be block or hold.
- If odds, injuries, weather, player props, futures, or pipeline order are stale or missing, downgrade or hold unless the issue is irrelevant to the recommendation.
- required_actions must come from operational_context.required_actions or shadow review recommended_actions.
- Do not rewrite the recommendation text.

Publishing package:
{$json}
PROMPT;
    }

    /**
     * @param  iterable<ValidationFinding>  $findings
     */
    private function buildValidationReviewPrompt(iterable $findings): string
    {
        $findingsCollection = Collection::make($findings)
            ->sortByDesc(fn (ValidationFinding $finding): int => match ($finding->status) {
                'failing' => 3,
                'warning' => 2,
                default => 1,
            })
            ->values();

        $structuredFindings = $findingsCollection
            ->take(30)
            ->map(fn (ValidationFinding $finding): array => [
                'sport' => $finding->sport,
                'status' => $finding->status,
                'severity' => $finding->severity,
                'check_type' => $finding->check_type,
                'message' => $finding->message,
                'recommended_action' => $finding->recommended_action,
                'facts' => is_array($finding->facts) ? $finding->facts : [],
                'detected_at' => $finding->detected_at?->toDateTimeString(),
            ])
            ->values()
            ->all();

        $allowedActions = $findingsCollection
            ->pluck('recommended_action')
            ->filter(fn ($action) => is_string($action) && trim($action) !== '')
            ->unique()
            ->values()
            ->all();

        $json = json_encode([
            'generated_at' => now()->toDateTimeString(),
            'allowed_actions' => $allowedActions,
            'findings' => $structuredFindings,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $lines = $findingsCollection
            ->take(12)
            ->map(function (ValidationFinding $finding): string {
                $facts = is_array($finding->facts) ? $finding->facts : [];
                $sampleGameIds = $facts['sample_game_ids'] ?? null;
                $sampleSuffix = '';

                if (is_array($sampleGameIds) && $sampleGameIds !== []) {
                    $sampleSuffix = ' Sample game IDs: '.implode(', ', array_map('strval', array_slice($sampleGameIds, 0, 5))).'.';
                }

                return sprintf(
                    '- [%s] %s / %s: %s Recommended action: %s.%s',
                    strtoupper($finding->sport),
                    $finding->status,
                    $finding->check_type,
                    $finding->message,
                    $finding->recommended_action ?: 'review manually',
                    $sampleSuffix
                );
            })
            ->implode("\n");

        return <<<PROMPT
Summarize this sports data validation run for an internal admin dashboard.

Focus on:
- what data is missing or incomplete
- which issues are the highest priority
- which commands should be rerun first
- when the latest data looked fresh or when freshness was last checked
- today's data schedule in plain English
- small operational tweaks that would make the pipeline more reliable

Use only the supplied findings. Do not invent new incidents.
Never recommend a command unless it appears in allowed_actions.
For safe_adjustments, include only non-destructive reruns from allowed_actions.
For blocked_outputs, name user-facing outputs that should be held, caveated, or regenerated because of the findings.
For operational_status, use one of: healthy, watch, degraded, critical.
For trust_score, return 0-100 based only on validation state and data freshness.
For latest_data_fresh_at, answer with the clearest timestamp or state that the latest run did not prove full freshness.
For data_schedule_today, include scoreboard, game details, odds/player props, validation, and admin report timing when supported by the findings.
For tweak_recommendations, recommend only practical scheduling, validation, or sync changes grounded in the supplied findings.

Finding summary:
{$lines}

Structured operational context:
{$json}
PROMPT;
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($item) => trim((string) $item), $value),
            fn (string $item): bool => $item !== ''
        ));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function score(mixed $value): int
    {
        return max(0, min(100, (int) round((float) $value)));
    }
}
