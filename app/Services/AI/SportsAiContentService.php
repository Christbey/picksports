<?php

namespace App\Services\AI;

use App\AI\Agents\DailyDigestSummaryAgent;
use App\AI\Agents\PlayerPropNarrativeAgent;
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
     * @return array{headline:string,intro:string,highlights:array<int,string>,recommended_actions:array<int,string>,generated_by:string}|null
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
     * @return array{headline:string,intro:string,highlights:array<int,string>,recommended_actions:array<int,string>}|null
     */
    private function normalizeValidationReviewPayload(array $decoded): ?array
    {
        $headline = trim((string) ($decoded['headline'] ?? ''));
        $intro = trim((string) ($decoded['intro'] ?? ''));
        $highlights = $decoded['highlights'] ?? [];
        $recommendedActions = $decoded['recommended_actions'] ?? [];

        if ($headline === '' || $intro === '' || ! is_array($highlights) || ! is_array($recommendedActions)) {
            return null;
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
     * @param  iterable<ValidationFinding>  $findings
     */
    private function buildValidationReviewPrompt(iterable $findings): string
    {
        $lines = Collection::make($findings)
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

Use only the supplied findings. Do not invent new incidents.

Findings:
{$lines}
PROMPT;
    }
}
