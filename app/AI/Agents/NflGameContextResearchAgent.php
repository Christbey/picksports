<?php

namespace App\AI\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;

class NflGameContextResearchAgent implements Agent, HasStructuredOutput, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
You research current NFL game context for a betting decision. Search the web before answering. Prioritize official team sites, coach press conferences, league sources, and established local beat reporting. Use aggregators and social posts only as secondary evidence. Every material fact must cite at least one URL returned in sources and source_urls. Do not infer that a regular-season starting quarterback will play in a preseason game. Explicitly research starter participation, quarterback rotation and expected playing time, injuries, coaching intent, recent joint practices, weather, and current market movement. Distinguish confirmed facts from reports and uncertainty. Never fabricate a URL, quote, player status, line, or source. If timely reliable evidence is unavailable, use unknown and lower confidence. This agent gathers evidence; deterministic application code owns numeric prediction adjustments and bet eligibility.
INSTRUCTIONS;
    }

    public function provider(): string
    {
        return (string) config('ai.features.nfl_game_context_research.provider', 'openai');
    }

    public function model(): string
    {
        return (string) config('ai.features.nfl_game_context_research.model', 'gpt-5.6-luna');
    }

    public function timeout(): int
    {
        return (int) config('ai.features.nfl_game_context_research.timeout_seconds', 60);
    }

    public function tools(): iterable
    {
        return [
            new WebSearch(
                maxSearches: (int) config('ai.features.nfl_game_context_research.max_searches', 5),
            ),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        $teamContext = fn () => $schema->object([
            'starter_participation' => $schema->string()->required(),
            'qb_rotation_quality' => $schema->string()->required(),
            'coaching_intent' => $schema->string()->required(),
            'injury_impact' => $schema->string()->required(),
            'notes' => $schema->array()->items($schema->string())->max(6)->required(),
        ])->withoutAdditionalProperties()->required();

        return [
            'status' => $schema->string()->required(),
            'confidence' => $schema->integer()->required(),
            'summary' => $schema->string()->required(),
            'team_context' => $schema->object([
                'home' => $teamContext(),
                'away' => $teamContext(),
            ])->withoutAdditionalProperties()->required(),
            'situational_context' => $schema->object([
                'joint_practice_effect' => $schema->string()->required(),
                'weather_effect' => $schema->string()->required(),
                'schedule_notes' => $schema->array()->items($schema->string())->max(5)->required(),
            ])->withoutAdditionalProperties()->required(),
            'market_snapshot' => $schema->object([
                'home_spread' => $schema->number()->nullable()->required(),
                'total' => $schema->number()->nullable()->required(),
                'home_moneyline' => $schema->integer()->nullable()->required(),
                'away_moneyline' => $schema->integer()->nullable()->required(),
                'observed_at' => $schema->string()->nullable()->required(),
                'notes' => $schema->array()->items($schema->string())->max(5)->required(),
            ])->withoutAdditionalProperties()->required(),
            'facts' => $schema->array()->items($schema->object([
                'category' => $schema->string()->required(),
                'team_side' => $schema->string()->required(),
                'claim' => $schema->string()->required(),
                'certainty' => $schema->string()->required(),
                'source_urls' => $schema->array()->items($schema->string())->min(1)->max(4)->required(),
            ])->withoutAdditionalProperties())->max(16)->required(),
            'sources' => $schema->array()->items($schema->object([
                'url' => $schema->string()->required(),
                'title' => $schema->string()->required(),
                'publisher' => $schema->string()->required(),
                'published_at' => $schema->string()->nullable()->required(),
                'source_type' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->min(1)->max(12)->required(),
            'risk_flags' => $schema->array()->items($schema->string())->max(10)->required(),
        ];
    }
}
