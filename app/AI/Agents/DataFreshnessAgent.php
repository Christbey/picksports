<?php

namespace App\AI\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class DataFreshnessAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You audit sports data freshness for an operational betting system. Use only the supplied JSON. Treat validation findings and operational_context as authoritative. Do not invent syncs, timestamps, injuries, odds, weather, or games.';
    }

    public function provider(): string
    {
        return (string) config('ai.features.data_freshness_review.provider', 'openai');
    }

    public function model(): string
    {
        return (string) config('ai.features.data_freshness_review.model', 'gpt-4o-mini');
    }

    public function timeout(): int
    {
        return (int) config('ai.features.data_freshness_review.timeout_seconds', 8);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'freshness_status' => $schema->string()->required(),
            'trust_score' => $schema->integer()->required(),
            'latest_data_fresh_at' => $schema->string()->nullable()->required(),
            'summary' => $schema->string()->required(),
            'stale_inputs' => $schema->array()
                ->items($schema->string())
                ->max(8)
                ->required(),
            'missing_inputs' => $schema->array()
                ->items($schema->string())
                ->max(8)
                ->required(),
            'blocked_outputs' => $schema->array()
                ->items($schema->string())
                ->max(8)
                ->required(),
            'recommended_actions' => $schema->array()
                ->items($schema->string())
                ->max(8)
                ->required(),
        ];
    }
}
