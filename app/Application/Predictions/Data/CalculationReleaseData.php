<?php

namespace App\Application\Predictions\Data;

use App\Models\CalculationRelease;

final readonly class CalculationReleaseData
{
    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $publicId,
        public string $sport,
        public string $phase,
        public string $calculatorName,
        public string $releaseType,
        public string $semanticVersion,
        public string $codeRevision,
        public string $configurationHash,
        public string $inputSchemaVersion,
        public array $configuration,
        public array $metadata = [],
    ) {}

    public static function fromModel(CalculationRelease $release): self
    {
        return new self(
            publicId: $release->public_id,
            sport: $release->sport,
            phase: $release->phase,
            calculatorName: $release->calculator_name,
            releaseType: $release->release_type,
            semanticVersion: $release->semantic_version,
            codeRevision: $release->code_revision,
            configurationHash: $release->configuration_hash,
            inputSchemaVersion: $release->input_schema_version,
            configuration: $release->configuration ?? [],
            metadata: $release->metadata ?? [],
        );
    }
}
