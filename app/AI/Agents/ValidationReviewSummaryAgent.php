<?php

namespace App\AI\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class ValidationReviewSummaryAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You summarize validation findings for sports data operations. Stay factual, concise, and recommend only actions grounded in the supplied findings.';
    }

    public function provider(): string
    {
        return (string) config('ai.features.validation_review_summary.provider', 'openai');
    }

    public function model(): string
    {
        return (string) config('ai.features.validation_review_summary.model', 'gpt-4o-mini');
    }

    public function timeout(): int
    {
        return (int) config('ai.features.validation_review_summary.timeout_seconds', 8);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'headline' => $schema->string()->required(),
            'intro' => $schema->string()->required(),
            'highlights' => $schema->array()
                ->items($schema->string())
                ->max(4)
                ->required(),
            'recommended_actions' => $schema->array()
                ->items($schema->string())
                ->max(4)
                ->required(),
        ];
    }
}
