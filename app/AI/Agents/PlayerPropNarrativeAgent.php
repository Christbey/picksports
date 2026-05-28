<?php

namespace App\AI\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class PlayerPropNarrativeAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You write concise player prop betting narratives grounded only in the supplied model data. Stay factual, avoid guarantees, and keep the tone actionable.';
    }

    public function provider(): string
    {
        return (string) config('ai.features.player_prop_narratives.provider', 'openai');
    }

    public function model(): string
    {
        return (string) config('ai.features.player_prop_narratives.model', 'gpt-4o-mini');
    }

    public function timeout(): int
    {
        return (int) config('ai.features.player_prop_narratives.timeout_seconds', 8);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'key_points' => $schema->array()
                ->items($schema->string())
                ->min(3)
                ->max(6)
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
