<?php

namespace App\Services\NFL;

use App\AI\Agents\NflGameContextResearchAgent;
use App\Models\NFL\Game;
use App\Models\SportsGameContextReport;
use App\Services\AI\AiGenerationRecorder;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

class NflWebContextResearchService
{
    public function __construct(
        private readonly OpenAiNflGameContextResearchClient $openAiClient,
        private readonly AiGenerationRecorder $generationRecorder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function research(Game $game, ?string $provider = null, ?string $model = null): array
    {
        $game->loadMissing(['homeTeam', 'awayTeam']);
        $input = $this->input($game);
        $prompt = $this->prompt($input);
        $provider ??= (string) config('ai.features.nfl_game_context_research.provider', 'openai');
        $model ??= (string) config('ai.features.nfl_game_context_research.model', 'gpt-5.6-luna');
        $promptVersion = (string) config('ai.features.nfl_game_context_research.prompt_version', 'nfl-game-context-research-v1');
        $generation = Schema::hasTable('ai_generations')
            ? $this->generationRecorder->start(
                purpose: 'nfl_game_context_research',
                promptVersion: $promptVersion,
                provider: $provider,
                model: $model,
                input: $input,
                contextType: 'nfl_game',
                contextId: (string) $game->getKey(),
                metadata: [
                    'search_cap' => max(1, (int) config('ai.features.nfl_game_context_research.max_searches', 5)),
                ],
            )
            : null;
        $startedAt = microtime(true);

        try {
            if ($provider === 'openai' && ! NflGameContextResearchAgent::isFaked()) {
                $result = $this->openAiClient->research($prompt, $model);
                $decoded = $result['structured'];
                $providerCitationUrls = $result['citation_urls'];
                $generatedProvider = $result['provider'];
                $generatedModel = $result['model'];
                $responseId = $result['response_id'];
                $usage = $result['usage'];
                $webSearchCalls = $result['web_search_calls'];
            } else {
                $response = app(NflGameContextResearchAgent::class)->prompt(
                    $prompt,
                    provider: $provider,
                    model: $model,
                );

                if (! $response instanceof StructuredAgentResponse) {
                    throw new RuntimeException('NFL game-context research returned an unexpected response type.');
                }

                $decoded = $response->toArray();
                $providerCitationUrls = $response->meta->citations
                    ->map(fn ($citation): ?string => isset($citation->url) ? (string) $citation->url : null)
                    ->filter(fn (?string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== false)
                    ->unique()
                    ->values()
                    ->all();
                $generatedProvider = (string) ($response->meta->provider ?? $provider);
                $generatedModel = (string) ($response->meta->model ?? $model);
                $responseId = $response->invocationId;
                $usage = [
                    'input' => $response->usage->promptTokens,
                    'output' => $response->usage->completionTokens,
                    'cached_input' => $response->usage->cacheReadInputTokens,
                    'reasoning' => $response->usage->reasoningTokens,
                ];
                $webSearchCalls = null;
            }

            $payload = $this->normalize($decoded, $providerCitationUrls);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $researchedAt = now();

            $report = SportsGameContextReport::query()->create([
                'sport' => 'nfl',
                'game_id' => (int) $game->getKey(),
                'game_date' => app(SportsDateWindowService::class)->gameDateForDisplay($game->game_date, $game->game_time),
                'status' => $payload['status'],
                'provider' => $generatedProvider,
                'model' => $generatedModel,
                'prompt_version' => $promptVersion,
                'input_hash' => hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES)),
                'confidence' => $payload['confidence'],
                'summary' => $payload['summary'],
                'team_context' => $payload['team_context'],
                'situational_context' => $payload['situational_context'],
                'market_snapshot' => $payload['market_snapshot'],
                'facts' => $payload['facts'],
                'sources' => $payload['sources'],
                'risk_flags' => $payload['risk_flags'],
                'raw_payload' => $payload,
                'researched_at' => $researchedAt,
                'expires_at' => $researchedAt->copy()->addMinutes((int) config('ai.features.nfl_game_context_research.freshness_minutes', 360)),
                'latency_ms' => $latencyMs,
            ]);

            if ($generation !== null) {
                $generation = $this->generationRecorder->complete(
                    generation: $generation,
                    output: $payload,
                    latencyMs: $latencyMs,
                    tokens: $usage,
                    costUsd: $this->estimatedOpenAiCost($generatedProvider, $generatedModel, $usage, $webSearchCalls),
                    metadata: [
                        'provider_response_id' => $responseId,
                        'report_id' => (int) $report->getKey(),
                        'web_search_calls' => $webSearchCalls,
                        'reasoning_tokens' => $usage['reasoning'],
                        'cost_basis' => $webSearchCalls === null ? null : 'configured_pricing_estimate',
                    ],
                );
            }

            return ['report' => $report, 'payload' => $payload, 'generation' => $generation];
        } catch (Throwable $exception) {
            if ($generation !== null && $generation->fresh()?->status === 'running') {
                $this->generationRecorder->fail(
                    $generation,
                    'provider_exception',
                    (int) round((microtime(true) - $startedAt) * 1000),
                    ['exception_class' => $exception::class],
                );
            }

            throw $exception;
        }
    }

    /**
     * @param  array{input:int,output:int,cached_input:int,reasoning:int}  $usage
     */
    private function estimatedOpenAiCost(
        string $provider,
        string $model,
        array $usage,
        ?int $webSearchCalls,
    ): ?string {
        $pricing = (array) config('ai.features.nfl_game_context_research.pricing', []);
        if ($provider !== 'openai' || $model !== (string) ($pricing['model'] ?? '') || $webSearchCalls === null) {
            return null;
        }

        $cachedInput = min(max(0, $usage['cached_input']), max(0, $usage['input']));
        $uncachedInput = max(0, $usage['input'] - $cachedInput);
        $cost = ($uncachedInput / 1_000_000) * (float) ($pricing['input_per_million'] ?? 0)
            + ($cachedInput / 1_000_000) * (float) ($pricing['cached_input_per_million'] ?? 0)
            + (max(0, $usage['output']) / 1_000_000) * (float) ($pricing['output_per_million'] ?? 0)
            + max(0, $webSearchCalls) * (float) ($pricing['web_search_per_call'] ?? 0);

        return number_format($cost, 6, '.', '');
    }

    /** @return array<string, mixed> */
    private function input(Game $game): array
    {
        return [
            'as_of' => now()->toIso8601String(),
            'game_id' => (int) $game->getKey(),
            'season' => is_numeric($game->season) ? (int) $game->season : $game->season,
            'season_type' => $game->season_type,
            'week' => $game->week,
            'game_date' => app(SportsDateWindowService::class)->gameDateForDisplay($game->game_date, $game->game_time),
            'game_time' => $game->game_time,
            'venue' => $game->venue_name,
            'home_team' => [
                'name' => $this->teamName($game->homeTeam),
                'abbreviation' => $game->homeTeam?->abbreviation,
                'listed_qb' => $game->home_qb_name,
                'coach' => $game->home_coach,
            ],
            'away_team' => [
                'name' => $this->teamName($game->awayTeam),
                'abbreviation' => $game->awayTeam?->abbreviation,
                'listed_qb' => $game->away_qb_name,
                'coach' => $game->away_coach,
            ],
        ];
    }

    /** @param array<string, mixed> $input */
    private function prompt(array $input): string
    {
        $json = json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Research the current web context for this NFL game as of the supplied timestamp.

For preseason games, participation plans and quarterback rotations matter more than regular-season depth-chart labels. Search specifically for each head coach's latest participation announcement and credible same-day reporting. A team's regular-season quality is not evidence that its starters will play.

Allowed normalized values:
- starter_participation: full, extended, limited, none, unknown
- qb_rotation_quality: strong, average, weak, unknown
- coaching_intent: aggressive, balanced, conservative, unknown
- injury_impact: none, low, medium, high, unknown
- joint_practice_effect: boosts_readiness, reduces_game_reps, neutral, unknown
- weather_effect: boosts_scoring, suppresses_scoring, neutral, indoor, unknown
- fact certainty: confirmed, reported, uncertain
- source_type: official, primary_reporter, established_media, odds_market, secondary
- status: ready, partial, insufficient

Do not call context ready unless every material claim has a real source URL. Market lines found on the web are a time-stamped secondary snapshot, not a replacement for the application's synced sportsbook feed.

Game packet:
{$json}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<int, string>  $providerCitationUrls
     * @return array<string, mixed>
     */
    private function normalize(array $decoded, array $providerCitationUrls): array
    {
        $requireProviderCitations = (bool) config('ai.features.nfl_game_context_research.require_provider_citations', true);
        $sources = collect((array) ($decoded['sources'] ?? []))
            ->filter(fn ($source): bool => is_array($source) && filter_var($source['url'] ?? null, FILTER_VALIDATE_URL) !== false)
            ->filter(fn (array $source): bool => ! $requireProviderCitations || in_array($source['url'], $providerCitationUrls, true))
            ->map(fn (array $source): array => [
                'url' => (string) $source['url'],
                'title' => trim((string) ($source['title'] ?? 'Source')),
                'publisher' => trim((string) ($source['publisher'] ?? 'Unknown')),
                'published_at' => $this->nullableString($source['published_at'] ?? null),
                'source_type' => $this->allowed($source['source_type'] ?? null, ['official', 'primary_reporter', 'established_media', 'odds_market', 'secondary'], 'secondary'),
                'provider_citation' => in_array($source['url'], $providerCitationUrls, true),
            ])
            ->unique('url')
            ->take(12)
            ->values()
            ->all();
        $sourceUrls = collect($sources)->pluck('url')->all();

        $facts = collect((array) ($decoded['facts'] ?? []))
            ->filter(fn ($fact): bool => is_array($fact) && trim((string) ($fact['claim'] ?? '')) !== '')
            ->map(function (array $fact) use ($sourceUrls): array {
                $urls = collect((array) ($fact['source_urls'] ?? []))
                    ->filter(fn ($url): bool => in_array($url, $sourceUrls, true))
                    ->unique()
                    ->take(4)
                    ->values()
                    ->all();

                return [
                    'category' => $this->normalizeCategory($fact['category'] ?? null),
                    'team_side' => $this->allowed($fact['team_side'] ?? null, ['home', 'away', 'both', 'game'], 'game'),
                    'claim' => trim((string) $fact['claim']),
                    'certainty' => $this->allowed($fact['certainty'] ?? null, ['confirmed', 'reported', 'uncertain'], 'uncertain'),
                    'source_urls' => $urls,
                ];
            })
            ->filter(fn (array $fact): bool => $fact['source_urls'] !== [])
            ->take(16)
            ->values()
            ->all();

        $status = $this->allowed($decoded['status'] ?? null, ['ready', 'partial', 'insufficient'], 'insufficient');
        if ($sources === [] || $facts === []) {
            $status = 'insufficient';
        }
        $riskFlags = collect($this->stringList($decoded['risk_flags'] ?? [], 10));
        if ($requireProviderCitations && $providerCitationUrls === []) {
            $riskFlags->push('provider_citations_missing');
        }
        if ($sources === [] || $facts === []) {
            $riskFlags->push('insufficient_sourced_context');
        }

        return [
            'status' => $status,
            'confidence' => max(0, min(100, (int) ($decoded['confidence'] ?? 0))),
            'summary' => trim((string) ($decoded['summary'] ?? 'No reliable current game context was found.')),
            'team_context' => [
                'home' => $this->normalizeTeamContext(data_get($decoded, 'team_context.home', [])),
                'away' => $this->normalizeTeamContext(data_get($decoded, 'team_context.away', [])),
            ],
            'situational_context' => [
                'joint_practice_effect' => $this->allowed(data_get($decoded, 'situational_context.joint_practice_effect'), ['boosts_readiness', 'reduces_game_reps', 'neutral', 'unknown'], 'unknown'),
                'weather_effect' => $this->allowed(data_get($decoded, 'situational_context.weather_effect'), ['boosts_scoring', 'suppresses_scoring', 'neutral', 'indoor', 'unknown'], 'unknown'),
                'schedule_notes' => $this->stringList(data_get($decoded, 'situational_context.schedule_notes', []), 5),
            ],
            'market_snapshot' => [
                'home_spread' => $this->nullableFloat(data_get($decoded, 'market_snapshot.home_spread')),
                'total' => $this->nullableFloat(data_get($decoded, 'market_snapshot.total')),
                'home_moneyline' => $this->nullableInt(data_get($decoded, 'market_snapshot.home_moneyline')),
                'away_moneyline' => $this->nullableInt(data_get($decoded, 'market_snapshot.away_moneyline')),
                'observed_at' => $this->nullableString(data_get($decoded, 'market_snapshot.observed_at')),
                'notes' => $this->stringList(data_get($decoded, 'market_snapshot.notes', []), 5),
            ],
            'facts' => $facts,
            'sources' => $sources,
            'risk_flags' => $riskFlags->unique()->take(10)->values()->all(),
            'provider_citation_urls' => $providerCitationUrls,
        ];
    }

    /** @param mixed $value @return array<string, mixed> */
    private function normalizeTeamContext(mixed $value): array
    {
        $value = is_array($value) ? $value : [];

        return [
            'starter_participation' => $this->allowed($value['starter_participation'] ?? null, ['full', 'extended', 'limited', 'none', 'unknown'], 'unknown'),
            'qb_rotation_quality' => $this->allowed($value['qb_rotation_quality'] ?? null, ['strong', 'average', 'weak', 'unknown'], 'unknown'),
            'coaching_intent' => $this->allowed($value['coaching_intent'] ?? null, ['aggressive', 'balanced', 'conservative', 'unknown'], 'unknown'),
            'injury_impact' => $this->allowed($value['injury_impact'] ?? null, ['none', 'low', 'medium', 'high', 'unknown'], 'unknown'),
            'notes' => $this->stringList($value['notes'] ?? [], 6),
        ];
    }

    /** @param array<int, string> $allowed */
    private function allowed(mixed $value, array $allowed, string $fallback): string
    {
        $normalized = Str::of((string) $value)->lower()->replace([' ', '-'], '_')->toString();

        return in_array($normalized, $allowed, true) ? $normalized : $fallback;
    }

    private function normalizeCategory(mixed $value): string
    {
        $category = Str::snake((string) $value);

        return match ($category) {
            'starter', 'starters', 'starter_plan', 'starter_plans', 'participation', 'starter_playing_time' => 'starter_participation',
            'quarterback', 'quarterback_rotation', 'quarterback_plan', 'qb_plan', 'qb_playing_time' => 'qb_rotation',
            'coach', 'coach_intent', 'game_intent' => 'coaching_intent',
            'injuries', 'availability' => 'injury',
            'joint_practices', 'practice' => 'joint_practice',
            default => $category !== '' ? $category : 'other',
        };
    }

    /** @return array<int, string> */
    private function stringList(mixed $value, int $limit): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function teamName(mixed $team): ?string
    {
        if (! $team) {
            return null;
        }

        return trim(((string) $team->location).' '.((string) $team->name)) ?: null;
    }
}
