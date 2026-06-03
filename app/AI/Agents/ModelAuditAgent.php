<?php

namespace App\AI\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class ModelAuditAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You audit a sports prediction model output. Use only the supplied JSON. Treat calculated_model as deterministic model output and operational_context as authoritative. Your job is to assess whether the model signal, confidence, edge, and reason codes support the recommendation strength.';
    }

    public function provider(): string
    {
        return (string) config('ai.features.model_audit_review.provider', 'openai');
    }

    public function model(): string
    {
        return (string) config('ai.features.model_audit_review.model', 'gpt-4o-mini');
    }

    public function timeout(): int
    {
        return (int) config('ai.features.model_audit_review.timeout_seconds', 8);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'model_status' => $schema->string()->required(),
            'signal_score' => $schema->integer()->required(),
            'confidence_alignment' => $schema->string()->required(),
            'summary' => $schema->string()->required(),
            'supporting_factors' => $schema->array()
                ->items($schema->string())
                ->max(8)
                ->required(),
            'model_risk_flags' => $schema->array()
                ->items($schema->string())
                ->max(8)
                ->required(),
            'reason_codes' => $schema->array()
                ->items($schema->string())
                ->max(12)
                ->required(),
            'recommended_classification' => $schema->string()->required(),
        ];
    }
}
