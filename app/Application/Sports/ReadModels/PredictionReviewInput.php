<?php

namespace App\Application\Sports\ReadModels;

/** Internal, read-only input contract. It is not a public API resource or approval. */
final readonly class PredictionReviewInput
{
    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $projection
     * @param  array<string, mixed>|null  $recommendationContext
     * @param  array<string, mixed>  $decisionContract
     */
    public function __construct(
        public array $identity,
        public array $projection,
        public ?array $recommendationContext,
        public array $decisionContract,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 'prediction_review_input_v1',
            'identity' => $this->identity,
            'primary_projection' => $this->projection,
            'recommendation_context' => $this->recommendationContext,
            'decision_contract' => $this->decisionContract,
        ];
    }
}
