<?php

namespace App\Console\Commands\Sports;

use App\Models\NBA\Prediction;
use App\Models\SportsAiPredictionAnalysis;
use App\Services\AI\SportsAiContentService;
use App\Services\Predictions\SportsAiPredictionPayloadBuilder;
use App\Services\Sports\SportsDateWindowService;
use App\Services\Sports\SportsPipelineRegistry;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AnalyzeDailyPredictionsWithAiCommand extends Command
{
    protected $signature = 'sports:ai-daily-predictions
        {--sport=* : Sport(s) to analyze; supports nba, nfl, mlb, cbb, wcbb, wnba, cfb}
        {--all : Analyze every supported sport}
        {--date= : Slate date, defaults to today}
        {--days-forward=0 : Include games through N days after the slate date}
        {--season= : Optional season filter}
        {--limit=25 : Max predictions per sport}
        {--force : Regenerate even when the input hash has not changed}
        {--dry-run : Show which predictions would be analyzed without calling AI}
        {--provider= : Override AI provider}
        {--model= : Override AI model}';

    protected $description = 'Send daily prediction payloads through the structured AI analysis layer for one or more sports';

    /**
     * @var array<string, class-string<Model>>
     */
    private array $predictionModels = [
        'nba' => Prediction::class,
        'nfl' => \App\Models\NFL\Prediction::class,
        'mlb' => \App\Models\MLB\Prediction::class,
        'cbb' => \App\Models\CBB\Prediction::class,
        'wcbb' => \App\Models\WCBB\Prediction::class,
        'wnba' => \App\Models\WNBA\Prediction::class,
        'cfb' => \App\Models\CFB\Prediction::class,
    ];

    public function handle(
        SportsPipelineRegistry $registry,
        SportsAiPredictionPayloadBuilder $payloadBuilder,
        SportsAiContentService $aiContentService
    ): int {
        $sports = $this->sportsToAnalyze($registry);
        if ($sports === []) {
            $this->error('No supported sports selected.');

            return self::FAILURE;
        }

        $dateWindowService = app(SportsDateWindowService::class);
        $date = $this->option('date') ? $dateWindowService->parseLocalDate((string) $this->option('date')) : $dateWindowService->parseLocalDate();
        $endDate = $date->copy()->addDays(max(0, (int) $this->option('days-forward')));
        $limit = max(1, (int) $this->option('limit'));
        $asOfDate = now()->toDateString();
        $requestedProvider = $this->option('provider') ? (string) $this->option('provider') : (string) config('ai.features.daily_prediction_analysis.provider', 'openai');
        $requestedModel = $this->option('model') ? (string) $this->option('model') : (string) config('ai.features.daily_prediction_analysis.model', 'gpt-4o-mini');
        $processed = 0;
        $skipped = 0;
        $rateLimited = false;

        if (! $this->option('dry-run') && ! Schema::hasTable('sports_ai_prediction_analyses')) {
            $this->error('Missing sports_ai_prediction_analyses table. Run php artisan migrate before saving AI analyses.');

            return self::FAILURE;
        }

        $providerUnavailable = ! $this->option('dry-run')
            ? $aiContentService->providerAvailabilityMessage($requestedProvider)
            : null;

        if ($providerUnavailable !== null) {
            $this->warn($providerUnavailable);
        }

        foreach ($sports as $sport) {
            $predictions = $this->predictionsForSport($sport, $date, $endDate, $limit);

            $this->line(strtoupper($sport).': '.$predictions->count().' prediction(s) for '.$date->toDateString().($endDate->isSameDay($date) ? '' : ' through '.$endDate->toDateString()));

            foreach ($predictions as $prediction) {
                if ($rateLimited) {
                    break;
                }

                $payload = $payloadBuilder->build($sport, $prediction);
                $inputHash = $payloadBuilder->hash($payload);
                $gameId = (int) ($prediction->game?->id ?? $prediction->game_id ?? 0);
                $gameDate = $prediction->game?->game_date?->toDateString();
                $operationalContext = $payload['operational_context'] ?? [];

                if ($this->option('dry-run')) {
                    $this->line('  - '.$this->matchup($prediction).' ['.$inputHash.']');
                    $processed++;

                    continue;
                }

                $existing = SportsAiPredictionAnalysis::query()
                    ->where('sport', $sport)
                    ->where('prediction_id', (int) $prediction->id)
                    ->where('market', 'game')
                    ->whereDate('as_of_date', $asOfDate)
                    ->first();

                if (! $this->option('force') && $existing && $existing->input_hash === $inputHash) {
                    $skipped++;

                    continue;
                }

                $startedAt = microtime(true);
                $analysis = $aiContentService->generateDailyPredictionAnalysis(
                    $payload,
                    provider: $requestedProvider,
                    model: $requestedModel,
                );
                $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

                if (! $analysis) {
                    $failureReason = $aiContentService->lastDailyPredictionAnalysisFailure();
                    $this->warn('  - skipped '.$this->matchup($prediction).' (AI analysis unavailable'.($failureReason ? ': '.$failureReason : '').')');
                    $skipped++;

                    if ($failureReason && str_contains(strtolower($failureReason), 'rate limited')) {
                        $rateLimited = true;
                        $this->warn('  - stopping remaining AI daily prediction analysis for this run because the provider is rate limited.');
                    }

                    continue;
                }

                $dataFreshness = $aiContentService->generateDataFreshnessAssessment(
                    $payload,
                    provider: $requestedProvider,
                    model: $requestedModel,
                );
                $marketReadiness = $aiContentService->generateMarketReadinessAssessment(
                    $payload,
                    provider: $requestedProvider,
                    model: $requestedModel,
                );
                $modelAudit = $aiContentService->generateModelAuditAssessment(
                    $payload,
                    $analysis,
                    provider: $requestedProvider,
                    model: $requestedModel,
                );
                $publishingGuardrail = $aiContentService->generatePublishingGuardrailAssessment(
                    $payload,
                    $analysis,
                    $dataFreshness,
                    $marketReadiness,
                    $modelAudit,
                    provider: $requestedProvider,
                    model: $requestedModel,
                );

                $shadowAgents = [
                    'data_freshness' => $dataFreshness,
                    'market_readiness' => $marketReadiness,
                    'model_audit' => $modelAudit,
                    'publishing_guardrail' => $publishingGuardrail,
                ];
                $publishingDecision = $this->effectivePublishingDecision($analysis, $publishingGuardrail);

                [$generatedProvider, $generatedModel] = $this->providerModel((string) ($analysis['generated_by'] ?? ''));

                SportsAiPredictionAnalysis::query()->updateOrCreate(
                    [
                        'sport' => $sport,
                        'prediction_id' => (int) $prediction->id,
                        'market' => 'game',
                        'as_of_date' => $asOfDate,
                    ],
                    [
                        'game_id' => $gameId,
                        'game_date' => $gameDate,
                        'provider' => $generatedProvider,
                        'model' => $generatedModel,
                        'input_hash' => $inputHash,
                        'raw_payload' => $payload,
                        'recommendation' => $publishingDecision['recommendation'],
                        'ai_confidence' => (int) $analysis['ai_confidence'],
                        'analysis_confidence' => (int) $analysis['analysis_confidence'],
                        'bet_classification' => $publishingDecision['bet_classification'],
                        'summary' => (string) $analysis['summary'],
                        'key_factors' => $analysis['key_factors'],
                        'risk_flags' => $analysis['risk_flags'],
                        'reason_codes' => $analysis['reason_codes'],
                        'market_notes' => $analysis['market_notes'],
                        'calculated_edge' => $payloadBuilder->calculatedEdge($prediction),
                        'metadata' => [
                            'command' => 'sports:ai-daily-predictions',
                            'schema_version' => $payload['schema_version'] ?? null,
                            'operational_context_schema_version' => $operationalContext['schema_version'] ?? null,
                            'publication_guardrail_status' => data_get($operationalContext, 'publication_guardrails.status'),
                            'required_actions' => $operationalContext['required_actions'] ?? [],
                            'shadow_agents' => $shadowAgents,
                            'publishing_enforcement' => $publishingDecision['enforcement'],
                        ],
                        'latency_ms' => $latencyMs,
                    ]
                );

                $processed++;
                $this->line('  - saved '.$this->matchup($prediction).' -> '.$publishingDecision['bet_classification'].' / '.$publishingDecision['recommendation']);
            }
        }

        $this->info("AI daily prediction analysis complete. Processed {$processed}; skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function sportsToAnalyze(SportsPipelineRegistry $registry): array
    {
        if ($this->option('all')) {
            return $registry->supportedSports();
        }

        $sports = array_map('strtolower', (array) $this->option('sport'));

        return array_values(array_filter(
            array_unique($sports),
            fn (string $sport): bool => $registry->supportsSport($sport) && isset($this->predictionModels[$sport])
        ));
    }

    private function predictionsForSport(string $sport, CarbonInterface $date, CarbonInterface $endDate, int $limit)
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $this->predictionModels[$sport];
        $season = $this->option('season') ?: config("{$sport}.season.default");
        $scheduledStatus = (string) config("{$sport}.statuses.scheduled", 'STATUS_SCHEDULED');
        $dateWindowService = app(SportsDateWindowService::class);
        $window = $dateWindowService->forRange($date, $endDate);

        return $modelClass::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->when($season, fn ($query) => $query->whereHas('game', fn ($gameQuery) => $gameQuery->where('season', (int) $season)))
            ->whereHas('game', function ($query) use ($dateWindowService, $window, $scheduledStatus): void {
                $dateWindowService->applyGameDateWindow($query, $window)
                    ->where('status', $scheduledStatus);
            })
            ->limit($limit)
            ->get();
    }

    private function matchup(Model $prediction): string
    {
        $game = $prediction->game;

        return (string) ($game?->short_name ?: $game?->name ?: 'prediction '.$prediction->id);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>|null  $publishingGuardrail
     * @return array{recommendation:string,bet_classification:string,enforcement:array<string, mixed>}
     */
    private function effectivePublishingDecision(array $analysis, ?array $publishingGuardrail): array
    {
        $originalRecommendation = (string) $analysis['recommendation'];
        $originalClassification = (string) $analysis['bet_classification'];
        $enforced = (bool) config('ai.features.publishing_guardrail_review.enforced', false);
        $decision = (string) data_get($publishingGuardrail, 'decision', 'shadow');
        $guardrailClassification = (string) data_get($publishingGuardrail, 'publishable_classification', '');

        $recommendation = $originalRecommendation;
        $classification = $originalClassification;

        if ($enforced && $publishingGuardrail) {
            if (in_array($decision, ['downgrade', 'hold', 'block'], true) && $guardrailClassification !== '') {
                $classification = $guardrailClassification;
            }

            if (in_array($decision, ['hold', 'block'], true)) {
                $recommendation = 'pass';
            }
        }

        return [
            'recommendation' => $recommendation,
            'bet_classification' => $classification,
            'enforcement' => [
                'enabled' => $enforced,
                'applied' => $enforced && $publishingGuardrail !== null && (
                    $recommendation !== $originalRecommendation || $classification !== $originalClassification
                ),
                'decision' => $decision,
                'original_recommendation' => $originalRecommendation,
                'original_bet_classification' => $originalClassification,
                'effective_recommendation' => $recommendation,
                'effective_bet_classification' => $classification,
            ],
        ];
    }

    /**
     * @return array{0:string|null,1:string|null}
     */
    private function providerModel(string $generatedBy): array
    {
        if ($generatedBy === '') {
            return [null, null];
        }

        $parts = explode(':', $generatedBy, 2);

        return [$parts[0] ?? null, $parts[1] ?? null];
    }
}
