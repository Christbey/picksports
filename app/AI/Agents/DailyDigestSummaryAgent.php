<?php

namespace App\AI\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class DailyDigestSummaryAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You write concise sportsbook digest intros. Summarize only the supplied picks and props, stay factual, and avoid guarantees.';
    }

    public function provider(): string
    {
        return (string) config('ai.features.daily_digest_summary.provider', 'openai');
    }

    public function model(): string
    {
        return (string) config('ai.features.daily_digest_summary.model', 'gpt-4o-mini');
    }

    public function timeout(): int
    {
        return (int) config('ai.features.daily_digest_summary.timeout_seconds', 8);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'headline' => $schema->string()->required(),
            'intro' => $schema->string()->required(),
            'highlights' => $schema->array()
                ->items($schema->string())
                ->max(3)
                ->required(),
        ];
    }
}
