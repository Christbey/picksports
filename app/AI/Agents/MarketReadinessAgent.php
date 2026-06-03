<?php

namespace App\AI\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class MarketReadinessAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You audit betting market readiness for a sports prediction workflow. Use only the supplied JSON. Treat operational_context, market_context, odds timestamps, validation findings, and publishing guardrails as authoritative.';
    }

    public function provider(): string
    {
        return (string) config('ai.features.market_readiness_review.provider', 'openai');
    }

    public function model(): string
    {
        return (string) config('ai.features.market_readiness_review.model', 'gpt-4o-mini');
    }

    public function timeout(): int
    {
        return (int) config('ai.features.market_readiness_review.timeout_seconds', 8);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'market_status' => $schema->string()->required(),
            'readiness_score' => $schema->integer()->required(),
            'summary' => $schema->string()->required(),
            'available_markets' => $schema->array()
                ->items($schema->string())
                ->max(8)
                ->required(),
            'missing_markets' => $schema->array()
                ->items($schema->string())
                ->max(8)
                ->required(),
            'risk_flags' => $schema->array()
                ->items($schema->string())
                ->max(8)
                ->required(),
            'recommended_actions' => $schema->array()
                ->items($schema->string())
                ->max(8)
                ->required(),
            'publishable_recommendation' => $schema->string()->required(),
        ];
    }
}
