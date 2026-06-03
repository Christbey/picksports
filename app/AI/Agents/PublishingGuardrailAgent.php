<?php

namespace App\AI\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class PublishingGuardrailAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a publishing guardrail for sports betting analysis. Use only the supplied JSON. Treat operational_context, data freshness, market readiness, and validation findings as authoritative. Your job is to decide whether the recommendation can be published as-is, downgraded, held, or blocked.';
    }

    public function provider(): string
    {
        return (string) config('ai.features.publishing_guardrail_review.provider', 'openai');
    }

    public function model(): string
    {
        return (string) config('ai.features.publishing_guardrail_review.model', 'gpt-4o-mini');
    }

    public function timeout(): int
    {
        return (int) config('ai.features.publishing_guardrail_review.timeout_seconds', 8);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'decision' => $schema->string()->required(),
            'publishable_classification' => $schema->string()->required(),
            'confidence' => $schema->integer()->required(),
            'summary' => $schema->string()->required(),
            'reasons' => $schema->array()
                ->items($schema->string())
                ->min(1)
                ->max(8)
                ->required(),
            'blocked_outputs' => $schema->array()
                ->items($schema->string())
                ->max(8)
                ->required(),
            'required_actions' => $schema->array()
                ->items($schema->string())
                ->max(8)
                ->required(),
        ];
    }
}
