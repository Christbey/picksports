<?php

namespace App\Services\Predictions\Review;

use App\Application\Sports\ReadModels\PredictionReviewInput;
use App\Services\Predictions\CanonicalPayloadHasher;

class ReviewFingerprint
{
    public function __construct(private readonly CanonicalPayloadHasher $hasher) {}

    /** @return array<string, mixed> */
    public function executionSpec(): array
    {
        return ['provider' => config('prediction_review.provider'), 'model' => config('prediction_review.model'),
            'prompt_version' => config('prediction_review.prompt_version'), 'schema_version' => 'prediction_review_input_v1',
            'policy_version' => config('prediction_review.policy_version'), 'stages' => ['interpretation']];
    }

    /** @return array{input_hash:string,execution_hash:string,evidence_hash:string,fingerprint:string} */
    public function forInput(PredictionReviewInput $input, array $evidence = []): array
    {
        $packet = $input->toArray();
        // SQL drivers and hydrated versus freshly created sport games may expose
        // equivalent season types as strings or integers. They are one identity.
        if (isset($packet['identity']['season_type'])) {
            $packet['identity']['season_type'] = (string) $packet['identity']['season_type'];
        }
        $inputHash = $this->hasher->hash($packet);
        $executionHash = $this->hasher->hash($this->executionSpec());
        $evidenceHash = $this->hasher->hash($this->semanticEvidence($evidence));

        return ['input_hash' => $inputHash, 'execution_hash' => $executionHash, 'evidence_hash' => $evidenceHash,
            'fingerprint' => $this->hasher->hash([$inputHash, $executionHash, $evidenceHash])];
    }

    /** @return array<string, mixed> */
    public function semanticEvidence(array $evidence): array
    {
        foreach ($evidence as $key => $value) {
            if (in_array($key, ['assessed_at', 'queried_at', 'generated_at', 'checked_at', 'last_checked_at', 'age_minutes'], true)) {
                unset($evidence[$key]);
            } elseif (is_array($value)) {
                $evidence[$key] = $this->semanticEvidence($value);
            }
        }

        return $evidence;
    }
}
