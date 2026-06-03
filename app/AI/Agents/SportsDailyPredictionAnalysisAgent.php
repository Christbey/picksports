<?php

namespace App\AI\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class SportsDailyPredictionAnalysisAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a disciplined sports betting analyst. Use only the supplied JSON. Treat operational_context as authoritative for data freshness, validation findings, pipeline order, and publishing guardrails. If publication_guardrails.status is blocked, do not classify the play as an official bet. Separate calculated model edge from analysis confidence. Do not invent injuries, odds, weather, players, or trends.';
    }

    public function provider(): string
    {
        return (string) config('ai.features.daily_prediction_analysis.provider', 'openai');
    }

    public function model(): string
    {
        return (string) config('ai.features.daily_prediction_analysis.model', 'gpt-4o-mini');
    }

    public function timeout(): int
    {
        return (int) config('ai.features.daily_prediction_analysis.timeout_seconds', 12);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'recommendation' => $schema->string()->required(),
            'bet_classification' => $schema->string()->required(),
            'ai_confidence' => $schema->integer()->required(),
            'analysis_confidence' => $schema->integer()->required(),
            'summary' => $schema->string()->required(),
            'key_factors' => $schema->array()
                ->items($schema->string())
                ->min(3)
                ->max(8)
                ->required(),
            'risk_flags' => $schema->array()
                ->items($schema->string())
                ->max(8)
                ->required(),
            'reason_codes' => $schema->array()
                ->items($schema->string())
                ->min(2)
                ->max(12)
                ->required(),
            'market_notes' => $schema->object([
                'moneyline' => $schema->string()->nullable()->required(),
                'spread' => $schema->string()->nullable()->required(),
                'total' => $schema->string()->nullable()->required(),
                'props' => $schema->string()->nullable()->required(),
            ])->required(),
        ];
    }
}
