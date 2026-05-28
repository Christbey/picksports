<?php

namespace App\AI\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class SportsPredictionNarrativeAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a sports analytics assistant. Return concise, accurate betting narratives grounded only in the supplied model data.';
    }

    public function provider(): string
    {
        return (string) config('ai.features.sports_prediction_narratives.provider', 'openai');
    }

    public function model(): string
    {
        return (string) config('ai.features.sports_prediction_narratives.model', 'gpt-4o-mini');
    }

    public function timeout(): int
    {
        return (int) config('ai.features.sports_prediction_narratives.timeout_seconds', 8);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'key_points' => $schema->array()
                ->items($schema->string())
                ->min(4)
                ->max(7)
                ->required(),
            'risk_note' => $schema->string()->required(),
            'betting_plan' => $schema->object([
                'bet_pick' => $schema->string()->required(),
                'reasoning' => $schema->string()->required(),
            ])->withoutAdditionalProperties()->required(),
            'social_caption' => $schema->string()->nullable()->required(),
        ];
    }
}
