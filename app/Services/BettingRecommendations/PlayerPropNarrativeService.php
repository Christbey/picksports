<?php

namespace App\Services\BettingRecommendations;

use App\Services\AI\SportsAiContentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use JsonException;

class PlayerPropNarrativeService
{
    public function __construct(
        private readonly SportsAiContentService $sportsAiContentService,
    ) {}

    /**
     * @param  array<string, mixed>  $recommendation
     * @return array<string, mixed>
     */
    public function attachNarrative(array $recommendation, string $sport, bool $allowOpenAi = true): array
    {
        $prop = $recommendation['prop'] ?? null;
        if (! $prop instanceof Model) {
            $recommendation['narrative'] = null;

            return $recommendation;
        }

        $context = $this->buildContext($recommendation, $sport);
        $hash = $this->inputHash($context);
        $storedNarrative = is_array($prop->narrative_json ?? null) ? $prop->narrative_json : null;
        $storedHash = (string) ($prop->narrative_input_hash ?? '');

        if ($storedNarrative && $storedHash !== '' && hash_equals($storedHash, $hash)) {
            $recommendation['narrative'] = $storedNarrative;

            return $recommendation;
        }

        $startedAt = microtime(true);
        $narrative = $this->templateNarrative($context);

        if ($allowOpenAi && $this->shouldUseOpenAi()) {
            $openAiNarrative = $this->sportsAiContentService->generatePlayerPropNarrative(
                $this->buildPrompt($context),
                provider: 'openai',
                model: (string) config('ai.features.player_prop_narratives.model', 'gpt-4o-mini'),
            );

            if ($openAiNarrative !== null) {
                $narrative = $openAiNarrative;
            }
        }

        $recommendation['narrative'] = $narrative;
        $this->persistNarrative($prop, $narrative, $hash, (int) round((microtime(true) - $startedAt) * 1000));

        return $recommendation;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function inputHash(array $context): string
    {
        try {
            return hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return hash('sha256', serialize($context));
        }
    }

    /**
     * @param  array<string, mixed>  $recommendation
     * @return array<string, mixed>
     */
    private function buildContext(array $recommendation, string $sport): array
    {
        /** @var Model $prop */
        $prop = $recommendation['prop'];
        /** @var Model|null $game */
        $game = $recommendation['game'] ?? null;
        /** @var Model|null $player */
        $player = $recommendation['player'] ?? null;

        $homeTeam = $game?->homeTeam?->abbreviation ?? $game?->homeTeam?->name ?? 'Home';
        $awayTeam = $game?->awayTeam?->abbreviation ?? $game?->awayTeam?->name ?? 'Away';
        $playerName = $player?->full_name ?? $player?->display_name ?? $player?->name ?? ($prop->player_name ?? 'Player');
        $recommendationSide = (string) ($recommendation['recommendation'] ?? 'Lean');
        $modelOverProbability = (float) ($recommendation['model_over_probability'] ?? 50.0);
        $marketOverProbability = (float) ($recommendation['market_over_probability'] ?? 50.0);
        $modelSideProbability = $recommendationSide === 'Under'
            ? round(100 - $modelOverProbability, 1)
            : round($modelOverProbability, 1);
        $marketSideProbability = $recommendationSide === 'Under'
            ? round(100 - $marketOverProbability, 1)
            : round($marketOverProbability, 1);

        return [
            'sport' => strtoupper($sport),
            'player_name' => $playerName,
            'market' => (string) ($recommendation['market'] ?? str_replace('_', ' ', (string) $prop->market)),
            'line' => round((float) ($recommendation['line'] ?? $prop->line ?? 0), 1),
            'recommendation' => $recommendationSide,
            'odds' => $recommendation['odds'] ?? null,
            'confidence' => round((float) ($recommendation['confidence'] ?? 0), 1),
            'edge' => round((float) ($recommendation['edge'] ?? 0), 1),
            'edge_probability' => round((float) ($recommendation['edge_probability'] ?? 0), 1),
            'model_side_probability' => $modelSideProbability,
            'market_side_probability' => $marketSideProbability,
            'season_avg' => $recommendation['season_avg'] ?? null,
            'recent_avg' => $recommendation['recent_avg'] ?? null,
            'last5_avg' => $recommendation['last5_avg'] ?? null,
            'vs_opponent_avg' => $recommendation['vs_opponent_avg'] ?? null,
            'home_away_avg' => $recommendation['home_away_avg'] ?? null,
            'data_quality_score' => $recommendation['data_quality_score'] ?? null,
            'match_quality_score' => $recommendation['match_quality_score'] ?? null,
            'context_factor' => data_get($recommendation, 'context.combined_factor'),
            'game' => sprintf('%s @ %s', $awayTeam, $homeTeam),
            'reasoning' => array_values(array_slice(array_map('strval', (array) ($recommendation['reasoning'] ?? [])), 0, 4)),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *   summary:string,
     *   key_points:array<int,string>,
     *   risk_note:string,
     *   generated_by:string,
     *   betting_plan:array{bet_pick:string,reasoning:string},
     *   social_caption:string|null
     * }
     */
    private function templateNarrative(array $context): array
    {
        $oddsText = is_numeric($context['odds'])
            ? sprintf('%s%d', ((int) $context['odds']) > 0 ? '+' : '', (int) $context['odds'])
            : 'current odds';
        $propLabel = sprintf(
            '%s %s %.1f %s',
            $context['player_name'],
            $context['recommendation'],
            $context['line'],
            $context['market']
        );

        $keyPoints = [
            sprintf(
                'Model %s probability is %.1f%% vs market implied %.1f%%.',
                strtolower((string) $context['recommendation']),
                (float) $context['model_side_probability'],
                (float) $context['market_side_probability']
            ),
            sprintf(
                'Projection edge sits at %.1f with confidence %.1f.',
                (float) $context['edge'],
                (float) $context['confidence']
            ),
            sprintf(
                'Baseline splits: season %.1f, recent %.1f, last five %.1f.',
                (float) ($context['season_avg'] ?? 0),
                (float) ($context['recent_avg'] ?? 0),
                (float) ($context['last5_avg'] ?? 0)
            ),
        ];

        if ($context['vs_opponent_avg'] !== null || $context['home_away_avg'] !== null) {
            $keyPoints[] = sprintf(
                'Context averages: vs opponent %s, venue split %s.',
                $context['vs_opponent_avg'] !== null ? number_format((float) $context['vs_opponent_avg'], 1) : 'N/A',
                $context['home_away_avg'] !== null ? number_format((float) $context['home_away_avg'], 1) : 'N/A'
            );
        }

        return [
            'summary' => sprintf(
                '%s prop lean: %s at %s. Model edge is %.1f with %.1f%% confidence for %s.',
                $context['sport'],
                $propLabel,
                $oddsText,
                (float) $context['edge'],
                (float) $context['confidence'],
                $context['game']
            ),
            'key_points' => $keyPoints,
            'risk_note' => sprintf(
                'Risk note: data quality %s and match quality %s still leave room for lineup, usage, or game-script volatility.',
                $context['data_quality_score'] ?? 'N/A',
                $context['match_quality_score'] ?? 'N/A'
            ),
            'generated_by' => 'template-player-prop-v1',
            'betting_plan' => [
                'bet_pick' => sprintf('Bet %s %.1f %s.', $context['recommendation'], $context['line'], $context['market']),
                'reasoning' => sprintf(
                    '%s carries the stronger model probability at %.1f%% versus the market at %.1f%%.',
                    $context['recommendation'],
                    (float) $context['model_side_probability'],
                    (float) $context['market_side_probability']
                ),
            ],
            'social_caption' => sprintf(
                '%s prop lean: %s %.1f %s.',
                $context['sport'],
                $context['recommendation'],
                $context['line'],
                $context['market']
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function buildPrompt(array $context): string
    {
        $reasoning = collect($context['reasoning'] ?? [])
            ->filter(fn ($line) => is_string($line) && trim($line) !== '')
            ->map(fn (string $line) => '- '.$line)
            ->implode("\n");

        return <<<PROMPT
You are writing a short player prop betting narrative for a sports picks app.

Return JSON only using the provided schema. Use only the supplied data. Do not invent injuries, usage changes, or sportsbook context.

Sport: {$context['sport']}
Game: {$context['game']}
Player: {$context['player_name']}
Market: {$context['market']}
Recommendation: {$context['recommendation']}
Line: {$context['line']}
Odds: {$context['odds']}
Confidence: {$context['confidence']}
Projection edge: {$context['edge']}
Edge probability: {$context['edge_probability']}
Model side probability: {$context['model_side_probability']}
Market side probability: {$context['market_side_probability']}
Season average: {$context['season_avg']}
Recent average: {$context['recent_avg']}
Last 5 average: {$context['last5_avg']}
Vs opponent average: {$context['vs_opponent_avg']}
Home/away average: {$context['home_away_avg']}
Data quality score: {$context['data_quality_score']}
Match quality score: {$context['match_quality_score']}
Context factor: {$context['context_factor']}
Signals:
{$reasoning}
PROMPT;
    }

    private function shouldUseOpenAi(): bool
    {
        return (string) config('ai.features.player_prop_narratives.provider', 'template') === 'openai'
            && trim((string) config('services.openai.api_key', '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $narrative
     */
    private function persistNarrative(Model $prop, array $narrative, string $hash, int $latencyMs): void
    {
        if (! $this->supportsPersistence($prop)) {
            return;
        }

        $generatedBy = (string) ($narrative['generated_by'] ?? '');
        $provider = null;
        $model = null;

        if ($generatedBy !== '') {
            $parts = explode(':', $generatedBy, 2);
            $provider = $parts[0] ?? null;
            $model = $parts[1] ?? $generatedBy;
        }

        $prop->forceFill([
            'narrative_json' => $narrative,
            'narrative_provider' => $provider,
            'narrative_model' => $model,
            'narrative_input_hash' => $hash,
            'narrative_latency_ms' => $latencyMs,
            'narrative_generated_at' => now(),
        ])->saveQuietly();
    }

    private function supportsPersistence(Model $prop): bool
    {
        return Schema::hasColumns($prop->getTable(), [
            'narrative_json',
            'narrative_provider',
            'narrative_model',
            'narrative_input_hash',
            'narrative_latency_ms',
            'narrative_generated_at',
        ]);
    }
}
