<?php

namespace App\Services\NFL;

use App\AI\Agents\NflGameContextResearchAgent;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\ObjectSchema;
use RuntimeException;
use Throwable;

class OpenAiNflGameContextResearchClient
{
    /**
     * @return array{
     *     structured:array<string, mixed>,
     *     citation_urls:array<int, string>,
     *     provider:string,
     *     model:string,
     *     response_id:string|null,
     *     usage:array{input:int,output:int,cached_input:int,reasoning:int},
     *     web_search_calls:int
     * }
     */
    public function research(string $prompt, string $model): array
    {
        $apiKey = trim((string) config('ai.providers.openai.key'));
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI is not configured for NFL game-context research.');
        }

        $agent = app(NflGameContextResearchAgent::class);
        $schema = (new ObjectSchema(
            $agent->schema(new JsonSchemaTypeFactory),
            'nfl_game_context_research',
        ))->toArray();
        $maxSearches = max(1, (int) config('ai.features.nfl_game_context_research.max_searches', 5));
        $payload = [
            'model' => $model,
            'instructions' => $agent->instructions(),
            'input' => $prompt,
            'tools' => [[
                'type' => 'web_search',
            ]],
            'tool_choice' => 'auto',
            'max_tool_calls' => $maxSearches,
            'max_output_tokens' => max(1_000, (int) config('ai.features.nfl_game_context_research.max_output_tokens', 6_000)),
            'include' => ['web_search_call.action.sources'],
            'store' => false,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'nfl_game_context_research',
                    'schema' => $schema,
                    'strict' => true,
                ],
            ],
        ];

        if ($this->supportsReasoningEffort($model)) {
            $payload['reasoning'] = [
                'effort' => (string) config('ai.features.nfl_game_context_research.reasoning_effort', 'none'),
            ];
        }

        $baseUrl = rtrim((string) config('ai.providers.openai.url', 'https://api.openai.com/v1'), '/');
        $response = Http::baseUrl($baseUrl)
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(max(1, (int) config('ai.features.nfl_game_context_research.timeout_seconds', 60)))
            ->post('responses', $payload);

        if ($response->failed()) {
            $message = trim((string) data_get($response->json(), 'error.message', $response->body()));
            $errorCode = trim((string) data_get($response->json(), 'error.code', ''));

            throw new RuntimeException(sprintf(
                'OpenAI Responses API failed with status %d%s: %s',
                $response->status(),
                $errorCode === '' ? '' : ' ['.$errorCode.']',
                $message !== '' ? $message : 'Unknown provider error.',
            ));
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('OpenAI Responses API returned an invalid response body.');
        }

        $outputText = $this->outputText($body);
        if ($outputText === null) {
            throw new RuntimeException('OpenAI Responses API returned no structured output text.');
        }

        try {
            $structured = json_decode($outputText, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('OpenAI Responses API returned invalid structured JSON.', previous: $exception);
        }

        if (! is_array($structured)) {
            throw new RuntimeException('OpenAI Responses API returned an unexpected structured payload.');
        }

        return [
            'structured' => $structured,
            'citation_urls' => $this->citationUrls($body),
            'provider' => 'openai',
            'model' => (string) ($body['model'] ?? $model),
            'response_id' => isset($body['id']) ? (string) $body['id'] : null,
            'usage' => [
                'input' => max(0, (int) data_get($body, 'usage.input_tokens', 0)),
                'output' => max(0, (int) data_get($body, 'usage.output_tokens', 0)),
                'cached_input' => max(0, (int) data_get($body, 'usage.input_tokens_details.cached_tokens', 0)),
                'reasoning' => max(0, (int) data_get($body, 'usage.output_tokens_details.reasoning_tokens', 0)),
            ],
            'web_search_calls' => collect((array) ($body['output'] ?? []))
                ->where('type', 'web_search_call')
                ->count(),
        ];
    }

    /** @param array<string, mixed> $body */
    private function outputText(array $body): ?string
    {
        $topLevel = trim((string) ($body['output_text'] ?? ''));
        if ($topLevel !== '') {
            return $topLevel;
        }

        foreach ((array) ($body['output'] ?? []) as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ((array) ($item['content'] ?? []) as $content) {
                if (! is_array($content) || ($content['type'] ?? null) !== 'output_text') {
                    continue;
                }

                $text = trim((string) ($content['text'] ?? ''));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<int, string>
     */
    private function citationUrls(array $body): array
    {
        $urls = collect();

        foreach ((array) ($body['output'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['type'] ?? null) === 'web_search_call') {
                foreach ((array) data_get($item, 'action.sources', []) as $source) {
                    if (is_array($source)) {
                        $urls->push($source['url'] ?? null);
                    }
                }
            }

            foreach ((array) ($item['content'] ?? []) as $content) {
                if (! is_array($content)) {
                    continue;
                }

                foreach ((array) ($content['annotations'] ?? []) as $annotation) {
                    if (is_array($annotation)) {
                        $urls->push($annotation['url'] ?? data_get($annotation, 'url_citation.url'));
                    }
                }
            }
        }

        return $urls
            ->filter(fn ($url): bool => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    private function supportsReasoningEffort(string $model): bool
    {
        return str_starts_with($model, 'gpt-5') || preg_match('/^o\d/', $model) === 1;
    }
}
